<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class DatabaseQueueRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16T06:00:00.123456Z'));
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL,
            payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER,
            available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
            connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL,
            failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function deliveries(): array
    {
        $cases = [];
        foreach (['workflow', 'activity', 'timer'] as $type) {
            foreach (['queued', 'missing', 'reserved'] as $delivery) {
                $cases[$type . ' ' . $delivery] = [$type, $delivery];
            }
        }
        return $cases;
    }

    #[DataProvider('deliveries')]
    public function testRecoveryDistinguishesQueuedWorkFromMissingOrReservedTransport(
        string $type,
        string $delivery
    ): void {
        $stub = WorkflowStub::make(
            $type === 'timer' ? TestTimerWorkflow::class : TestGreetingWorkflow::class,
            'queue-recovery-' . $type,
            '0',
        );
        $stub->start($type === 'timer' ? 1 : 'queue-user', new StartOptions(
            executionTimeoutSeconds: 120,
            runTimeoutSeconds: 100,
        ));
        if ($type !== 'workflow') {
            self::assertSame(0, Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'default',
                '--once' => true,
                '--tries' => 1,
            ]));
        }
        $task = WorkflowTask::query()->where('task_type', $type)->sole();
        $taskId = $task->id;
        self::assertSame('ready', $task->status->value);
        self::assertIsArray($task->queue_delivery_reference);
        self::assertSame($task->id, $task->queue_delivery_reference['task_id']);
        self::assertArrayNotHasKey('queue_delivery_reference', $task->toArray());
        self::assertSame(1, DB::table('jobs')->count());
        $originalJob = DB::table('jobs')->sole();
        if ($delivery === 'missing') {
            DB::table('jobs')->where('id', $originalJob->id)->delete();
        } elseif ($delivery === 'reserved') {
            DB::table('jobs')->where('id', $originalJob->id)->update([
                'reserved_at' => now()
->timestamp,
                'attempts' => 1,
            ]);
        }
        $before = $task->getRawOriginal();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $timing = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all();
        Carbon::setTestNow(now()->addSeconds(4));

        $report = TaskWatchdog::runPass('database', 'default', runIds: [$run->id]);

        $task->refresh();
        self::assertSame($delivery === 'queued' ? 0 : 1, $report['dispatched_tasks']);
        self::assertSame($delivery === 'reserved' ? 2 : 1, DB::table('jobs')->count());
        self::assertSame($delivery === 'queued' ? 0 : 1, $task->repair_count);
        self::assertSame($taskId, $task->id);
        self::assertSame('ready', $task->status->value);
        self::assertSame(0, (int) $task->attempt_count);
        self::assertNull($task->lease_owner);
        self::assertNull($task->lease_expires_at);
        if ($delivery === 'queued') {
            self::assertSame($before, $task->getRawOriginal());
            self::assertSame((array) $originalJob, (array) DB::table('jobs')->sole());
            self::assertSame(0, TaskWatchdog::runPass('database', 'default', runIds: [$run->id])['dispatched_tasks']);
            self::assertSame($before, $task->fresh()->getRawOriginal());
        }
        self::assertSame($history, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map->getRawOriginal()
            ->all());
        $run->refresh();
        self::assertSame(
            $timing,
            [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')]
        );
    }

    public static function scanPositions(): array
    {
        return [
            'ordinary timestamps' => [false],
            'nullable legacy timestamps' => [true],
        ];
    }

    #[DataProvider('scanPositions')]
    public function testHealthyBacklogDoesNotStarveMissingDeliveryPastTheScanLimit(bool $legacy): void
    {
        config()->set('workflows.v2.task_repair.scan_limit', 1);
        $queued = WorkflowStub::make(TestGreetingWorkflow::class, 'queued-before-missing', '0');
        $queued->start('queue-user', new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
        $queuedTask = WorkflowTask::query()->sole();
        if ($legacy) {
            $queuedTask->forceFill([
                'available_at' => null,
                'created_at' => null,
            ])->save();
        }
        $before = $queuedTask->getRawOriginal();
        Carbon::setTestNow(now()->addSecond());
        $missing = WorkflowStub::make(TestGreetingWorkflow::class, 'missing-after-queued', '0');
        $missing->start('queue-user', new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
        $missingTask = WorkflowTask::query()->where('workflow_run_id', $missing->runId())->sole();
        DB::table('jobs')->where('id', $missingTask->queue_delivery_reference['job_id'])->delete();
        Carbon::setTestNow(now()->addSeconds(4));

        $first = TaskWatchdog::runPass('database', 'default');
        $second = TaskWatchdog::runPass('database', 'default');

        self::assertSame(1, $first['selected_existing_task_candidates']);
        self::assertSame(0, $first['dispatched_tasks']);
        self::assertSame(1, $second['selected_existing_task_candidates']);
        self::assertSame(1, $second['dispatched_tasks']);
        self::assertSame($before, $queuedTask->fresh()->getRawOriginal());
        self::assertSame(1, $missingTask->fresh()->repair_count);
        self::assertSame(2, DB::table('jobs')->count());
        DB::table('jobs')->where('id', $queuedTask->queue_delivery_reference['job_id'])->delete();
        self::assertSame(1, TaskWatchdog::runPass('database', 'default')['dispatched_tasks']);
        self::assertSame(1, $queuedTask->fresh()->repair_count);
        self::assertSame(2, DB::table('jobs')->count());
    }

    public function testCandidatePositionsDoNotHydratePartialTaskModels(): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'scan-without-hydration', '0');
        $stub->start('queue-user', new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
        $taskId = WorkflowTask::query()->sole()->id;
        $partialTasks = 0;
        $this->app['events']->listen('eloquent.retrieved: ' . WorkflowTask::class, static function ($task) use (
            &$partialTasks
        ): void {
            if (array_key_exists('id', $task->getAttributes())) {
                ++$partialTasks;
            }
        });
        Carbon::setTestNow(now()->addSeconds(4));

        self::assertSame([$taskId], TaskRepairCandidates::taskIds(runIds: [$stub->runId()]));
        self::assertSame(0, $partialTasks);
    }

    public static function unusableReferences(): array
    {
        $names = ['legacy schema', 'missing', 'version', 'task', 'run', 'namespace',
            'connection', 'queue', 'database', 'table', 'job', 'uuid', 'row uuid', 'row class',
            'row queue', 'row queue case', 'invalid json', 'oversized', 'job overflow'];
        return array_combine($names, array_map(static fn (string $name): array => [$name], $names));
    }

    #[DataProvider('unusableReferences')]
    public function testUnusableReferenceRetainsRecoveryWithoutGrantingTaskAuthority(string $case): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'reference-control', '0');
        $stub->start('queue-user', new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
        $task = WorkflowTask::query()->sole();
        $reference = $task->queue_delivery_reference;
        self::assertIsArray($reference);
        if ($case === 'legacy schema') {
            Schema::table('workflow_tasks', static function ($table): void {
                $table->dropColumn('queue_delivery_reference');
            });
        } elseif (str_starts_with($case, 'row ')) {
            $row = DB::table('jobs')->sole();
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            if ($case === 'row uuid') {
                $payload['uuid'] = 'different-original-payload';
            } elseif ($case === 'row class') {
                $payload['data']['commandName'] = \Workflow\V2\Jobs\RunActivityTask::class;
            }
            DB::table('jobs')->where('id', $row->id)->update([
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'queue' => match ($case) {
                    'row queue' => 'other',
                    'row queue case' => strtoupper($row->queue),
                    default => $row->queue,
                },
            ]);
        } elseif (in_array($case, ['invalid json', 'oversized', 'missing'], true)) {
            DB::table('workflow_tasks')->where('id', $task->id)->update([
                'queue_delivery_reference' => match ($case) {
                    'invalid json' => '{',
                    'oversized' => json_encode([
                        'padding' => str_repeat('x', 2049),
                    ]),
                    default => null,
                },
            ]);
        } else {
            $field = [
                'task' => 'task_id',
                'run' => 'run_id',
                'job' => 'job_id',
                'job overflow' => 'job_id',
                'uuid' => 'payload_uuid',
            ][$case] ?? $case;
            $reference[$field] = match ($case) {
                'version' => 2, 'job' => '99999', 'job overflow' => (string) PHP_INT_MAX . '0', default => 'other',
            };
            $task->forceFill([
                'queue_delivery_reference' => $reference,
            ])->save();
        }
        $history = WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map->getRawOriginal()->all();
        Carbon::setTestNow(now()->addSeconds(4));

        $report = TaskWatchdog::runPass('database', 'default', runIds: [$stub->runId()]);

        self::assertSame(1, $report['dispatched_tasks']);
        self::assertSame(2, DB::table('jobs')->count());
        $task->refresh();
        self::assertSame(1, $task->repair_count);
        self::assertSame(0, (int) $task->attempt_count);
        self::assertNull($task->lease_owner);
        self::assertSame('ready', $task->status->value);
        self::assertSame(
            $history,
            WorkflowHistoryEvent::query()->orderBy('sequence')->get()->map->getRawOriginal()->all()
        );
    }

    public function testDisappearingDeliveryIsRecheckedOnTheNextPassOutsideRepairLocks(): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'delivery-disappears', '0');
        $stub->start('queue-user', new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
        $task = WorkflowTask::query()->sole();
        $jobId = DB::table('jobs')->value('id');
        $levels = [];
        $armed = true;
        DB::listen(static function ($query) use (&$armed, &$levels, $jobId): void {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"payload"')
                && str_contains($query->sql, 'from "jobs"') && in_array((string) $jobId, $query->bindings, true)) {
                $armed = false;
                $levels[] = $query->connection->transactionLevel();
                DB::table('jobs')->where('id', $jobId)->delete();
            }
        });
        Carbon::setTestNow(now()->addSeconds(4));

        self::assertSame(0, TaskWatchdog::runPass('database', 'default', runIds: [$stub->runId()])['dispatched_tasks']);
        self::assertSame([0], $levels);
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, $task->fresh()->repair_count);
        self::assertSame(1, TaskWatchdog::runPass('database', 'default', runIds: [$stub->runId()])['dispatched_tasks']);
        self::assertSame(1, DB::table('jobs')->count());
        self::assertSame(1, $task->fresh()->repair_count);
        self::assertSame(0, (int) $task->fresh()->attempt_count);
    }

    public function testLookupUsesTheActualSeparateTransportDatabaseAndTable(): void
    {
        config()->set('database.connections.transport', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()
            ->set('queue.connections.transport-queue', [
                'driver' => 'database',
                'connection' => 'transport',
                'table' => 'transport_jobs',
                'queue' => 'selected',
                'retry_after' => 90,
            ]);
        config()
            ->set('workflows.v2.connection', 'transport-queue');
        config()
            ->set('workflows.v2.queue', 'selected');
        DB::connection('transport')->statement('CREATE TABLE transport_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER,
            available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'separate-transport', '0');
        $stub->start('queue-user');
        $task = WorkflowTask::query()->sole();
        self::assertSame('transport', $task->queue_delivery_reference['database']);
        self::assertSame('transport_jobs', $task->queue_delivery_reference['table']);
        self::assertSame('selected', $task->queue_delivery_reference['queue']);
        Carbon::setTestNow(now()->addSeconds(4));

        $report = TaskWatchdog::runPass('transport-queue', 'selected', runIds: [$stub->runId()]);

        self::assertSame(0, $report['dispatched_tasks']);
        self::assertSame(1, DB::connection('transport')->table('transport_jobs')->count());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, $task->fresh()->repair_count);
    }

    public function testApplicationQueueManagerRetainsItsExistingRecoveryBehavior(): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'application-manager', '0');
        $stub->start('queue-user');
        $manager = new class($this->app) extends \Illuminate\Queue\QueueManager {
        };
        $manager->addConnector(
            'database',
            fn () => new \Illuminate\Queue\Connectors\DatabaseConnector($this->app['db'])
        );
        $this->app->instance('queue', $manager);
        Carbon::setTestNow(now()->addSeconds(4));

        $report = TaskWatchdog::runPass('database', 'default', runIds: [$stub->runId()]);

        self::assertSame($manager, $this->app['queue']);
        self::assertSame(1, $report['dispatched_tasks']);
        self::assertSame(2, DB::table('jobs')->count());
        self::assertNull(WorkflowTask::query()->sole()->queue_delivery_reference);
    }

    public function testOlderJobQueuedEventShapeUsesTheObservedUnchangedPayloadUuid(): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'legacy-event', '0');
        $stub->start('queue-user');
        $task = WorkflowTask::query()->sole();
        $row = DB::table('jobs')->sole();
        $job = new \Workflow\V2\Jobs\RunWorkflowTask($task->id);
        $event = (new \ReflectionClass(\Illuminate\Queue\Events\JobQueued::class))->newInstanceWithoutConstructor();
        $event->id = $row->id;
        $event->job = $job;
        $event->connectionName = 'database';
        self::assertSame([], \Workflow\Queue\DatabaseTaskDelivery::observePayload('database', 'default', [
            'uuid' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['uuid'],
            'data' => [
                'command' => $job,
            ],
        ]));
        \Workflow\Queue\DatabaseTaskDelivery::observe($event);

        $reference = \Workflow\Queue\DatabaseTaskDelivery::capture($task, $job);

        self::assertIsArray($reference);
        self::assertSame((string) $row->id, $reference['job_id']);
        self::assertSame(
            json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)['uuid'],
            $reference['payload_uuid']
        );
    }

    public function testLegacyEventWithoutOriginalUuidCannotGuessAReferenceFromTheCurrentRow(): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'unobserved-uuid', '0');
        $stub->start('queue-user');
        $task = WorkflowTask::query()->sole();
        $job = new \Workflow\V2\Jobs\RunWorkflowTask($task->id);
        $event = (new \ReflectionClass(\Illuminate\Queue\Events\JobQueued::class))->newInstanceWithoutConstructor();
        $event->id = DB::table('jobs')->value('id');
        $event->job = $job;
        $event->connectionName = 'database';
        \Workflow\Queue\DatabaseTaskDelivery::observe($event);

        self::assertNull(\Workflow\Queue\DatabaseTaskDelivery::capture($task, $job));
    }

    public function testAnExistingCallerTransactionRetainsTheOriginalRepairPath(): void
    {
        $stub = WorkflowStub::make(TestGreetingWorkflow::class, 'existing-transaction', '0');
        $stub->start('queue-user');
        Carbon::setTestNow(now()->addSeconds(4));
        $probeReads = 0;
        DB::listen(static function ($query) use (&$probeReads): void {
            if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"payload"')
                && str_contains($query->sql, 'from "jobs"')) {
                ++$probeReads;
            }
        });

        DB::transaction(static function () use ($stub): void {
            self::assertSame(
                1,
                TaskWatchdog::runPass('database', 'default', runIds: [$stub->runId()])['dispatched_tasks']
            );
            self::assertSame(
                1,
                DB::table('jobs')->count(),
                'New transport remains deferred until the original commit.'
            );
        });

        self::assertSame(0, $probeReads);
        self::assertSame(2, DB::table('jobs')->count());
        self::assertSame(1, WorkflowTask::query()->sole()->repair_count);
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'queue-recovery',
            'database.connections.queue-recovery' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'workflows.storage.connection' => 'queue-recovery',
            'workflows.v1.enabled' => false,
            'workflows.v2.compatibility.namespace' => '0',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.matching_role.queue_wake_enabled' => false,
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'queue-recovery',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids',
                'database' => 'queue-recovery',
                'table' => 'failed_jobs',
            ],
            'cache.default' => 'array',
            'logging.default' => 'null',
        ]);
    }
}
