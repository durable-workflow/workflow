<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2RunDetailViewTest extends TestCase
{
    public function testRunDetailViewIncludesWaitAndLivenessMetadataForSignalWaitingRun(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-signal');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);

        $this->assertSame($runId, $detail['id']);
        $this->assertSame('detail-signal', $detail['instance_id']);
        $this->assertSame($runId, $detail['selected_run_id']);
        $this->assertSame($runId, $detail['run_id']);
        $this->assertTrue($detail['is_current_run']);
        $this->assertSame($runId, $detail['current_run_id']);
        $this->assertSame('waiting', $detail['status']);
        $this->assertSame('running', $detail['status_bucket']);
        $this->assertSame('signal', $detail['wait_kind']);
        $this->assertSame('Waiting for signal name-provided', $detail['wait_reason']);
        $this->assertSame('waiting_for_signal', $detail['liveness_state']);
        $this->assertSame('Waiting for signal name-provided.', $detail['liveness_reason']);
        $this->assertTrue($detail['can_issue_terminal_commands']);
        $this->assertNull($detail['read_only_reason']);
        $this->assertSame('start', $detail['commands'][0]['type']);
        $this->assertSame('started_new', $detail['commands'][0]['outcome']);
        $this->assertSame('SignalWaitOpened', $detail['timeline'][2]['type']);
        $this->assertSame('signal', $detail['timeline'][2]['kind']);
        $this->assertSame('Waiting for signal name-provided.', $detail['timeline'][2]['summary']);
    }

    public function testRunDetailViewIncludesCommandsActivitiesAndTimelineForCompletedSignalRun(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'detail-signal-complete');
        $workflow->start();
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);

        $this->assertSame('completed', $detail['status']);
        $this->assertSame('completed', $detail['status_bucket']);
        $this->assertSame('completed', $detail['closed_reason']);
        $this->assertFalse($detail['can_issue_terminal_commands']);
        $this->assertSame('Run is closed.', $detail['read_only_reason']);
        $this->assertSame(0, $detail['exception_count']);
        $this->assertSame(0, $detail['exceptions_count']);
        $this->assertCount(2, $detail['commands']);
        $this->assertSame('start', $detail['commands'][0]['type']);
        $this->assertSame('signal', $detail['commands'][1]['type']);
        $this->assertSame('name-provided', $detail['commands'][1]['target_name']);
        $this->assertSame('signal_received', $detail['commands'][1]['outcome']);
        $this->assertCount(1, $detail['activities']);
        $this->assertSame('completed', $detail['activities'][0]['status']);
        $this->assertSame('Hello, Taylor!', unserialize($detail['activities'][0]['result']));
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'SignalApplied',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], array_column($detail['timeline'], 'type'));
    }

    public function testRunDetailViewIncludesCurrentRunPointerForHistoricalRun(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'detail-historical-instance',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 2,
            'reserved_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
            'run_count' => 2,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($historicalRun->fresh(['summary', 'instance.currentRun.summary']));

        $this->assertSame($historicalRun->id, $detail['selected_run_id']);
        $this->assertSame($historicalRun->id, $detail['run_id']);
        $this->assertFalse($detail['is_current_run']);
        $this->assertSame($currentRun->id, $detail['current_run_id']);
        $this->assertSame('waiting', $detail['current_run_status']);
        $this->assertSame('running', $detail['current_run_status_bucket']);
        $this->assertFalse($detail['can_issue_terminal_commands']);
        $this->assertSame(
            'Selected run is historical. Issue commands against the current active run.',
            $detail['read_only_reason'],
        );
    }

    private function waitFor(callable $condition): void
    {
        $startedAt = microtime(true);

        while ((microtime(true) - $startedAt) < 5) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Condition was not met within 5 seconds.');
    }
}
