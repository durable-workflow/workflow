<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2ArchiveWorkflowTest extends TestCase
{
    public function testArchiveMarksClosedRunAndRecordsAuditedHistory(): void
    {
        $run = $this->createRun('archive-terminal-run', '01JARCHIVEFLOWRUN00000001', 'completed');
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $result = WorkflowStub::loadRun($run->id)->attemptArchive('retention export complete');

        $this->assertTrue($result->accepted());
        $this->assertSame('archive', $result->type());
        $this->assertSame('archived', $result->outcome());
        $this->assertSame('run', $result->targetScope());
        $this->assertSame($run->id, $result->runId());

        $this->assertDatabaseHas('workflow_runs', [
            'id' => $run->id,
            'archive_command_id' => $result->commandId(),
            'archive_reason' => 'retention export complete',
        ]);

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $run->id,
            'archive_command_id' => $result->commandId(),
            'archive_reason' => 'retention export complete',
        ]);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'archive-terminal-run',
            'workflow_run_id' => $run->id,
            'command_type' => 'archive',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'archived',
        ]);

        $this->assertSame(['ArchiveRequested', 'WorkflowArchived'], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertNotNull($detail['archived_at']);
        $this->assertSame($result->commandId(), $detail['archive_command_id']);
        $this->assertSame('retention export complete', $detail['archive_reason']);
        $this->assertFalse($detail['can_archive']);
        $this->assertSame('run_archived', $detail['archive_blocked_reason']);
        $this->assertSame('Run is archived.', $detail['read_only_reason']);
        $this->assertSame('archive', $detail['commands'][0]['type']);
        $this->assertSame('archived', $detail['commands'][0]['outcome']);

        $timeline = HistoryTimeline::forRun($run->fresh());

        $this->assertSame('command', $timeline[0]['kind']);
        $this->assertSame('Archive requested.', $timeline[0]['summary']);
        $this->assertSame('Workflow archived.', $timeline[1]['summary']);

        $export = HistoryExport::forRun($run->fresh(['summary']));

        $this->assertSame($result->commandId(), $export['workflow']['archive_command_id']);
        $this->assertSame('retention export complete', $export['workflow']['archive_reason']);
        $this->assertSame($result->commandId(), $export['summary']['archive_command_id']);
        $this->assertSame(1, OperatorMetrics::snapshot()['runs']['archived']);
    }

    public function testArchiveRejectsOpenRun(): void
    {
        $run = $this->createRun('archive-open-run', '01JARCHIVEFLOWRUN00000002', 'waiting');
        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $result = WorkflowStub::loadRun($run->id)->attemptArchive('too early');

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_run_not_closed', $result->outcome());
        $this->assertSame('run_not_closed', $result->rejectionReason());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'archive-open-run',
            'workflow_run_id' => $run->id,
            'command_type' => 'archive',
            'status' => 'rejected',
            'outcome' => 'rejected_run_not_closed',
            'rejection_reason' => 'run_not_closed',
        ]);

        $this->assertNull(WorkflowRun::query()->findOrFail($run->id)->archived_at);
    }

    public function testArchiveCanTargetHistoricalClosedRunWithoutCurrentRunRedirection(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'archive-historical-run',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 2,
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'id' => '01JARCHIVEFLOWRUN00000003',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()
                ->subMinutes(10),
            'closed_at' => now()
                ->subMinutes(9),
            'last_progress_at' => now()
                ->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'id' => '01JARCHIVEFLOWRUN00000004',
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $result = WorkflowStub::loadSelection($instance->id, $historicalRun->id)->attemptArchive();

        $this->assertTrue($result->accepted());
        $this->assertSame('archived', $result->outcome());
        $this->assertSame($historicalRun->id, $result->runId());
        $this->assertSame($historicalRun->id, $result->requestedRunId());
        $this->assertSame($historicalRun->id, $result->resolvedRunId());

        $this->assertDatabaseHas('workflow_runs', [
            'id' => $historicalRun->id,
            'archive_command_id' => $result->commandId(),
        ]);
        $this->assertNull(WorkflowRun::query()->findOrFail($currentRun->id)->archived_at);

        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame('run', $command->target_scope);
        $this->assertSame($historicalRun->id, $command->requestedRunId());
        $this->assertSame($historicalRun->id, $command->resolvedRunId());
    }

    private function createRun(string $instanceId, string $runId, string $status): WorkflowRun
    {
        $isClosed = in_array($status, ['completed', 'failed', 'cancelled', 'terminated'], true);

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => $status,
            'closed_reason' => $isClosed ? $status : null,
            'arguments' => Serializer::serialize([]),
            'started_at' => now()
                ->subMinutes(5),
            'closed_at' => $isClosed ? now()
                ->subMinute() : null,
            'last_progress_at' => $isClosed ? now()
                ->subMinute() : now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
