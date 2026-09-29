<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Enums\ParentClosePolicy;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowChildProjectionRepair;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowRunTimerEntry;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;

final class V2WorkflowRunRetentionCleanupTest extends TestCase
{
    public function testPruningMarkerPreservesUtcInstantAcrossApplicationTimezones(): void
    {
        $originalTimezone = date_default_timezone_get();
        $originalAppTimezone = config('app.timezone');
        $instants = [
            '2026-01-15T12:00:00Z',
            '2026-07-15T12:00:00Z',
            '2026-03-29T00:59:59Z',
            '2026-03-29T01:00:01Z',
            '2026-10-25T00:59:59Z',
            '2026-10-25T01:00:01Z',
        ];

        try {
            foreach (['UTC', 'Europe/Kyiv'] as $timezone) {
                config()->set('app.timezone', $timezone);
                date_default_timezone_set($timezone);

                foreach ($instants as $instant) {
                    $expected = Carbon::parse($instant, 'UTC');
                    Carbon::setTestNow($expected);
                    $run = $this->seedRun(status: RunStatus::Completed, closed: true);
                    $run->forceFill([
                        'archived_at' => $expected->copy()
                            ->subMinute(),
                    ])->save();
                    $rawArchivedAt = DB::table('workflow_runs')->where('id', $run->id)->value('archived_at');

                    WorkflowRunRetentionCleanup::pruneRun($run->id);

                    $raw = DB::table('workflow_runs')->where('id', $run->id)->value('details_pruned_at');
                    $hydrated = WorkflowRun::query()->findOrFail($run->id);
                    $this->assertSame(
                        $expected->getTimestamp(),
                        Carbon::parse($raw, 'UTC')->getTimestamp(),
                        $timezone . ' ' . $instant . ' raw'
                    );
                    $this->assertSame(
                        $expected->getTimestamp(),
                        $hydrated->details_pruned_at?->getTimestamp(),
                        $timezone . ' ' . $instant . ' hydrated'
                    );
                    $this->assertSame(RunStatus::Completed, $hydrated->status);
                    $this->assertSame(
                        $rawArchivedAt,
                        DB::table('workflow_runs')->where('id', $run->id)->value('archived_at')
                    );

                    Carbon::setTestNow($expected->copy()->addHour());
                    WorkflowRunRetentionCleanup::pruneRun($run->id);
                    $this->assertSame(
                        $raw,
                        DB::table('workflow_runs')->where('id', $run->id)->value('details_pruned_at')
                    );
                    $this->assertSame(
                        $expected->getTimestamp(),
                        WorkflowRun::query()->findOrFail($run->id)->details_pruned_at?->getTimestamp()
                    );
                    $this->assertSame(
                        $rawArchivedAt,
                        DB::table('workflow_runs')->where('id', $run->id)->value('archived_at')
                    );
                }
            }
        } finally {
            Carbon::setTestNow();
            config()
                ->set('app.timezone', $originalAppTimezone);
            date_default_timezone_set($originalTimezone);
        }
    }

    public function testPrunesRetainedRowsForClosedRunAndLeavesRunTombstone(): void
    {
        $run = $this->seedRun(status: RunStatus::Completed, closed: true);
        $otherRun = $this->seedRun(status: RunStatus::Completed, closed: true);

        $this->seedRetainedRows($run, $otherRun);
        $this->seedTask($otherRun);

        $report = WorkflowRunRetentionCleanup::pruneRun($run);

        $this->assertSame(1, $report['activity_attempts_deleted']);
        $this->assertSame(1, $report['activity_executions_deleted']);
        $this->assertSame(1, $report['child_calls_deleted']);
        $this->assertSame(1, $report['child_projection_repairs_deleted']);
        $this->assertSame(1, $report['commands_deleted']);
        $this->assertSame(1, $report['failures_deleted']);
        $this->assertSame(1, $report['history_events_deleted']);
        $this->assertSame(2, $report['links_deleted']);
        $this->assertSame(1, $report['memos_deleted']);
        $this->assertSame(1, $report['messages_deleted']);
        $this->assertSame(1, $report['run_lineage_entries_deleted']);
        $this->assertSame(1, $report['run_summary_deleted']);
        $this->assertSame(1, $report['run_timer_entries_deleted']);
        $this->assertSame(1, $report['run_waits_deleted']);
        $this->assertSame(1, $report['search_attributes_deleted']);
        $this->assertSame(1, $report['signals_deleted']);
        $this->assertSame(1, $report['tasks_deleted']);
        $this->assertSame(1, $report['timers_deleted']);
        $this->assertSame(1, $report['timeline_entries_deleted']);
        $this->assertSame(1, $report['updates_deleted']);
        $this->assertSame(0, $report['run_deleted']);

        $this->assertDatabaseHas('workflow_runs', [
            'id' => $run->id,
        ]);
        $this->assertNotNull(WorkflowRun::query()->findOrFail($run->id)->details_pruned_at);
        $this->assertDatabaseMissing('workflow_run_summaries', [
            'id' => $run->id,
        ]);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        $this->assertDatabaseHas('workflow_runs', [
            'id' => $otherRun->id,
        ]);
        $this->assertNull(WorkflowRun::query()->findOrFail($otherRun->id)->details_pruned_at);
        $this->assertDatabaseHas('workflow_tasks', [
            'workflow_run_id' => $otherRun->id,
        ]);
        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $otherRun->id,
        ]);
    }

    public function testFailedAndEmptyRetainedRunsExposeOnlyCommittedPruning(): void
    {
        $failedRun = $this->seedRun(status: RunStatus::Failed, closed: true);
        $emptyRun = $this->seedRun(status: RunStatus::Completed, closed: true);
        $this->seedRetainedRows($failedRun, $emptyRun);

        config()
            ->set('workflows.v2.run_model', ConfiguredRetentionWorkflowRun::class);

        $this->assertNull(ConfiguredRetentionWorkflowRun::query()->findOrFail($failedRun->id)->details_pruned_at);
        $this->assertNull(ConfiguredRetentionWorkflowRun::query()->findOrFail($emptyRun->id)->details_pruned_at);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $emptyRun->id)->count());

        WorkflowRunRetentionCleanup::pruneRun($failedRun->id);

        $prunedRun = ConfiguredRetentionWorkflowRun::query()->findOrFail($failedRun->id);
        $firstPrunedAt = $prunedRun->details_pruned_at;
        $this->assertNotNull($firstPrunedAt);
        $this->assertSame(RunStatus::Failed, $prunedRun->status);
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $failedRun->id)->count());
        $this->assertSame(0, WorkflowFailure::query()->where('workflow_run_id', $failedRun->id)->count());

        $repeatReport = WorkflowRunRetentionCleanup::pruneRun($failedRun->id);
        $this->assertSame(0, array_sum($repeatReport));
        $this->assertEquals(
            $firstPrunedAt,
            ConfiguredRetentionWorkflowRun::query()->findOrFail($failedRun->id)->details_pruned_at
        );
        $this->assertNull(ConfiguredRetentionWorkflowRun::query()->findOrFail($emptyRun->id)->details_pruned_at);
    }

    public function testFailedMarkerWriteRollsBackDetailDeletionOnConfiguredStorageConnection(): void
    {
        $run = $this->seedRun(status: RunStatus::Completed, closed: true);
        $this->seedTask($run);

        $defaultConnection = (string) config('database.default');
        config()
            ->set('database.connections.retention_alternate', config('database.connections.' . $defaultConnection));
        config()
            ->set('workflows.storage.connection', 'retention_alternate');
        config()
            ->set('workflows.v2.run_model', FailingRetentionWorkflowRun::class);

        try {
            WorkflowRunRetentionCleanup::pruneRun($run->id);
            $this->fail('The configured marker write should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('marker write failed', $exception->getMessage());
        }

        $this->assertNull(FailingRetentionWorkflowRun::query()->findOrFail($run->id)->details_pruned_at);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        $this->assertNotNull(WorkflowRunSummary::query()->find($run->id));
        $this->assertSame(0, DB::connection('retention_alternate')->transactionLevel());
    }

    public function testRejectsOpenRunsWithoutDeletingRetainedRows(): void
    {
        $run = $this->seedRun(status: RunStatus::Running, closed: false);
        $this->seedTask($run);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not terminal');

        try {
            WorkflowRunRetentionCleanup::pruneRun($run->id);
        } finally {
            $this->assertDatabaseHas('workflow_runs', [
                'id' => $run->id,
            ]);
            $this->assertNull(WorkflowRun::query()->findOrFail($run->id)->details_pruned_at);
            $this->assertDatabaseHas('workflow_tasks', [
                'workflow_run_id' => $run->id,
            ]);
            $this->assertDatabaseHas('workflow_run_summaries', [
                'id' => $run->id,
            ]);
        }
    }

    public function testRejectsTerminalRunsWithoutClosedTimestamp(): void
    {
        $run = $this->seedRun(status: RunStatus::Completed, closed: false);
        $this->seedTask($run);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has not been closed');

        try {
            WorkflowRunRetentionCleanup::pruneRun($run);
        } finally {
            $this->assertNull(WorkflowRun::query()->findOrFail($run->id)->details_pruned_at);
            $this->assertDatabaseHas('workflow_tasks', [
                'workflow_run_id' => $run->id,
            ]);
            $this->assertDatabaseHas('workflow_run_summaries', [
                'id' => $run->id,
            ]);
        }
    }

    private function seedRun(RunStatus $status, bool $closed): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => 'Tests\\Fixtures\\RetentionWorkflow',
            'workflow_type' => 'workflow.retention',
            'business_key' => null,
            'namespace' => 'default',
            'run_count' => 1,
        ]);

        $closedAt = null;
        if ($closed) {
            $closedAt = now()
                ->subMinute();
        }

        $statusBucket = match ($status) {
            RunStatus::Completed => 'completed',
            RunStatus::Cancelled, RunStatus::Failed, RunStatus::Terminated => 'failed',
            default => 'running',
        };

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $instance->workflow_class,
            'workflow_type' => $instance->workflow_type,
            'namespace' => 'default',
            'status' => $status->value,
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => now()
                ->subHour(),
            'closed_at' => $closedAt,
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'namespace' => 'default',
            'status' => $status->value,
            'status_bucket' => $statusBucket,
            'connection' => 'sync',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
        ]);

        return $run;
    }

    private function seedRetainedRows(WorkflowRun $run, WorkflowRun $otherRun): void
    {
        $task = $this->seedTask($run);
        $command = WorkflowCommand::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'requested_workflow_run_id' => $run->id,
            'resolved_workflow_run_id' => $run->id,
            'command_type' => CommandType::Update->value,
            'target_scope' => 'run',
            'status' => CommandStatus::Accepted->value,
            'accepted_at' => now()
                ->subMinutes(50),
            'applied_at' => now()
                ->subMinutes(49),
        ]);

        WorkflowUpdate::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'requested_workflow_run_id' => $run->id,
            'resolved_workflow_run_id' => $run->id,
            'update_name' => 'approve',
            'status' => UpdateStatus::Completed->value,
            'command_sequence' => 1,
            'accepted_at' => now()
                ->subMinutes(50),
            'closed_at' => now()
                ->subMinutes(49),
        ]);

        WorkflowSignal::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_command_id' => (string) Str::ulid(),
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'requested_workflow_run_id' => $run->id,
            'resolved_workflow_run_id' => $run->id,
            'signal_name' => 'nudge',
            'status' => SignalStatus::Applied->value,
            'command_sequence' => 2,
            'received_at' => now()
                ->subMinutes(48),
            'closed_at' => now()
                ->subMinutes(47),
        ]);

        $historyEvent = WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
            'payload' => [],
            'workflow_task_id' => $task->id,
            'workflow_command_id' => $command->id,
            'recorded_at' => now()
                ->subMinute(),
        ]);
        WorkflowChildProjectionRepair::query()->create([
            'workflow_history_event_id' => $historyEvent->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $task->id,
            'history_sequence' => $historyEvent->sequence,
        ]);

        $execution = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'Tests\\Fixtures\\RetentionActivity',
            'activity_type' => 'activity.retention',
            'status' => ActivityStatus::Completed->value,
            'attempt_count' => 1,
            'started_at' => now()
                ->subMinutes(45),
            'closed_at' => now()
                ->subMinutes(44),
        ]);
        ActivityAttempt::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => ActivityAttemptStatus::Completed->value,
            'started_at' => now()
                ->subMinutes(45),
            'closed_at' => now()
                ->subMinutes(44),
        ]);

        WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => 5,
            'fire_at' => now()
                ->subMinutes(40),
            'fired_at' => now()
                ->subMinutes(40),
        ]);
        WorkflowFailure::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow',
            'source_id' => $run->id,
            'propagation_kind' => 'root',
            'exception_class' => LogicException::class,
            'message' => 'retained failure',
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
        WorkflowRunWait::query()->create([
            'id' => hash('sha256', $run->id . '|wait'),
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'wait_id' => 'signal:nudge',
            'position' => 1,
            'kind' => 'signal',
            'status' => 'resolved',
            'opened_at' => now()
                ->subMinutes(30),
            'resolved_at' => now()
                ->subMinutes(29),
        ]);
        WorkflowTimelineEntry::query()->create([
            'id' => hash('sha256', $run->id . '|timeline'),
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'history_event_id' => (string) Str::ulid(),
            'sequence' => 1,
            'type' => 'WorkflowCompleted',
            'kind' => 'workflow',
            'entry_kind' => 'point',
            'recorded_at' => now()
                ->subMinute(),
            'payload' => [],
        ]);
        WorkflowRunTimerEntry::query()->create([
            'id' => hash('sha256', $run->id . '|timer'),
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'timer_id' => 'timer-1',
            'position' => 1,
            'sequence' => 2,
            'status' => TimerStatus::Fired->value,
            'delay_seconds' => 5,
            'fire_at' => now()
                ->subMinutes(40),
            'fired_at' => now()
                ->subMinutes(40),
            'payload' => [],
        ]);
        WorkflowRunLineageEntry::query()->create([
            'id' => hash('sha256', $run->id . '|lineage'),
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'direction' => 'child',
            'lineage_id' => 'child:' . $otherRun->id,
            'position' => 1,
            'link_type' => 'child',
            'sequence' => 3,
            'related_workflow_instance_id' => $otherRun->workflow_instance_id,
            'related_workflow_run_id' => $otherRun->id,
            'related_run_number' => 1,
            'related_workflow_type' => $otherRun->workflow_type,
            'related_workflow_class' => $otherRun->workflow_class,
            'status' => RunStatus::Completed->value,
            'status_bucket' => 'completed',
            'linked_at' => now()
                ->subMinutes(35),
            'payload' => [],
        ]);
        WorkflowMemo::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'customer_note',
            'value' => [
                'value' => 'retain until prune',
            ],
            'upserted_at_sequence' => 1,
        ]);
        WorkflowSearchAttribute::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'customer_id',
            'type' => WorkflowSearchAttribute::TYPE_KEYWORD,
            'value_keyword' => 'cust-retain',
            'upserted_at_sequence' => 1,
        ]);
        WorkflowMessage::query()->create([
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'direction' => MessageDirection::Inbound->value,
            'channel' => 'signal',
            'stream_key' => 'signal:nudge',
            'sequence' => 1,
            'source_workflow_instance_id' => $otherRun->workflow_instance_id,
            'source_workflow_run_id' => $otherRun->id,
            'target_workflow_instance_id' => $run->workflow_instance_id,
            'target_workflow_run_id' => $run->id,
            'consume_state' => MessageConsumeState::Consumed->value,
            'consumed_at' => now()
                ->subMinutes(20),
        ]);
        WorkflowChildCall::query()->create([
            'parent_workflow_run_id' => $run->id,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'sequence' => 3,
            'child_workflow_type' => $otherRun->workflow_type,
            'child_workflow_class' => $otherRun->workflow_class,
            'resolved_child_instance_id' => $otherRun->workflow_instance_id,
            'resolved_child_run_id' => $otherRun->id,
            'parent_close_policy' => ParentClosePolicy::Abandon->value,
            'status' => ChildCallStatus::Completed->value,
            'scheduled_at' => now()
                ->subMinutes(36),
            'started_at' => now()
                ->subMinutes(35),
            'closed_at' => now()
                ->subMinutes(34),
        ]);
        WorkflowLink::query()->create([
            'id' => (string) Str::ulid(),
            'link_type' => 'child',
            'sequence' => 3,
            'parent_workflow_instance_id' => $run->workflow_instance_id,
            'parent_workflow_run_id' => $run->id,
            'child_workflow_instance_id' => $otherRun->workflow_instance_id,
            'child_workflow_run_id' => $otherRun->id,
            'is_primary_parent' => true,
            'parent_close_policy' => ParentClosePolicy::Abandon->value,
        ]);
        WorkflowLink::query()->create([
            'id' => (string) Str::ulid(),
            'link_type' => 'parent',
            'sequence' => 4,
            'parent_workflow_instance_id' => $otherRun->workflow_instance_id,
            'parent_workflow_run_id' => $otherRun->id,
            'child_workflow_instance_id' => $run->workflow_instance_id,
            'child_workflow_run_id' => $run->id,
            'is_primary_parent' => false,
            'parent_close_policy' => ParentClosePolicy::Abandon->value,
        ]);
    }

    private function seedTask(WorkflowRun $run): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'default',
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Completed->value,
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
            'available_at' => now()
                ->subHour(),
        ]);

        return $task;
    }
}

final class ConfiguredRetentionWorkflowRun extends WorkflowRun
{
}

final class FailingRetentionWorkflowRun extends WorkflowRun
{
    public function save(array $options = []): bool
    {
        if ($this->details_pruned_at !== null) {
            throw new RuntimeException('marker write failed');
        }

        return parent::save($options);
    }
}
