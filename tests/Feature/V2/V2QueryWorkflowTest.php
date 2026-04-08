<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestHistoryReplayedChildFailureWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedChildWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedFailureWorkflow;
use Tests\Fixtures\V2\TestHistoryTimerReplayWorkflow;
use Tests\Fixtures\V2\TestMixedParallelFailureWorkflow;
use Tests\Fixtures\V2\TestMixedParallelWorkflow;
use Tests\Fixtures\V2\TestParallelActivityFailureWorkflow;
use Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelChildFailureWorkflow;
use Tests\Fixtures\V2\TestParallelChildWorkflow;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\Fixtures\V2\TestSideEffectWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\WorkflowStub;

final class V2QueryWorkflowTest extends TestCase
{
    protected function tearDown(): void
    {
        TestSideEffectWorkflow::resetCounter();

        parent::tearDown();
    }

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

    public function testQueriesSupportAliasedTargetsNamedArgumentMapsAndDurableContracts(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-aliased-target');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $this->assertSame(1, $workflow->query('events-starting-with', 'start'));
        $this->assertSame(1, $workflow->queryWithArguments('events-starting-with', [
            'prefix' => 'start',
        ]));

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $contracts = collect($started->payload['declared_query_contracts'] ?? [])
            ->keyBy('name');

        $this->assertContains('events-starting-with', $started->payload['declared_queries'] ?? []);
        $this->assertSame('events-starting-with', $contracts->get('events-starting-with')['name'] ?? null);
        $this->assertSame('prefix', $contracts->get('events-starting-with')['parameters'][0]['name'] ?? null);
        $this->assertSame('string', $contracts->get('events-starting-with')['parameters'][0]['type'] ?? null);
    }

    public function testQueriesThrowExplicitExecutionUnavailableWhenWorkflowDefinitionCannotBeResolved(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestQueryWorkflow::class, 'query-definition-unavailable');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        WorkflowRun::query()->whereKey($workflow->runId())->update([
            'workflow_class' => 'Missing\\Workflow\\TestQueryWorkflow',
            'workflow_type' => 'missing-query-workflow',
        ]);

        try {
            $workflow->queryWithArguments('events-starting-with', [
                'prefix' => 'start',
            ]);

            $this->fail('Expected query execution to be blocked when the workflow definition is unavailable.');
        } catch (WorkflowExecutionUnavailableException $exception) {
            $this->assertSame('query', $exception->operation());
            $this->assertSame('events-starting-with', $exception->targetName());
            $this->assertSame('workflow_definition_unavailable', $exception->blockedReason());
            $this->assertSame(
                sprintf(
                    'Workflow %s [%s] cannot execute query [%s] because the workflow definition is unavailable for durable type [%s].',
                    $workflow->runId(),
                    $workflow->id(),
                    'events-starting-with',
                    'missing-query-workflow',
                ),
                $exception->getMessage(),
            );
        }
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

    public function testLoadResolvesLatestRunWhenCurrentRunPointerIsMissing(): void
    {
        Queue::fake();

        $instanceId = 'query-continue-pointer-drift';

        $workflow = WorkflowStub::make(TestQueryContinueAsNewWorkflow::class, $instanceId);
        $workflow->start(0, 1);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->orderByDesc('run_number')
            ->firstOrFail();

        WorkflowInstance::query()
            ->findOrFail($instanceId)
            ->forceFill(['current_run_id' => null])
            ->save();

        $resolved = WorkflowStub::load($instanceId);

        $this->assertSame($currentRun->id, $resolved->runId());
        $this->assertSame($currentRun->id, $resolved->currentRunId());
        $this->assertTrue($resolved->currentRunIsSelected());
        $this->assertSame(1, $resolved->currentCount());
    }

    public function testQueriesReuseRecordedSideEffectsWithoutReExecutingClosures(): void
    {
        Queue::fake();

        TestSideEffectWorkflow::resetCounter();

        $workflow = WorkflowStub::make(TestSideEffectWorkflow::class, 'query-side-effect');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('waiting-for-finish', $workflow->currentStage());
        $this->assertSame(1, $workflow->currentToken());
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        $this->assertSame(1, $workflow->query('currentToken'));
        $this->assertSame('waiting-for-finish', $workflow->query('currentStage'));
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());

        $workflow->signal('finish', 'done');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame(1, TestSideEffectWorkflow::sideEffectExecutions());
        $this->assertSame([
            'token' => 1,
            'finish' => 'done',
            'workflow_id' => 'query-side-effect',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
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

        DB::transaction(static function () use ($execution, $failure): void {
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
            'output' => Serializer::serialize([
                'greeting' => 'corrupted-child-output',
            ]),
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

    public function testQueriesKeepParallelChildAllWaitingUntilEveryChildCompletes(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelChildWorkflow::class, 'query-parallel-children');
        $workflow->start(0, 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $links);

        $firstChildRunId = $links[0]->child_workflow_run_id;

        $this->assertIsString($firstChildRunId);

        $this->runReadyTaskForRun($firstChildRunId, TaskType::Workflow);

        $this->assertSame([
            'stage' => 'waiting-for-children',
        ], $workflow->currentState());
    }

    public function testQueriesReplayParallelChildFailureBeforeParentWorkflowTaskRuns(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelChildFailureWorkflow::class, 'query-parallel-child-failure');
        $workflow->start(60);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $links);

        $failingChildRunId = $links[0]->child_workflow_run_id;

        $this->assertIsString($failingChildRunId);

        $this->runReadyTaskForRun($failingChildRunId, TaskType::Workflow);
        $this->runReadyTaskForRun($failingChildRunId, TaskType::Activity);
        $this->runReadyTaskForRun($failingChildRunId, TaskType::Workflow);

        $this->assertSame([
            'stage' => 'caught-child-failure',
            'message' => 'boom',
        ], $workflow->currentState());
    }

    public function testQueriesKeepParallelActivityAllWaitingUntilEveryActivityCompletes(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityWorkflow::class, 'query-parallel-activities');
        $workflow->start('Taylor', 'Abigail');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame([
            'stage' => 'waiting-for-activities',
        ], $workflow->currentState());
    }

    public function testQueriesReplayParallelActivityFailureBeforeParentWorkflowTaskRuns(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelActivityFailureWorkflow::class, 'query-parallel-activity-failure');
        $workflow->start('Taylor');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame([
            'stage' => 'caught-activity-failure',
            'message' => 'boom',
        ], $workflow->currentState());
    }

    public function testQueriesKeepMixedAllWaitingUntilEveryMemberCompletes(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelWorkflow::class, 'query-mixed-parallel');
        $workflow->start('Taylor', 0);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame([
            'stage' => 'waiting-for-mixed-group',
        ], $workflow->currentState());
    }

    public function testQueriesReplayMixedFailureBeforeParentWorkflowTaskRuns(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestMixedParallelFailureWorkflow::class, 'query-mixed-parallel-failure');
        $workflow->start(60);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyTaskForRun($parentRunId, TaskType::Activity);

        $this->assertSame([
            'stage' => 'caught-mixed-failure',
            'message' => 'boom',
        ], $workflow->currentState());
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

    private function runNextReadyTask(): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail('Expected a ready workflow task.');
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }

    private function runReadyTaskForRun(string $runId, TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail(sprintf('Expected a ready %s task for run %s.', $taskType->value, $runId));
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
