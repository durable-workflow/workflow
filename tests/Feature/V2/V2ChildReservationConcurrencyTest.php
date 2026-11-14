<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StateMachine\Binding\BindingAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowExecutor;

final class V2ChildReservationConcurrencyTest extends TestCase
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
    public function testNativeAdmissionAndClosureSerializeOnTheReservedInstance(string $winner): void
    {
        [$parent, $task, $reservation] = $this->reserve();
        $results = $this->race($parent, $task, $reservation, $winner);
        if ($winner === 'close') {
            $this->assertSame('closed', $results[0]['kind']);
            $this->assertSame('refused', $results[1]['kind']);
            $this->assertSame(
                0,
                WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
            );
            $closed = $this->store()
                ->close($reservation, 'owner');
            $this->assertInstanceOf(NeverAdmittedChild::class, $closed);
            $this->assertSame($results[0]['closureId'], $closed->closureId);
        } else {
            $this->assertSame('admitted', $results[0]['kind']);
            $this->assertSame('admitted', $results[1]['kind']);
            $this->assertSame($results[0]['binding'], $results[1]['binding']);
            $this->assertSame(
                1,
                WorkflowRun::query()->where('workflow_instance_id', $reservation->childInstanceId())->count()
            );
            $this->assertSame(1, $parent->historyEvents()->where('event_type', 'ChildRunStarted')->count());
        }
    }

    private function store(): ChildReservationStore
    {
        return new ChildReservationStore(new class() implements BindingAuthorization {
            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                if ($principal !== 'owner' || $identity->namespace !== 'race-tenant'
                    || DB::connection()->transactionLevel() !== 0) {
                    throw new LogicException('Race fixture authorization refused.');
                }
                return 'race-policy-v1';
            }
        });
    }

    private function reserve(): array
    {
        $type = TypeRegistry::for(TestGreetingWorkflow::class);
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => $type,
            'namespace' => 'race-tenant',
            'reserved_at' => now(),
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => $type,
            'namespace' => 'race-tenant',
            'status' => RunStatus::Running,
            'started_at' => now(),
            'last_history_sequence' => 0,
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $identity = new BindingIdentity(
            new ExecutionReference('race-app', 'subject', 'execution', str_repeat('a', 64), 'ready', '1'),
            'race-tenant',
            $instance->id,
            $run->id,
            'child',
            'entry-1',
            'working',
            $type,
            str_repeat('b', 64),
            'delivered',
            ChildReservation::inputFingerprint(['Taylor']),
        );
        $reservation = $this->store()
            ->reserve($identity, TestGreetingWorkflow::class, 'owner');
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'payload' => [],
        ]);
        return [$run, $task, $reservation];
    }

    private function operate(
        string $operation,
        WorkflowRun $parent,
        WorkflowTask $task,
        ChildReservation $reservation
    ): array {
        if ($operation === 'admit') {
            try {
                DB::transaction(static function () use ($parent, $task, $reservation): void {
                    (new ReflectionMethod(WorkflowExecutor::class, 'scheduleChildWorkflow'))->invoke(
                        new WorkflowExecutor(),
                        $parent,
                        $task,
                        1,
                        new ChildWorkflowCall($reservation->workflowClass, [
                            'Taylor',
                        ], $reservation->identity->childOptions, $reservation),
                        null,
                        false,
                        true,
                    );
                });
            } catch (LogicException $error) {
                if (! str_contains($error->getMessage(), 'closed, already admitted')) {
                    throw $error;
                }
                return [
                    'kind' => 'refused',
                ];
            }
        }
        $outcome = $this->store()
            ->close($reservation, 'owner');
        return $outcome instanceof ChildBinding
            ? [
                'kind' => 'admitted',
                'binding' => $outcome->toArray(),
            ]
            : [
                'kind' => 'closed',
                'closureId' => $outcome->closureId,
            ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function race(WorkflowRun $parent, WorkflowTask $task, ChildReservation $reservation, string $winner): array
    {
        $root = sys_get_temp_dir() . '/dw-child-reservation-race-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $driver = DB::connection()->getDriverName();
        DB::purge();
        $children = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork the child reservation race.');
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
                                if ($held || ! str_starts_with(strtolower($query->sql), 'update')
                                    || ! str_contains($query->sql, 'native_child_reservation')) {
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
                            $index === 0 ? $winner : ($winner === 'close' ? 'admit' : 'close'),
                            $parent,
                            $task,
                            $reservation
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
                        && str_contains($sql, 'workflow_instances') && str_contains($sql, 'for update')
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
