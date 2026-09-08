<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestHandledFailureParentWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class V2ChildTerminalOutcomeTest extends TestCase
{
    public function testEmbeddedCompletedChildDoesNotPropagateItsHandledActivityFailure(): void
    {
        $this->completeRecoveredChild(serviceMode: false);
    }

    public function testServiceModeCompletedChildDoesNotPropagateItsHandledActivityFailure(): void
    {
        $this->completeRecoveredChild(serviceMode: true);
    }

    public function testParentNotificationFailureRetriesTheTaskWithoutChangingTheChildOutcome(): void
    {
        $this->completeRecoveredChild(serviceMode: false, interruptNotification: true);
    }

    public function testGenuineChildFailureUsesTheTerminalFailureNotAnEarlierHandledFailure(): void
    {
        [$parent, $childId] = $this->prepareRecoveredChild();
        $handledFailure = WorkflowFailure::query()->where('workflow_run_id', $childId)->sole();
        $task = $this->readyTask($childId);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'terminal-failure-worker')['claimed']);
        $completion = $bridge->complete($task->id, [[
            'type' => 'fail_workflow',
            'message' => 'Terminal failure after recovery',
            'exception_class' => RuntimeException::class,
        ]]);
        $this->assertTrue($completion['completed']);
        $terminalEvent = WorkflowHistoryEvent::query()->where('workflow_run_id', $childId)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)->sole();
        $resolution = WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->runId())
            ->where('event_type', HistoryEventType::ChildRunFailed->value)->sole();
        $this->assertNotSame($handledFailure->id, $resolution->payload['failure_id']);
        $this->assertSame($terminalEvent->payload['failure_id'], $resolution->payload['failure_id']);
        $this->assertSame('Terminal failure after recovery', $resolution->payload['message']);
        $this->runReadyTask($parent->runId());
        $this->assertTrue($parent->refresh()->failed());
    }

    private function completeRecoveredChild(bool $serviceMode, bool $interruptNotification = false): void
    {
        [$parent, $childId] = $this->prepareRecoveredChild();

        if ($interruptNotification) {
            $eventName = 'eloquent.creating: ' . WorkflowHistoryEvent::class;
            Event::listen($eventName, static function (WorkflowHistoryEvent $event): void {
                if ($event->event_type === HistoryEventType::ChildRunCompleted) {
                    throw new RuntimeException('Parent notification unavailable');
                }
            });
            try {
                $this->runReadyTask($childId);
                $this->fail('The task infrastructure error must remain visible.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Parent notification unavailable', $exception->getMessage());
            } finally {
                Event::forget($eventName);
            }
            $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($childId)->status);
            $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $childId)
                ->whereIn(
                    'event_type',
                    [HistoryEventType::WorkflowCompleted->value, HistoryEventType::WorkflowFailed->value]
                )
                ->count());
            $this->assertSame('repair_dispatched', WorkflowStub::loadRun($childId)->attemptRepair()->outcome());
        }

        if ($serviceMode) {
            $task = $this->readyTask($childId);
            $bridge = $this->app->make(WorkflowTaskBridge::class);
            $this->assertTrue($bridge->claimStatus($task->id, 'handled-failure-worker')['claimed']);
            $completion = $bridge->complete($task->id, [[
                'type' => 'complete_workflow',
                'result' => Avro::serialize('Hello, Recovered!'),
                'payload_codec' => 'avro',
            ]]);
            $this->assertTrue($completion['completed']);
        } else {
            $this->runReadyTask($childId);
        }

        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($childId)->status);
        $this->assertSame([HistoryEventType::WorkflowCompleted], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $childId)
            ->whereIn(
                'event_type',
                [HistoryEventType::WorkflowCompleted->value, HistoryEventType::WorkflowFailed->value]
            )
            ->orderBy('sequence')
            ->pluck('event_type')
            ->all());
        $resolution = WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->runId())
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)->sole();
        foreach ([
            'failure_id',
            'failure_category',
            'exception',
            'exception_type',
            'exception_class',
            'message',
            'code',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $resolution->payload);
        }
        $this->runReadyTask($parent->runId());
        $this->assertTrue($parent->refresh()->completed());
        $this->assertSame('Hello, Recovered!', $parent->output());
    }

    /**
     * @return array{WorkflowStub, string}
     */
    private function prepareRecoveredChild(): array
    {
        self::stopWorkers();
        Queue::fake();
        $parent = WorkflowStub::make(TestHandledFailureParentWorkflow::class);
        $parent->start();
        $this->runReadyTask($parent->runId());
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parent->runId())->sole();
        $childId = $link->child_workflow_run_id;

        $this->runReadyTask($childId);
        $this->runReadyTask($childId);
        $this->runReadyTask($childId);
        $this->runReadyTask($childId);
        $this->assertSame(1, WorkflowFailure::query()->where('workflow_run_id', $childId)
            ->where('handled', true)
            ->count());

        return [$parent, $childId];
    }

    private function readyTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready->value)->sole();
    }

    private function runReadyTask(string $runId): void
    {
        $task = $this->readyTask($runId);
        $job = $task->task_type === TaskType::Activity
            ? new RunActivityTask($task->id)
            : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}
