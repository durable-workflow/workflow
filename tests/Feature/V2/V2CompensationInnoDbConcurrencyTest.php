<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ChildScopes;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

final class V2CompensationInnoDbConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Separate processes and an InnoDB backend are required.');
        }
        self::stopWorkers();
        Queue::fake();
    }

    public static function sharedRows(): array
    {
        return [
            'namespace graph' => ['graph'],
            'scope adoption' => ['scope'],
        ];
    }

    #[DataProvider('sharedRows')]
    public function testConcurrentAdoptersDoNotUpgradeSharedDuplicateKeyLocks(string $kind): void
    {
        if ($kind === 'graph') {
            (new ChildScopes())->register($this->identity('seed'), 'seed-run', 'child', $this->identity('seed-child'));
            $operation = fn (int $index): string => (new ChildScopes())->register(
                $this->identity('parent-' . $index),
                'run-' . $index,
                'child',
                $this->identity('child-' . $index),
            );
            $table = 'workflow_compensation_graphs';
        } else {
            $scope = $this->identity('shared');
            (new ScopeRepository())->open($scope, 'original-run');
            $operation = static function () use ($scope): string {
                (new ScopeRepository())->open($scope, 'original-run');
                return $scope->id();
            };
            $table = 'workflow_compensation_scopes';
        }

        $results = $this->raceAtExclusiveLookup($table, $operation);
        $this->assertCount(2, $results);
        if ($kind === 'graph') {
            $this->assertNotSame($results[0], $results[1]);
            $this->assertSame(1, DB::table($table)->count());
            $this->assertSame(3, DB::table('workflow_compensation_children')->count());
        } else {
            $this->assertSame($results[0], $results[1]);
            $this->assertSame(1, DB::table($table)->count());
            $this->assertSame(1, DB::table('workflow_compensation_journal')->where('kind', 'opened')->count());
        }
    }

    /**
     * Both callers reach the first exclusive lookup before either executes it.
     * The predecessor has already acquired duplicate-key shared locks here;
     * the corrected path reaches this barrier before any INSERT IGNORE.
     *
     * @return list<string>
     */
    private function raceAtExclusiveLookup(string $table, Closure $operation): array
    {
        $root = sys_get_temp_dir() . '/dw-innodb-scope-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        DB::purge();
        $children = [];
        $results = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the scope contention control.');
                }
                if ($pid === 0) {
                    try {
                        DB::reconnect();
                        DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
                        $waiting = false;
                        DB::connection()->beforeExecuting(static function (string $sql) use (
                            $table,
                            $root,
                            $index,
                            &$waiting,
                        ): void {
                            $sql = strtolower($sql);
                            if (! $waiting && str_starts_with($sql, 'select')
                                && str_contains($sql, $table) && str_contains($sql, 'for update')) {
                                $waiting = true;
                                touch($root . '/ready-' . $index);
                                self::waitFile($root . '/release');
                            }
                        });
                        DB::beginTransaction();
                        $result = $operation($index);
                        DB::commit();
                        file_put_contents($root . '/' . $index . '.json', json_encode([
                            'result' => $result,
                        ], JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(0);
                    } catch (Throwable $error) {
                        file_put_contents($root . '/' . $index . '.json', json_encode([
                            'error' => $error::class . ': ' . $error->getMessage(),
                        ], JSON_THROW_ON_ERROR));
                        DB::disconnect();
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            self::waitFile($root . '/ready-0');
            self::waitFile($root . '/ready-1');
            touch($root . '/release');
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $payload = json_decode(
                    file_get_contents($root . '/' . $index . '.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
                $this->assertSame(0, pcntl_wexitstatus($status), $payload['error'] ?? 'Scope caller failed.');
                $results[] = $payload['result'];
            }
            DB::reconnect();
            return $results;
        } finally {
            touch($root . '/release');
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach (glob($root . '/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    private function identity(string $key): ScopeIdentity
    {
        return new ScopeIdentity('shop', 'client-a', $key, '1');
    }

    private static function waitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Scope contention barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
