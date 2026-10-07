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
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\RunTaskView;
use Workflow\V2\Support\RunWaitProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;

final class RunActivityTaskViewTest extends TestCase
{
    private const POLICY = [
        'snapshot_version' => 1,
        'max_attempts' => 4,
        'backoff_seconds' => [3, 7, 11],
        'start_to_close_timeout' => null,
        'schedule_to_start_timeout' => null,
        'schedule_to_close_timeout' => null,
        'heartbeat_timeout' => null,
        'non_retryable_error_types' => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07T12:00:00Z'));
        config([
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
            'workflows.v2.compatibility.namespace' => 'activity-task-view',
        ]);
        WorkerCompatibilityFleet::clear();
    }

    #[DataProvider('taskCases')]
    public function testActivityTransportStatusRetainsItsIdentityAndRetryMetadata(
        TaskStatus $status,
        bool $retry,
        bool $scheduled,
        string $summary,
    ): void {
        $run = $this->createRun();
        $activity = $this->createActivity($run);
        $payload = [
            'activity_execution_id' => $activity->id,
        ];
        if ($retry) {
            $payload += [
                'retry_of_task_id' => (string) Str::ulid(),
                'retry_after_attempt_id' => (string) Str::ulid(),
                'retry_after_attempt' => '2',
                'retry_backoff_seconds' => '7',
                'max_attempts' => '4',
                'retry_policy' => self::POLICY,
            ];
        }
        $task = $this->createTask($run, $payload, $status, $scheduled);
        $row = collect($this->readRows($run))
            ->firstWhere('id', $task->id);

        $this->assertIsArray($row);
        $this->assertSame($summary, $row['summary']);
        $this->assertSame($status->value, $row['status']);
        $this->assertSame($scheduled ? 'scheduled' : $status->value, $row['transport_state']);
        $this->assertFalse($row['task_missing']);
        $this->assertFalse($row['synthetic']);
        $this->assertSame(in_array($status, [TaskStatus::Ready, TaskStatus::Leased], true), $row['is_open']);
        $this->assertSame($activity->id, $row['activity_execution_id']);
        $this->assertSame('send-greeting', $row['activity_type']);
        $this->assertSame(TestGreetingActivity::class, $row['activity_class']);
        $this->assertSame('activities', $row['queue']);
        $this->assertEquals($task->available_at, $row['available_at']);
        $this->assertSame($retry ? 2 : null, $row['retry_after_attempt']);
        $this->assertSame($retry ? 7 : null, $row['retry_backoff_seconds']);
        $this->assertSame($retry ? 4 : null, $row['retry_max_attempts']);
        $this->assertSame($retry ? self::POLICY : null, $row['retry_policy']);
        foreach (['retry_of_task_id', 'retry_after_attempt_id'] as $field) {
            $this->assertSame($payload[$field] ?? null, $row[$field]);
        }
        if ($status === TaskStatus::Leased) {
            $this->assertSame('activity-worker', $row['lease_owner']);
            $this->assertEquals($task->lease_expires_at, $row['lease_expires_at']);
            $this->assertFalse($row['lease_expired']);
        }
    }

    public static function taskCases(): array
    {
        return [
            'retry ready' => [TaskStatus::Ready, true, false, 'Activity retry 3 ready for send-greeting.'],
            'retry scheduled' => [TaskStatus::Ready, true, true,
                'Activity retry 3 for send-greeting scheduled for 2026-10-07T12:00:07.000000Z.'],
            'retry leased' => [TaskStatus::Leased, true, false, 'Activity retry 3 leased for send-greeting.'],
            'retry completed' => [TaskStatus::Completed, true, false, 'Activity retry 3 completed for send-greeting.'],
            'retry cancelled' => [TaskStatus::Cancelled, true, false, 'Activity retry 3 cancelled for send-greeting.'],
            'retry failed' => [TaskStatus::Failed, true, false, 'Activity retry 3 failed for send-greeting.'],
            'initial ready' => [TaskStatus::Ready, false, false, 'Activity task ready for send-greeting.'],
            'initial leased' => [TaskStatus::Leased, false, false, 'Activity task leased for send-greeting.'],
            'initial completed' => [TaskStatus::Completed, false, false, 'Activity task completed for send-greeting.'],
            'initial cancelled' => [TaskStatus::Cancelled, false, false, 'Activity task cancelled for send-greeting.'],
            'initial failed' => [TaskStatus::Failed, false, false, 'Activity task failed for send-greeting.'],
        ];
    }

    public function testActivityClassRemainsVisibleWhenItsTypeIsAbsent(): void
    {
        $run = $this->createRun();
        $activity = $this->createActivity($run, type: '');
        $task = $this->createTask($run, [
            'activity_execution_id' => $activity->id,
        ]);
        $row = collect($this->readRows($run))
            ->firstWhere('id', $task->id);

        $this->assertNull($row['activity_type']);
        $this->assertSame(TestGreetingActivity::class, $row['activity_class']);
        $this->assertSame('Activity task ready for ' . TestGreetingActivity::class . '.', $row['summary']);
    }

    public function testTransportRetainsTheActivityLocatorWhenMetadataIsUnavailable(): void
    {
        $run = $this->createRun();
        $activityId = (string) Str::ulid();
        $task = $this->createTask($run, [
            'activity_execution_id' => $activityId,
        ]);
        $row = collect($this->readRows($run))
            ->firstWhere('id', $task->id);

        $this->assertSame($activityId, $row['activity_execution_id']);
        $this->assertNull($row['activity_type']);
        $this->assertNull($row['activity_class']);
        $this->assertSame('Activity task ready for activity.', $row['summary']);
        $this->assertFalse($row['task_missing']);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
    }

    #[DataProvider('policyCases')]
    public function testMissingRetryUsesTheLatestHistoryForThatActivity(bool $recordPolicy): void
    {
        $run = $this->createRun();
        $activity = $this->createActivity($run);
        $closedTask = $this->createTask($run, [
            'activity_execution_id' => $activity->id,
        ], TaskStatus::Failed);
        $previousAttemptId = (string) Str::ulid();
        $retryTaskId = (string) Str::ulid();
        $this->recordRetry($run, $activity, [
            'retry_after_attempt' => 1,
            'retry_available_at' => now()
                ->addSeconds(3)
                ->toJSON(),
            'retry_backoff_seconds' => 3,
        ]);
        $latest = [
            'retry_task_id' => $retryTaskId,
            'retry_of_task_id' => $closedTask->id,
            'retry_after_attempt_id' => $previousAttemptId,
            'retry_after_attempt' => '2',
            'retry_available_at' => now()
                ->addSeconds(7)
                ->toJSON(),
            'retry_backoff_seconds' => '7',
            'max_attempts' => '4',
        ];
        if ($recordPolicy) {
            $latest['retry_policy'] = self::POLICY;
        }
        $this->recordRetry($run, $activity, $latest);
        $other = $this->createActivity($run, sequence: 2, type: 'other-activity');
        $this->recordRetry($run, $other, [
            'retry_task_id' => (string) Str::ulid(),
            'retry_after_attempt' => 8,
            'retry_available_at' => now()
                ->addHour()
                ->toJSON(),
            'retry_backoff_seconds' => 3600,
            'max_attempts' => 10,
        ]);
        // Durable scheduling evidence remains authoritative over mutable drift.
        $activity->forceFill([
            'retry_policy' => [
                'max_attempts' => 99,
            ],
        ])->save();
        $rows = $this->readRows($run);
        $row = collect($rows)
            ->firstWhere('id', 'missing:activity:' . $activity->id);

        $this->assertIsArray($row);
        $this->assertSame('Activity retry 3 task missing for send-greeting.', $row['summary']);
        $this->assertSame($retryTaskId, $row['expected_task_id']);
        $this->assertSame($activity->id, $row['activity_execution_id']);
        $this->assertSame($closedTask->id, $row['retry_of_task_id']);
        $this->assertSame($previousAttemptId, $row['retry_after_attempt_id']);
        $this->assertSame(2, $row['retry_after_attempt']);
        $this->assertSame(2, $row['attempt_count']);
        $this->assertSame(7, $row['retry_backoff_seconds']);
        $this->assertSame(4, $row['retry_max_attempts']);
        $this->assertSame(self::POLICY, $row['retry_policy']);
        $this->assertTrue($row['available_at']->equalTo(now()->addSeconds(7)));
        $this->assertSame('activities', $row['queue']);
        $this->assertMissingTransport($row);
        $this->assertSame('missing:activity:' . $activity->id, $rows[0]['id']);
        $this->assertSame('missing:activity:' . $other->id, $rows[1]['id']);
        $this->assertSame($closedTask->id, $rows[2]['id']);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
    }

    public static function policyCases(): array
    {
        return [
            'explicit retry policy' => [true],
            'recorded activity policy fallback' => [false],
        ];
    }

    #[DataProvider('routeCases')]
    public function testMissingInitialTaskRetainsItsScheduleAndRoute(bool $dedicatedRoute): void
    {
        $run = $this->createRun();
        $activity = $this->createActivity($run, dedicatedRoute: $dedicatedRoute, attempts: 0);
        $row = collect($this->readRows($run))
            ->firstWhere('id', 'missing:activity:' . $activity->id);

        $this->assertIsArray($row);
        $this->assertSame('Activity task missing for send-greeting.', $row['summary']);
        $this->assertSame($activity->id, $row['activity_execution_id']);
        $this->assertSame('send-greeting', $row['activity_type']);
        $this->assertSame(TestGreetingActivity::class, $row['activity_class']);
        $this->assertNull($row['expected_task_id']);
        $this->assertNull($row['retry_after_attempt']);
        $this->assertSame(0, $row['attempt_count']);
        $this->assertSame(self::POLICY, $row['retry_policy']);
        $this->assertTrue($row['available_at']->equalTo(now()));
        $this->assertSame('redis', $row['connection']);
        $this->assertSame($dedicatedRoute ? 'activities' : 'workflows', $row['queue']);
        $this->assertMissingTransport($row);
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
    }

    public static function routeCases(): array
    {
        return [
            'activity route' => [true],
            'inherited run route' => [false],
        ];
    }

    public function testAnOpenRetryTaskClearsTheMissingTransportDiagnosis(): void
    {
        $run = $this->createRun();
        $activity = $this->createActivity($run);
        $retryTaskId = (string) Str::ulid();
        $payload = [
            'activity_execution_id' => $activity->id,
            'retry_after_attempt' => 2,
        ];
        $this->recordRetry($run, $activity, [
            'retry_task_id' => $retryTaskId,
            'retry_after_attempt' => 2,
            'retry_available_at' => now()
                ->addSeconds(7)
                ->toJSON(),
        ]);
        $missing = collect($this->readRows($run))
            ->firstWhere('id', 'missing:activity:' . $activity->id);
        $this->assertSame($retryTaskId, $missing['expected_task_id']);

        $task = $this->createTask($run, $payload, scheduled: true, id: $retryTaskId);
        $rows = $this->readRows($run);
        $this->assertCount(1, $rows);
        $this->assertSame($task->id, $rows[0]['id']);
        $this->assertFalse($rows[0]['task_missing']);
        $this->assertFalse($rows[0]['synthetic']);
        $this->assertSame('scheduled', $rows[0]['transport_state']);
        $this->assertSame(
            'Activity retry 3 for send-greeting scheduled for 2026-10-07T12:00:07.000000Z.',
            $rows[0]['summary'],
        );
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
    }

    private function createRun(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'activity-task-view-' . strtolower((string) Str::ulid()),
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'greeting',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'greeting',
            'namespace' => 'activity-task-view',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize(['Taylor']),
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'workflows',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

    private function createActivity(
        WorkflowRun $run,
        int $sequence = 1,
        string $type = 'send-greeting',
        bool $dedicatedRoute = true,
        int $attempts = 2,
    ): ActivityExecution {
        $activity = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => $type,
            'status' => ActivityStatus::Pending->value,
            'payload_codec' => 'avro',
            'arguments' => Serializer::serialize(['Taylor']),
            'attempt_count' => $attempts,
            'retry_policy' => self::POLICY,
            'connection' => $dedicatedRoute ? 'redis' : null,
            'queue' => $dedicatedRoute ? 'activities' : null,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $activity->id,
            'sequence' => $sequence,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ]);

        return $activity;
    }

    private function createTask(
        WorkflowRun $run,
        array $payload,
        TaskStatus $status = TaskStatus::Ready,
        bool $scheduled = false,
        ?string $id = null,
    ): WorkflowTask {
        return WorkflowTask::query()->create([
            'id' => $id ?? (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => $status->value,
            'payload' => $payload,
            'compatibility' => 'build-a',
            'connection' => 'redis',
            'queue' => 'activities',
            'available_at' => $scheduled ? now()
                ->addSeconds(7) : now(),
            'last_dispatched_at' => now(),
            'lease_owner' => $status === TaskStatus::Leased ? 'activity-worker' : null,
            'lease_expires_at' => $status === TaskStatus::Leased ? now()->addMinute() : null,
        ]);
    }

    private function recordRetry(WorkflowRun $run, ActivityExecution $activity, array $payload): void
    {
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, $payload + [
            'activity_execution_id' => $activity->id,
            'sequence' => $activity->sequence,
            'activity' => ActivitySnapshot::fromExecution($activity),
        ]);
    }

    private function assertMissingTransport(array $row): void
    {
        $this->assertSame('missing', $row['status']);
        $this->assertSame('missing', $row['transport_state']);
        $this->assertTrue($row['task_missing']);
        $this->assertTrue($row['synthetic']);
        $this->assertFalse($row['is_open']);
        $this->assertSame(0, $row['repair_count']);
        $this->assertSame(0, $row['repair_backoff_seconds']);
        foreach ([
            'lease_owner',
            'lease_expires_at',
            'repair_available_at',
            'last_dispatch_error',
            'last_claim_error',
        ] as $field) {
            $this->assertNull($row[$field]);
        }
    }

    private function readRows(WorkflowRun $run): array
    {
        // Prepare the embedded read-side projection before observing diagnostics.
        RunWaitProjector::project($run->fresh());
        $before = $this->runtimeRecords($run);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $rows = RunTaskView::forRun($run->fresh());
            $this->assertEquals($rows, RunTaskView::forRun($run->fresh()));
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^\s*(?:insert|update|delete|replace|truncate|alter|create|drop)\b/i',
                    $query['query'],
                );
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($before, $this->runtimeRecords($run));

        return $rows;
    }

    private function runtimeRecords(WorkflowRun $run): array
    {
        $records = [
            'run' => $run->fresh()->getRawOriginal(),
        ];
        foreach ([WorkflowHistoryEvent::class, ActivityExecution::class, WorkflowTask::class] as $model) {
            $records[$model] = $model::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()
                ->map(static fn ($record): array => $record->getRawOriginal())
                ->all();
        }

        return $records;
    }
}
