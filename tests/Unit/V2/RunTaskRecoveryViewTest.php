<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Support\RunTaskView;
use Workflow\V2\Support\RunWaitProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class RunTaskRecoveryViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07T12:00:00Z'));
        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'task-view',
        ]);
        WorkerCompatibilityFleet::clear();
    }

    #[DataProvider('recoveryCases')]
    public function testRecoveryDiagnosticsRetainTransportStateWithoutRepairingTasks(
        TaskType $type,
        string $scenario,
        string $transportState,
        string $summary,
    ): void {
        $run = $this->createRun();
        $task = $this->createTask($run, $type, $scenario);
        $row = $this->readTask($run, $task);

        $this->assertSame($transportState, $row['transport_state']);
        $this->assertSame($summary, $row['summary']);
        $this->assertSame($task->status->value, $row['status']);
        $this->assertTrue($row['is_open']);
        $this->assertFalse($row['synthetic']);
        $this->assertFalse($row['task_missing']);
        $this->assertSame(str_starts_with($scenario, 'dispatch'), $row['dispatch_failed']);
        $this->assertSame(str_starts_with($scenario, 'claim'), $row['claim_failed']);
        $this->assertSame($scenario === 'overdue', $row['dispatch_overdue']);
        $this->assertSame($scenario === 'expired', $row['lease_expired']);
        $this->assertSame(2, $row['attempt_count']);
        $this->assertSame(3, $row['repair_count']);
        $this->assertSame(12, $row['repair_backoff_seconds']);
        $this->assertSame('redis', $row['connection']);
        $this->assertSame('task-view', $row['queue']);
        $this->assertSame('retained diagnostic', $row['last_error']);
        foreach ([
            'available_at', 'last_dispatch_attempt_at', 'last_dispatched_at', 'last_dispatch_error',
            'last_claim_failed_at', 'last_claim_error', 'repair_available_at', 'leased_at',
            'lease_owner', 'lease_expires_at',
        ] as $field) {
            $this->assertEquals($task->{$field}, $row[$field], $field);
        }
        if ($type === TaskType::Timer) {
            $this->assertSame($task->payload['timer_id'], $row['timer_id']);
            $this->assertSame(1, $row['timer_sequence']);
            $this->assertTrue($row['timer_fire_at']->equalTo(now()->addSecond()));
            $this->assertSame('approval-wait', $row['signal_wait_id']);
            $this->assertSame('approval', $row['signal_name']);
        }
    }

    public static function recoveryCases(): array
    {
        $cases = [];
        foreach ([TaskType::Workflow, TaskType::Timer] as $type) {
            $label = $type === TaskType::Workflow ? 'Workflow task' : 'Signal timeout for 1 second task';
            $normal = $type === TaskType::Workflow
                ? 'Workflow task ready to resume the selected run.'
                : 'Signal timeout for 1 second task ready.';
            $cases[$type->value . ' expired lease boundary'] = [
                $type, 'expired', 'lease_expired', $label . ' lease expired; waiting for recovery.',
            ];
            $cases[$type->value . ' live lease'] = [
                $type, 'leased', 'leased', $type === TaskType::Workflow
                    ? 'Workflow task leased to a worker.'
                    : 'Signal timeout for 1 second task leased to a worker.',
            ];
            $cases[$type->value . ' repair backoff after dispatch failure'] = [
                $type, 'dispatch-backoff', 'repair_backoff',
                $label . ' dispatch failed; next repair is available at 2026-10-07T12:00:12.000000Z.',
            ];
            $cases[$type->value . ' dispatch repair due'] = [
                $type, 'dispatch-due', 'dispatch_failed', $label . ' dispatch failed; waiting for recovery.',
            ];
            $cases[$type->value . ' scheduled dispatch in backoff'] = [
                $type, 'dispatch-scheduled-backoff', 'repair_backoff',
                $label . ' dispatch failed; next repair is available at 2026-10-07T12:00:12.000000Z.',
            ];
            $cases[$type->value . ' scheduled dispatch repair due'] = [
                $type, 'dispatch-scheduled-due', 'dispatch_failed', $label . ' dispatch failed; waiting for recovery.',
            ];
            $cases[$type->value . ' claim repair backoff'] = [
                $type, 'claim-backoff', 'repair_backoff',
                $label . ' claim failed; next repair is available at 2026-10-07T12:00:12.000000Z.',
            ];
            $cases[$type->value . ' claim repair due'] = [
                $type, 'claim-due', 'claim_failed', $label . ' claim failed; worker backend capability is unsupported.',
            ];
            $cases[$type->value . ' overdue boundary'] = [
                $type, 'overdue', 'dispatch_overdue', $label . ' is ready but dispatch is overdue.',
            ];
            $cases[$type->value . ' scheduled without failure'] = [$type, 'scheduled', 'scheduled', $normal];
        }

        return $cases;
    }

    #[DataProvider('compatibilityCases')]
    public function testUnavailableCompatibleWorkerPreservesTheRecoveryDiagnosis(
        TaskType $type,
        string $scenario,
        string $summary,
    ): void {
        $run = $this->createRun();
        $task = $this->createTask($run, $type, $scenario);
        $task->forceFill([
            'compatibility' => 'build-b',
        ])->save();
        $row = $this->readTask($run, $task);

        $this->assertSame($summary, $row['summary']);
        $this->assertSame('build-b', $row['compatibility']);
        $this->assertFalse($row['compatibility_supported']);
        $this->assertFalse($row['compatibility_supported_in_fleet']);
        $this->assertSame(
            'Requires compatibility [build-b]; this worker supports [build-a].',
            $row['compatibility_reason']
        );
        $this->assertSame($scenario === 'expired', $row['lease_expired']);
        $this->assertSame($scenario === 'dispatch-due', $row['dispatch_failed']);
        $this->assertSame($scenario === 'overdue', $row['dispatch_overdue']);
        if ($type === TaskType::Activity) {
            $this->assertSame('send-greeting', $row['activity_type']);
            $this->assertSame(TestGreetingActivity::class, $row['activity_class']);
            $this->assertSame($task->payload['activity_execution_id'], $row['activity_execution_id']);
        }
    }

    public static function compatibilityCases(): array
    {
        return [
            'activity expired' => [TaskType::Activity, 'expired',
                'Activity task lease expired and is waiting for a compatible worker for send-greeting.'],
            'activity dispatch failed' => [TaskType::Activity, 'dispatch-due',
                'Activity task dispatch failed and is waiting for a compatible worker for send-greeting.'],
            'activity overdue' => [TaskType::Activity, 'overdue',
                'Activity task is waiting for a compatible worker for send-greeting; dispatch is overdue.'],
            'activity ready' => [TaskType::Activity, 'ready',
                'Activity task is waiting for a compatible worker for send-greeting.'],
            'timer expired' => [TaskType::Timer, 'expired',
                'Signal timeout for 1 second task lease expired and is waiting for a compatible worker.'],
            'timer dispatch failed' => [TaskType::Timer, 'dispatch-due',
                'Signal timeout for 1 second task dispatch failed and is waiting for a compatible worker.'],
            'timer overdue' => [TaskType::Timer, 'overdue',
                'Signal timeout for 1 second task is waiting for a compatible worker; dispatch is overdue.'],
            'timer ready' => [TaskType::Timer, 'ready',
                'Signal timeout for 1 second task is waiting for a compatible worker.'],
        ];
    }

    #[DataProvider('fleetCases')]
    public function testOnlyCompatibleWorkersOnTheTaskRouteRemoveTheFleetWarning(
        string $namespace,
        string $connection,
        string $queue,
        string $build,
        bool $supported,
    ): void {
        $run = $this->createRun();
        $task = $this->createTask($run, TaskType::Timer, 'expired');
        // An older task may inherit the selected run's compatibility contract.
        $run->forceFill([
            'compatibility' => 'build-b',
        ])->save();
        $task->forceFill([
            'compatibility' => null,
        ])->save();
        WorkerCompatibilityFleet::recordForNamespace($namespace, [$build], $connection, $queue, 'view-worker');
        $row = $this->readTask($run, $task);

        $this->assertSame('build-b', $row['compatibility']);
        $this->assertFalse($row['compatibility_supported']);
        $this->assertSame($supported, $row['compatibility_supported_in_fleet']);
        $this->assertSame($supported, $row['compatibility_fleet_reason'] === null);
        $this->assertSame('lease_expired', $row['transport_state']);
        $this->assertTrue($row['lease_expired']);
        $this->assertSame($supported
            ? 'Signal timeout for 1 second task lease expired; waiting for recovery.'
            : 'Signal timeout for 1 second task lease expired and is waiting for a compatible worker.', $row['summary']);
    }

    public static function fleetCases(): array
    {
        return [
            'matching route and build' => ['task-view', 'redis', 'task-view', 'build-b', true],
            'different namespace' => ['outside', 'redis', 'task-view', 'build-b', false],
            'different connection' => ['task-view', 'other', 'task-view', 'build-b', false],
            'different queue' => ['task-view', 'redis', 'other', 'build-b', false],
            'different build' => ['task-view', 'redis', 'task-view', 'build-a', false],
        ];
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'task-view-' . strtolower((string) Str::ulid()),
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'greeting',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'greeting',
            'namespace' => 'task-view',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize(['Taylor']),
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'task-view',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function createTask(WorkflowRun $run, TaskType $type, string $scenario): WorkflowTask
    {
        $payload = [];
        if ($type === TaskType::Activity) {
            $activity = ActivityExecution::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'activity_class' => TestGreetingActivity::class,
                'activity_type' => 'send-greeting',
                'status' => ActivityStatus::Pending->value,
            ]);
            $payload['activity_execution_id'] = $activity->id;
        } elseif ($type === TaskType::Timer) {
            $timer = WorkflowTimer::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'status' => TimerStatus::Pending->value,
                'delay_seconds' => 1,
                'fire_at' => now()
                    ->addSecond(),
            ]);
            $payload = [
                'timer_id' => $timer->id,
                'signal_wait_id' => 'approval-wait',
                'signal_name' => 'approval',
            ];
        }
        $attributes = [
            'workflow_run_id' => $run->id,
            'task_type' => $type->value,
            'status' => TaskStatus::Ready->value,
            'payload' => $payload,
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'task-view',
            'available_at' => now(),
            'last_dispatched_at' => now(),
            'attempt_count' => 2,
            'repair_count' => 3,
            'last_error' => 'retained diagnostic',
        ];
        if (in_array($scenario, ['expired', 'leased'], true)) {
            $attributes += [
                'leased_at' => now()
                    ->subSeconds(10),
                'lease_owner' => 'previous-worker',
                'lease_expires_at' => $scenario === 'expired' ? now() : now()
                    ->addSecond(),
            ];
            $attributes['status'] = TaskStatus::Leased->value;
        }
        if (str_starts_with($scenario, 'dispatch')) {
            $attributes['last_dispatched_at'] = now()->subMinute();
            $attributes['last_dispatch_attempt_at'] = now()->subSecond();
            $attributes['last_dispatch_error'] = 'queue unavailable';
        }
        if (str_starts_with($scenario, 'claim')) {
            $attributes['last_claim_failed_at'] = now()->subSecond();
            $attributes['last_claim_error'] = 'backend cannot claim tasks';
        }
        if (str_starts_with($scenario, 'dispatch') || str_starts_with($scenario, 'claim')) {
            $attributes['repair_available_at'] = str_ends_with($scenario, 'backoff')
                ? now()
                    ->addSeconds(12) : now();
        }
        if (str_contains($scenario, 'scheduled')) {
            $attributes['available_at'] = now()->addMinute();
        }
        if ($scenario === 'overdue') {
            $attributes['last_dispatched_at'] = now()->subSeconds(3);
        }

        return WorkflowTask::query()->create($attributes);
    }

    private function readTask(WorkflowRun $run, WorkflowTask $task): array
    {
        // Embedded operation permits read-side projection repair. Prepare the
        // fixture's wait projection before checking ordinary diagnostic reads.
        RunWaitProjector::project($run->fresh());
        $before = $task->fresh()
            ->getRawOriginal();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $rows = RunTaskView::forRun($run->fresh());
            $this->assertEquals($rows, RunTaskView::forRun($run->fresh()));
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^\s*(?:insert|update|delete|replace|truncate|alter|create|drop)\b/i',
                    $query['query'],
                    'Viewing recovery diagnostics must not repair or otherwise mutate runtime records.',
                );
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($before, $task->fresh()->getRawOriginal());
        $row = collect($rows)
            ->firstWhere('id', $task->id);
        $this->assertIsArray($row);

        return $row;
    }
}
