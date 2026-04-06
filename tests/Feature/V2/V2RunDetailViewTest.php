<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnChildWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
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
        $this->assertSame('selected_run', $detail['activities_scope']);
        $this->assertSame('selected_run', $detail['commands_scope']);
        $this->assertSame('selected_run', $detail['waits_scope']);
        $this->assertSame('selected_run', $detail['tasks_scope']);
        $this->assertSame('selected_run', $detail['timeline_scope']);
        $this->assertSame('selected_run', $detail['lineage_scope']);
        $this->assertTrue($detail['can_issue_terminal_commands']);
        $this->assertNull($detail['read_only_reason']);
        $this->assertSame(1, $detail['commands'][0]['sequence']);
        $this->assertSame('start', $detail['commands'][0]['type']);
        $this->assertSame('started_new', $detail['commands'][0]['outcome']);
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');
        $this->assertSame('open', $signalWait['status']);
        $this->assertSame('waiting', $signalWait['source_status']);
        $this->assertSame('Waiting for signal name-provided.', $signalWait['summary']);
        $this->assertFalse($signalWait['task_backed']);
        $this->assertTrue($signalWait['external_only']);
        $this->assertSame('signal', $signalWait['resume_source_kind']);
        $this->assertNull($signalWait['resume_source_id']);
        $workflowTask = $this->findTask($detail['tasks'], 'workflow');
        $this->assertSame('completed', $workflowTask['status']);
        $this->assertFalse($workflowTask['is_open']);
        $this->assertSame(1, $detail['timeline'][0]['command_sequence']);
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
        $this->assertSame(1, $detail['commands'][0]['sequence']);
        $this->assertSame('start', $detail['commands'][0]['type']);
        $this->assertSame(2, $detail['commands'][1]['sequence']);
        $this->assertSame('signal', $detail['commands'][1]['type']);
        $this->assertSame('name-provided', $detail['commands'][1]['target_name']);
        $this->assertSame('signal_received', $detail['commands'][1]['outcome']);
        $this->assertCount(1, $detail['activities']);
        $this->assertSame('completed', $detail['activities'][0]['status']);
        $this->assertNotNull($detail['activities'][0]['created_at']);
        $this->assertSame('Hello, Taylor!', unserialize($detail['activities'][0]['result']));
        $signalWait = $this->findWait($detail['waits'], 'signal', 'name-provided');
        $this->assertSame('resolved', $signalWait['status']);
        $this->assertSame('received', $signalWait['source_status']);
        $this->assertSame('Signal name-provided received.', $signalWait['summary']);
        $this->assertSame(2, $signalWait['command_sequence']);
        $this->assertSame('accepted', $signalWait['command_status']);
        $this->assertSame('signal_received', $signalWait['command_outcome']);
        $activityWait = $this->findWait($detail['waits'], 'activity');
        $this->assertSame('resolved', $activityWait['status']);
        $this->assertSame('completed', $activityWait['source_status']);
        $this->assertSame('Activity '.\Tests\Fixtures\V2\TestGreetingActivity::class.' completed.', $activityWait['summary']);
        $this->assertTrue($activityWait['task_backed']);
        $this->assertSame('activity', $activityWait['task_type']);
        $this->assertSame('completed', $activityWait['task_status']);
        $activityTask = $this->findTask($detail['tasks'], 'activity');
        $this->assertSame('completed', $activityTask['status']);
        $this->assertSame(\Tests\Fixtures\V2\TestGreetingActivity::class, $activityTask['activity_type']);
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
        $this->assertSame([1, 1, null, 2, 2, null, null, null], array_column($detail['timeline'], 'command_sequence'));
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

    public function testRunDetailViewIncludesContinueAsNewLineage(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'detail-continued');
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'detail-continued')
            ->orderBy('run_number')
            ->get();

        /** @var WorkflowRun $historicalRun */
        $historicalRun = $runs[0];
        /** @var WorkflowRun $currentRun */
        $currentRun = $runs[1];

        $historicalDetail = RunDetailView::forRun(
            $historicalRun->fresh(['summary', 'instance.currentRun.summary'])
        );
        $currentDetail = RunDetailView::forRun(
            $currentRun->fresh(['summary', 'instance.currentRun.summary'])
        );

        $this->assertSame('completed', $historicalDetail['status']);
        $this->assertSame('continued', $historicalDetail['closed_reason']);
        $this->assertFalse($historicalDetail['is_current_run']);
        $this->assertSame($currentRun->id, $historicalDetail['current_run_id']);
        $this->assertCount(0, $historicalDetail['parents']);
        $this->assertCount(1, $historicalDetail['continuedWorkflows']);
        $this->assertSame('continue_as_new', $historicalDetail['continuedWorkflows'][0]['link_type']);
        $this->assertSame($currentRun->id, $historicalDetail['continuedWorkflows'][0]['child_workflow_run_id']);
        $this->assertSame('completed', $historicalDetail['continuedWorkflows'][0]['status']);
        $this->assertSame('completed', $historicalDetail['continuedWorkflows'][0]['status_bucket']);
        $this->assertSame('WorkflowContinuedAsNew', $historicalDetail['timeline'][4]['type']);

        $this->assertTrue($currentDetail['is_current_run']);
        $this->assertSame($currentRun->id, $currentDetail['current_run_id']);
        $this->assertCount(1, $currentDetail['parents']);
        $this->assertCount(0, $currentDetail['continuedWorkflows']);
        $this->assertSame('continue_as_new', $currentDetail['parents'][0]['link_type']);
        $this->assertSame($historicalRun->id, $currentDetail['parents'][0]['parent_workflow_run_id']);
        $this->assertSame('completed', $currentDetail['parents'][0]['status']);
        $this->assertSame('completed', $currentDetail['parents'][0]['status_bucket']);
        $this->assertSame('completed', $currentDetail['status']);
        $this->assertSame('completed', $currentDetail['closed_reason']);
    }

    public function testRunDetailViewIncludesChildWaitAndLineageForParentRun(): void
    {
        $workflow = WorkflowStub::make(TestParentWaitingOnChildWorkflow::class, 'detail-child-parent');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'child');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);
        $childWait = $this->findWait($detail['waits'], 'child');

        $this->assertSame('waiting', $detail['status']);
        $this->assertSame('child', $detail['wait_kind']);
        $this->assertSame('waiting_for_child', $detail['liveness_state']);
        $this->assertSame('open', $childWait['status']);
        $this->assertSame('waiting', $childWait['source_status']);
        $this->assertStringStartsWith('Waiting for child workflow ', $childWait['summary']);
        $this->assertFalse($childWait['task_backed']);
        $this->assertFalse($childWait['external_only']);
        $this->assertSame('child_workflow_run', $childWait['resume_source_kind']);
        $this->assertNotNull($childWait['resume_source_id']);
        $this->assertCount(0, $detail['parents']);
        $this->assertCount(1, $detail['continuedWorkflows']);
        $this->assertSame('child_workflow', $detail['continuedWorkflows'][0]['link_type']);
        $this->assertSame(1, $detail['continuedWorkflows'][0]['sequence']);
        $this->assertSame('waiting', $detail['continuedWorkflows'][0]['status']);
        $this->assertSame('test-timer-workflow', $detail['continuedWorkflows'][0]['workflow_type']);
        $this->assertFalse($detail['can_repair']);
    }

    public function testRunDetailViewIncludesTaskBackedTimerWaitForSelectedRun(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-timer');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'timer');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($runId);

        $detail = RunDetailView::forRun($run);
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertSame('timer', $detail['wait_kind']);
        $this->assertSame('open', $timerWait['status']);
        $this->assertSame('pending', $timerWait['source_status']);
        $this->assertSame('Waiting for timer.', $timerWait['summary']);
        $this->assertTrue($timerWait['task_backed']);
        $this->assertFalse($timerWait['external_only']);
        $this->assertSame('timer', $timerWait['resume_source_kind']);
        $this->assertNotNull($timerWait['resume_source_id']);
        $this->assertNotNull($timerWait['task_id']);
        $this->assertSame('timer', $timerWait['task_type']);
        $this->assertSame('ready', $timerWait['task_status']);

        $timerTask = $this->findTask($detail['tasks'], 'timer');

        $this->assertTrue($timerTask['is_open']);
        $this->assertSame('ready', $timerTask['status']);
        $this->assertSame($timerWait['resume_source_id'], $timerTask['timer_id']);
        $this->assertSame(1, $timerTask['timer_sequence']);
        $this->assertNotNull($timerTask['timer_fire_at']);
    }

    public function testRunDetailViewMarksRepairNeededTimerWaitAsNotTaskBacked(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'detail-timer-repair');
        $workflow->start(60);
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'timer');

        WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Timer->value)
            ->delete();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        RunSummaryProjector::project($run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']));

        $detail = RunDetailView::forRun($run->fresh(['summary']));
        $timerWait = $this->findWait($detail['waits'], 'timer');

        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame('timer', $detail['wait_kind']);
        $this->assertFalse($timerWait['task_backed']);
        $this->assertNull($timerWait['task_id']);
        $this->assertNull($timerWait['task_type']);
        $this->assertNull($timerWait['task_status']);
        $this->assertNull($this->findTaskOrNull($detail['tasks'], 'timer'));
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

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    /**
     * @param list<array<string, mixed>> $waits
     * @return array<string, mixed>
     */
    private function findWait(array $waits, string $kind, ?string $targetName = null): array
    {
        foreach ($waits as $wait) {
            if (($wait['kind'] ?? null) !== $kind) {
                continue;
            }

            if ($targetName !== null && ($wait['target_name'] ?? null) !== $targetName) {
                continue;
            }

            return $wait;
        }

        $this->fail(sprintf('Did not find %s wait in detail payload.', $kind));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function findTask(array $tasks, string $type): array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        $this->fail(sprintf('Did not find %s task in detail payload.', $type));
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function findTaskOrNull(array $tasks, string $type): ?array
    {
        foreach ($tasks as $task) {
            if (($task['type'] ?? null) === $type) {
                return $task;
            }
        }

        return null;
    }
}
