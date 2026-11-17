<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Compensation\Admission\RootAuthorization;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservation;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class V2RootReservationConcurrencyTest extends TestCase
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
    }

    public static function winners(): array
    {
        return [
            'closure wins' => ['close'],
            'admission wins' => ['admit'],
        ];
    }

    #[DataProvider('winners')]
    public function testOriginalRootAdmissionAndClosureShareOneNativeFence(string $winner): void
    {
        $identity = RootIdentity::forWorkflow(
            new ScopeIdentity('race-app', 'race-tenant', 'order', 'original-generation'),
            TestGreetingWorkflow::class,
            ['Taylor'],
        );
        $reservation = $this->store()
            ->reserve($identity, 'owner');
        $results = $this->race($reservation, $winner);
        $this->assertSame(
            $winner === 'close' ? ['closed', 'closed'] : ['admitted', 'admitted'],
            array_column($results, 'kind')
        );
        $this->assertSame($results[0], $results[1]);
        $this->assertSame($winner === 'admit' ? 1 : 0, WorkflowRun::query()->count());
        $this->assertSame($winner === 'admit' ? 1 : 0, WorkflowTask::query()->count());
        $scope = DB::table(ScopeStorage::SCOPES)->where('id', $identity->scope->id())->sole();
        $this->assertSame($winner === 'admit' ? 'open' : 'root_never_admitted', $scope->status);
        $this->assertSame($reservation->originalRunId, $scope->original_run_id);
        $this->assertSame($results[0], $this->operate('close', $reservation));
    }

    public function testBackendRetainsCanonicalNumericAndUnicodeAdmissionMetadata(): void
    {
        $scope = new ScopeIdentity('race-app', 'race-tenant', 'order', 'original-generation');
        $memo = [
            'amount' => 1.0,
            'left' => str_repeat('🛒', 1500),
            'right' => str_repeat('🛒', 1500),
        ];
        $identity = RootIdentity::forWorkflow(
            $scope,
            TestGreetingWorkflow::class,
            ['Taylor'],
            new \Workflow\V2\StartOptions(memo: $memo)
        );
        $reservation = $this->store()
            ->reserve($identity, 'owner');
        $admitted = $this->store()
            ->start($reservation, 'owner', ['Taylor']);
        $this->assertInstanceOf(RootBinding::class, $admitted);
        $loaded = $this->store()
            ->load($identity, 'owner');
        $this->assertSame($memo, $loaded->identity->profile->options->memo);
        $this->assertSame($admitted->toArray(), $this->store()->close($loaded, 'owner')->toArray());
    }

    private function operate(string $operation, RootReservation $reservation): array
    {
        $outcome = $operation === 'admit'
            ? $this->store()
                ->start($reservation, 'owner', ['Taylor'])
            : $this->store()
                ->close($reservation, 'owner');
        return $outcome instanceof RootBinding
            ? [
                'kind' => 'admitted',
                'binding' => $outcome->toArray(),
            ]
            : [
                'kind' => 'closed',
                'closureId' => $outcome->closureId,
            ];
    }

    private function store(): RootReservationStore
    {
        return new RootReservationStore(new class() implements RootAuthorization {
            public function assertAllowed(RootIdentity $identity, string $principal, string $operation): string
            {
                if ($principal !== 'owner' || $identity->scope->application !== 'race-app'
                    || $identity->scope->namespace !== 'race-tenant' || $identity->scope->key !== 'order'
                    || $identity->scope->generation !== 'original-generation'
                    || $identity->workflowClass !== TestGreetingWorkflow::class || DB::transactionLevel() !== 0) {
                    throw new LogicException('Root race authorization refused.');
                }
                return 'joint-root-policy-v1';
            }
        }, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($principal !== 'owner' || $scope->namespace !== 'race-tenant' || DB::transactionLevel() !== 0) {
                    throw new LogicException('Root race scope authorization refused.');
                }
                return 'scope-policy-v1';
            }
        });
    }

    private function race(RootReservation $reservation, string $winner, ?string $loser = null): array
    {
        $loser ??= $winner === 'close' ? 'admit' : 'close';
        $lockTable = 'workflow_instances';
        $root = sys_get_temp_dir() . '/dw-root-reservation-race-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $driver = DB::connection()->getDriverName();
        DB::purge();
        $children = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the root reservation race.');
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
                            DB::listen(static function (QueryExecuted $query) use ($root, $winner, &$held): void {
                                if ($held || ! str_starts_with(strtolower($query->sql), 'update')) {
                                    return;
                                }
                                if (! str_contains($query->sql, 'native_root_reservation')) {
                                    return;
                                }
                                foreach ($query->bindings as $binding) {
                                    $state = is_string($binding) ? json_decode($binding, true) : null;
                                    if (is_array(
                                        $state
                                    ) && ($state['status'] ?? null) === ($winner === 'close' ? 'closed' : 'admitted')) {
                                        if ($query->connection->transactionLevel() === 0) {
                                            throw new RuntimeException(
                                                'The winner did not retain its native transaction.'
                                            );
                                        }
                                        $held = true;
                                        touch($root . '/held');
                                        self::waitFile($root . '/release');
                                    }
                                }
                            });
                        } else {
                            self::waitFile($root . '/held');
                        }
                        $result = $this->operate(
                            $index === 0 ? $winner : $loser,
                            RootReservation::fromArray($reservation->toArray())
                        );
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
            self::waitFile($root . '/held');
            self::waitFile($root . '/backend-1');
            $backend = (int) file_get_contents($root . '/backend-1');
            DB::reconnect();
            $deadline = microtime(true) + 5;
            $waiting = false;
            while (! $waiting && microtime(true) < $deadline) {
                $rows = $driver === 'pgsql'
                    ? DB::select(
                        'SELECT pid AS id, query AS statement, wait_event_type FROM pg_stat_activity WHERE pid = ?',
                        [$backend]
                    )
                    : DB::select('SHOW FULL PROCESSLIST');
                foreach ($rows as $row) {
                    $id = (int) ($driver === 'pgsql' ? $row->id : $row->Id);
                    $sql = strtolower((string) ($driver === 'pgsql' ? $row->statement : $row->Info));
                    $waiting = $id === $backend && str_starts_with($sql, 'select')
                        && str_contains($sql, $lockTable) && str_contains($sql, 'for update')
                        && ($driver !== 'pgsql' || $row->wait_event_type === 'Lock');
                    if ($waiting) {
                        break;
                    }
                }
                if (! $waiting) {
                    usleep(10_000);
                }
            }
            $this->assertTrue($waiting, 'The competing native reservation row-lock query was not observed.');
            touch($root . '/release');
            $results = [];
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $payload = json_decode(
                    file_get_contents($root . '/' . $index . '.json'),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );
                $this->assertSame(0, pcntl_wexitstatus($status), $payload['error'] ?? 'Reservation contender failed.');
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
                throw new RuntimeException('The native reservation race barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
