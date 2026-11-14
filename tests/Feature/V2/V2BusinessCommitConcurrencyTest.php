<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\BusinessCommitFixture;
use Tests\TestCase;
use Throwable;

/** Exercises the application example against real row locks, not scheduler ordering. */
final class V2BusinessCommitConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Separate processes and SQL row-lock observation are required.');
        }
        self::stopWorkers();
        Queue::fake();
        class_exists(BusinessCommitFixture::class);
        $schema = require __DIR__ . '/../../../examples/business-state-machines/schema.php';
        $schema(DB::connection()->getSchemaBuilder());
        BusinessCommitFixture::seed(BusinessCommitFixture::context()->execution());
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            foreach (['publications', 'decisions', 'subjects'] as $suffix) {
                DB::connection()->getSchemaBuilder()->dropIfExists('example_machine_' . $suffix);
            }
        }
        parent::tearDown();
    }

    public static function operations(): array
    {
        return [
            'cancellation wins' => ['cancel', 'commit', ['cancellation_requested', 'cancellation_requested'], false],
            'completion wins' => ['commit', 'cancel', ['committed', 'conflict'], true],
            'revocation wins' => ['revoke', 'commit', ['revoked', 'refused'], false],
            'completion precedes revocation' => ['commit', 'revoke', ['committed', 'revoked'], true],
            'competing completion' => ['commit', 'competing', ['committed', 'conflict'], true],
            'duplicate completion' => ['commit', 'commit', ['committed', 'committed'], true],
        ];
    }

    #[DataProvider('operations')]
    public function testBusinessDecisionsShareTheAuthoritativeSubjectFence(
        string $winner,
        string $loser,
        array $expected,
        bool $committed,
    ): void {
        $this->assertSame($expected, $this->race($winner, $loser));
        $subject = DB::table('example_machine_subjects')->first();
        $this->assertSame($committed ? 'ready' : 'received', $subject->state);
        $this->assertSame($committed ? 2 : 1, (int) $subject->revision);
        $this->assertSame($committed ? 1 : 0, DB::table('example_machine_publications')->count());
        $this->assertSame(
            (int) in_array('cancel', [$winner, $loser], true) && ! $committed,
            (bool) $subject->cancellation_requested,
        );
        if (in_array('revoke', [$winner, $loser], true)) {
            $this->assertSame('grant-v2', $subject->authority_version);
        }
    }

    private function operate(string $operation): string
    {
        if ($operation === 'revoke') {
            DB::transaction(static function (): void {
                $subject = DB::table('example_machine_subjects')->lockForUpdate()->first();
                DB::table('example_machine_subjects')->where('id', $subject->id)->update([
                    'authority_version' => 'grant-v2',
                ]);
            });
            return 'revoked';
        }
        $context = BusinessCommitFixture::context($operation === 'competing' ? 'transition-2' : 'transition-1');
        try {
            $store = BusinessCommitFixture::store();
            $result = $operation === 'cancel'
                ? $store->requestCancellation($context->execution(), 'cancel-1', 'alice')
                : $store->commit($context, 'alice');
            return $result->status->value;
        } catch (LogicException $error) {
            if ($error->getMessage() !== 'The original subject authority is not current.') {
                throw $error;
            }
            return 'refused';
        }
    }

    private function race(string $winner, string $loser): array
    {
        $root = sys_get_temp_dir() . '/dw-business-commit-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $driver = DB::connection()->getDriverName();
        DB::purge();
        $children = [];
        try {
            foreach ([$winner, $loser] as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the business commit race.');
                }
                if ($pid === 0) {
                    try {
                        DB::reconnect();
                        $backend = $driver === 'pgsql'
                            ? DB::selectOne('SELECT pg_backend_pid() AS id')->id
                            : DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                        if ($driver === 'pgsql') {
                            DB::statement("SET lock_timeout = '15s'");
                        } else {
                            DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
                        }
                        file_put_contents($root . '/backend-' . $index, (string) $backend);
                        if ($index === 0) {
                            $held = false;
                            DB::listen(static function (QueryExecuted $query) use ($root, &$held): void {
                                if (! $held && str_starts_with(strtolower($query->sql), 'update')
                                    && str_contains($query->sql, 'example_machine_subjects')) {
                                    if ($query->connection->transactionLevel() === 0) {
                                        throw new RuntimeException('The winning mutation was not transactional.');
                                    }
                                    $held = true;
                                    touch($root . '/held');
                                    self::waitFile($root . '/release');
                                }
                            });
                        } else {
                            self::waitFile($root . '/held');
                        }
                        file_put_contents($root . '/' . $index . '.json', json_encode([
                            'result' => $this->operate($operation),
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
            self::waitFile($root . '/held');
            self::waitFile($root . '/backend-1');
            $backend = (int) file_get_contents($root . '/backend-1');
            DB::reconnect();
            $deadline = microtime(true) + 5;
            $waiting = false;
            while (! $waiting && microtime(true) < $deadline) {
                $rows = $driver === 'pgsql'
                    ? DB::select('SELECT pid, query, wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend])
                    : DB::select('SHOW FULL PROCESSLIST');
                foreach ($rows as $row) {
                    $id = (int) ($driver === 'pgsql' ? $row->pid : $row->Id);
                    $sql = strtolower((string) ($driver === 'pgsql' ? $row->query : $row->Info));
                    $waiting = $id === $backend && str_starts_with($sql, 'select')
                        && str_contains($sql, 'example_machine_subjects') && str_contains($sql, 'for update')
                        && ($driver !== 'pgsql' || $row->wait_event_type === 'Lock');
                    if ($waiting) {
                        break;
                    }
                }
                if (! $waiting) {
                    usleep(10_000);
                }
            }
            $this->assertTrue($waiting, 'The competing business subject row lock was not observed.');
            touch($root . '/release');
            $results = [];
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $payload = json_decode(
                    file_get_contents($root . '/' . $index . '.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
                $this->assertSame(0, pcntl_wexitstatus($status), $payload['error'] ?? 'Business contender failed.');
                $results[] = $payload['result'];
            }
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
            DB::reconnect();
        }
    }

    private static function waitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The business commit barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
