<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestHistoryReplayedChildFailureWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedChildWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedFailureWorkflow;
use Tests\Fixtures\V2\TestHistoryTimerReplayWorkflow;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\WorkflowStub;

final class V2QueryWorkflowTest extends TestCase
{
    public function testQueriesReplayCommittedHistoryAndForwardArguments(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-current');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->assertSame('waiting-for-name', $workflow->currentStage());
        $this->assertSame(1, $workflow->query('countEventsMatching', 'start'));
        $this->assertSame(0, $workflow->query('countEventsMatching', 'name:'));
    }

    public function testQueriesIgnorePendingAcceptedSignalsUntilTheyAreApplied(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-pending-signal');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $result = $workflow->attemptSignal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('waiting-for-name', $workflow->currentStage());
        $this->assertSame(0, $workflow->query('countEventsMatching', 'name:'));

        $this->drainReadyTasks();

        $this->assertSame('waiting-for-timer', $workflow->refresh()->currentStage());
        $this->assertSame(1, $workflow->query('countEventsMatching', 'name:'));
    }

    public function testQueriesCanTargetHistoricalSelectedRunsAcrossContinueAsNew(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, 'query-continue');
        $started = $workflow->start(0, 2);
        $firstRunId = $started->runId();

        $this->assertNotNull($firstRunId);

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(2, $workflow->currentCount());

        $historical = WorkflowStub::loadRun($firstRunId);

        $this->assertSame(1, $historical->currentCount());
    }

    public function testQueriesAndResumeUseTypedActivityFailureHistoryWhenMutableRowsDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedFailureWorkflow::class, 'query-history-failure');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $expectedState = [
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => \Tests\Fixtures\V2\TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
                'order_id' => 'order-123',
                'channel' => 'api',
            ],
        ];

        $this->assertSame($expectedState, $workflow->currentState());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', 'ActivityFailed')
            ->firstOrFail();

        $propertyPayloads = collect($event->payload['exception']['properties'] ?? [])
            ->keyBy('name');

        $this->assertSame(1, $event->payload['sequence'] ?? null);
        $this->assertSame('order-123', $propertyPayloads->get('orderId')['value'] ?? null);
        $this->assertSame('api', $propertyPayloads->get('channel')['value'] ?? null);

        DB::transaction(function () use ($execution, $failure): void {
            $execution->forceFill([
                'status' => ActivityStatus::Completed,
                'result' => Serializer::serialize('corrupted-result'),
                'exception' => null,
            ])->save();

            $failure->forceFill([
                'exception_class' => \RuntimeException::class,
                'message' => 'corrupted failure row',
            ])->save();
        });

        $this->assertSame($expectedState, $workflow->refresh()->currentState());

        $workflow->signal('resume', 'go');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => $expectedState['caught'],
            'resume' => 'go',
        ], $workflow->output());
    }

    public function testQueriesAndResumeUseTimerHistoryWhenTimerRowsDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryTimerReplayWorkflow::class, 'query-history-timer');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('after-timer', $workflow->currentStage());
        $this->assertSame(['started', 'timer-fired'], $workflow->currentEvents());

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $timer->forceFill([
            'status' => TimerStatus::Pending,
            'fired_at' => null,
        ])->save();

        $this->assertSame('after-timer', $workflow->refresh()->currentStage());
        $this->assertSame(['started', 'timer-fired'], $workflow->currentEvents());

        $workflow->signal('resume', 'ready');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'events' => ['started', 'timer-fired', 'signal:ready'],
        ], $workflow->output());
    }

    public function testQueriesUseTypedParentChildCompletionHistoryWhenChildRowsDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedChildWorkflow::class, 'query-history-child');
        $workflow->start('Taylor');

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $workflow->runId())
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $expectedState = [
            'stage' => 'completed',
            'child' => [
                'greeting' => 'Hello, Taylor!',
                'workflow_id' => $link->child_workflow_instance_id,
                'run_id' => $childRun->id,
            ],
        ];

        $this->assertSame($expectedState, $workflow->currentState());

        $childRun->forceFill([
            'status' => RunStatus::Failed,
            'closed_reason' => 'failed',
            'output' => Serializer::serialize(['greeting' => 'corrupted-child-output']),
            'closed_at' => now(),
        ])->save();

        $this->assertSame($expectedState, $workflow->refresh()->currentState());
    }

    public function testQueriesUseTypedParentChildFailureHistoryWhenChildRowsDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedChildFailureWorkflow::class, 'query-child-failure');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $workflow->runId())
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $expectedState = [
            'stage' => 'completed',
            'caught' => [
                'class' => \Tests\Fixtures\V2\TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
                'order_id' => 'order-123',
                'channel' => 'api',
            ],
        ];

        $this->assertSame($expectedState, $workflow->currentState());

        $childRun->forceFill([
            'status' => RunStatus::Completed,
            'closed_reason' => 'completed',
            'output' => Serializer::serialize('corrupted-child-result'),
            'closed_at' => now(),
        ])->save();

        $this->assertSame($expectedState, $workflow->refresh()->currentState());
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

            if ($task->available_at !== null && $task->available_at->isFuture()) {
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
}
