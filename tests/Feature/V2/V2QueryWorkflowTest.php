<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAbstractReplayedException;
use Tests\Fixtures\V2\TestBroadChildFailureCatchWorkflow;
use Tests\Fixtures\V2\TestBroadFailureCatchWorkflow;
use Tests\Fixtures\V2\TestChildHandleParentWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedChildFailureWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedChildWorkflow;
use Tests\Fixtures\V2\TestHistoryReplayedFailureWorkflow;
use Tests\Fixtures\V2\TestHistoryTimerReplayWorkflow;
use Tests\Fixtures\V2\TestMixedParallelFailureWorkflow;
use Tests\Fixtures\V2\TestMixedParallelWorkflow;
use Tests\Fixtures\V2\TestNestedParallelActivityWorkflow;
use Tests\Fixtures\V2\TestPendingTimerSignalWorkflow;
use Tests\Fixtures\V2\TestParallelChildHandlesWorkflow;
use Tests\Fixtures\V2\TestParallelActivityFailureWorkflow;
use Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Tests\Fixtures\V2\TestParallelChildFailureWorkflow;
use Tests\Fixtures\V2\TestParallelChildWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnContinuingChildWorkflow;
use Tests\Fixtures\V2\TestParallelMultipleActivityFailureWorkflow;
use Tests\Fixtures\V2\TestQueryChildResolutionAuthorityWorkflow;
use Tests\Fixtures\V2\TestQueryContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\Fixtures\V2\TestReplayedDomainException;
use Tests\Fixtures\V2\TestSideEffectWorkflow;
use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
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
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
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

    public function testQueriesBackfillLegacyCommandContractsWhenWorkflowDefinitionIsLoadable(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');

        Queue::fake();

        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'query-legacy-command-contract');
        $workflow->start();

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $started->forceFill([
            'payload' => [
                'workflow_class' => TestUpdateWorkflow::class,
                'workflow_type' => 'test-update-workflow',
                'workflow_instance_id' => $workflow->id(),
                'workflow_run_id' => $workflow->runId(),
            ],
        ])->save();

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        $started = $started->fresh();
        $this->assertSame(['currentState'], $started->payload['declared_queries'] ?? null);
        $this->assertSame('currentState', $started->payload['declared_query_contracts'][0]['name'] ?? null);
        $this->assertSame(['name-provided'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('name-provided', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(['approve', 'explode'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('approve', $started->payload['declared_update_contracts'][0]['name'] ?? null);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId())->fresh());

        $this->assertSame('durable_history', $detail['declared_contract_source']);
        $this->assertFalse($detail['declared_contract_backfill_needed']);
        $this->assertFalse($detail['declared_contract_backfill_available']);
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

    public function testLoadPrefersContinueAsNewLineageWhenCurrentRunPointerIsMissing(): void
    {
        Queue::fake();
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');

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

        WorkflowRun::query()->create([
            'workflow_instance_id' => $instanceId,
            'run_number' => $currentRun->run_number + 1,
            'workflow_class' => $currentRun->workflow_class,
            'workflow_type' => $currentRun->workflow_type,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([999, 1000]),
            'connection' => $currentRun->connection,
            'queue' => $currentRun->queue,
            'started_at' => now()->addMinute(),
            'last_progress_at' => now()->addMinute(),
        ]);

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
        config()->set('queue.default', 'redis');
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

    public function testQueriesAndResumeRestoreActivityFailuresThroughDurableExceptionAliases(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('workflows.v2.types.exceptions.order-rejected', TestReplayedDomainException::class);
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedFailureWorkflow::class, 'query-history-failure-alias');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $payload = $event->payload;
        $this->assertSame('order-rejected', $payload['exception_type'] ?? null);
        $this->assertSame('order-rejected', $payload['exception']['type'] ?? null);
        $this->assertIsArray($payload['exception']['properties'] ?? null);
        $this->assertIsString($payload['failure_id'] ?? null);

        $payload['exception_class'] = 'App\\Legacy\\OrderRejected';
        $payload['exception']['class'] = 'App\\Legacy\\OrderRejected';

        foreach ($payload['exception']['properties'] as &$property) {
            $property['declaring_class'] = 'App\\Legacy\\OrderRejected';
        }

        unset($property);

        DB::transaction(static function () use ($event, $payload): void {
            $event->forceFill([
                'payload' => $payload,
            ])->save();

            WorkflowFailure::query()
                ->where('id', $payload['failure_id'])
                ->update([
                    'exception_class' => 'App\\Legacy\\OrderRejected',
                    'message' => 'legacy row drift',
                ]);
        });

        $expectedState = [
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
                'order_id' => 'order-123',
                'channel' => 'api',
            ],
        ];

        $this->assertSame($expectedState, $workflow->refresh()->currentState());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $exception = unserialize($detail['exceptions'][0]['exception']);
        $timelineFailure = collect($detail['timeline'])->firstWhere('type', HistoryEventType::ActivityFailed->value);

        $this->assertSame('order-rejected', $detail['exceptions'][0]['exception_type']);
        $this->assertSame(TestReplayedDomainException::class, $detail['exceptions'][0]['exception_resolved_class']);
        $this->assertSame('exception_type', $detail['exceptions'][0]['exception_resolution_source']);
        $this->assertSame('order-rejected', $exception['type'] ?? null);
        $this->assertSame('order-rejected', $timelineFailure['exception_type'] ?? null);
        $this->assertSame('order-rejected', $timelineFailure['failure']['exception_type'] ?? null);
        $this->assertSame(TestReplayedDomainException::class, $timelineFailure['exception_resolved_class'] ?? null);

        $workflow->signal('resume', 'go');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => $expectedState['caught'],
            'resume' => 'go',
        ], $workflow->output());
    }

    public function testQueriesAndResumeRestorePreAliasActivityFailuresThroughClassAliases(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('workflows.v2.types.exception_class_aliases', [
            'App\\Legacy\\OrderRejected' => TestReplayedDomainException::class,
        ]);
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedFailureWorkflow::class, 'query-history-failure-class-alias');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $payload = $event->payload;
        unset($payload['exception_type'], $payload['exception']['type']);
        $payload['exception_class'] = 'App\\Legacy\\OrderRejected';
        $payload['exception']['class'] = 'App\\Legacy\\OrderRejected';

        foreach ($payload['exception']['properties'] as &$property) {
            $property['declaring_class'] = 'App\\Legacy\\OrderRejected';
        }

        unset($property);

        DB::transaction(static function () use ($event, $payload): void {
            $event->forceFill([
                'payload' => $payload,
            ])->save();

            WorkflowFailure::query()
                ->where('id', $payload['failure_id'])
                ->update([
                    'exception_class' => 'App\\Legacy\\OrderRejected',
                    'message' => 'legacy row drift',
                ]);
        });

        $expectedState = [
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
                'order_id' => 'order-123',
                'channel' => 'api',
            ],
        ];

        $this->assertSame($expectedState, $workflow->refresh()->currentState());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $timelineFailure = collect($detail['timeline'])->firstWhere('type', HistoryEventType::ActivityFailed->value);

        $this->assertNull($detail['exceptions'][0]['exception_type']);
        $this->assertSame('App\\Legacy\\OrderRejected', $detail['exceptions'][0]['exception_class']);
        $this->assertSame(TestReplayedDomainException::class, $detail['exceptions'][0]['exception_resolved_class']);
        $this->assertSame('class_alias', $detail['exceptions'][0]['exception_resolution_source']);
        $this->assertSame(TestReplayedDomainException::class, $timelineFailure['exception_resolved_class'] ?? null);
        $this->assertSame('class_alias', $timelineFailure['failure']['exception_resolution_source'] ?? null);

        $workflow->signal('resume', 'go');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => $expectedState['caught'],
            'resume' => 'go',
        ], $workflow->output());
    }

    public function testUnmappedHistoricalFailureClassBlocksReplayInsteadOfFallingThroughBroadRuntimeCatch(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestBroadFailureCatchWorkflow::class, 'query-history-failure-unmapped');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $payload = $event->payload;
        unset($payload['exception_type'], $payload['exception']['type']);
        $payload['exception_class'] = 'App\\Legacy\\OrderRejected';
        $payload['exception']['class'] = 'App\\Legacy\\OrderRejected';

        DB::transaction(static function () use ($event, $payload): void {
            $event->forceFill([
                'payload' => $payload,
            ])->save();

            WorkflowFailure::query()
                ->where('id', $payload['failure_id'])
                ->update([
                    'exception_class' => 'App\\Legacy\\OrderRejected',
                    'message' => 'legacy row drift',
                ]);
        });

        try {
            $workflow->refresh()->currentState();
            $this->fail('Expected unresolved failure replay to block the query.');
        } catch (UnresolvedWorkflowFailureException $exception) {
            $this->assertSame('App\\Legacy\\OrderRejected', $exception->originalExceptionClass());
            $this->assertSame('unresolved', $exception->resolutionSource());
            $this->assertNull($exception->exceptionType());
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $timelineFailure = collect($detail['timeline'])->firstWhere('type', HistoryEventType::ActivityFailed->value);

        $this->assertSame('App\\Legacy\\OrderRejected', $detail['exceptions'][0]['exception_class']);
        $this->assertNull($detail['exceptions'][0]['exception_resolved_class']);
        $this->assertSame('unresolved', $detail['exceptions'][0]['exception_resolution_source']);
        $this->assertTrue($detail['exceptions'][0]['exception_replay_blocked']);
        $this->assertTrue($timelineFailure['failure']['exception_replay_blocked'] ?? false);

        $workflow->signal('resume', 'go');
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);
        $this->assertDatabaseHas('workflow_tasks', [
            'workflow_run_id' => $workflow->runId(),
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Failed->value,
        ]);

        config()->set('workflows.v2.types.exception_class_aliases', [
            'App\\Legacy\\OrderRejected' => TestReplayedDomainException::class,
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
        ], WorkflowStub::loadRun($workflow->runId())->currentState());

        $repair = WorkflowStub::loadRun($workflow->runId())->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
            'resume' => 'go',
        ], $workflow->output());
    }

    public function testMisconfiguredDurableFailureAliasBlocksReplayAndRecoveryUntilMappingIsFixed(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('workflows.v2.types.exceptions.order-rejected', TestReplayedDomainException::class);
        Queue::fake();

        $workflow = WorkflowStub::make(TestBroadFailureCatchWorkflow::class, 'query-history-failure-misconfigured-alias');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $this->assertSame('order-rejected', $event->payload['exception_type'] ?? null);

        config()->set('workflows.v2.types.exceptions.order-rejected', \stdClass::class);

        try {
            $workflow->refresh()->currentState();
            $this->fail('Expected misconfigured failure alias replay to block the query.');
        } catch (UnresolvedWorkflowFailureException $exception) {
            $this->assertSame(TestReplayedDomainException::class, $exception->originalExceptionClass());
            $this->assertSame('order-rejected', $exception->exceptionType());
            $this->assertSame('misconfigured', $exception->resolutionSource());
            $this->assertStringContainsString(\stdClass::class, (string) $exception->resolutionError());
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $timelineFailure = collect($detail['timeline'])->firstWhere('type', HistoryEventType::ActivityFailed->value);

        $this->assertSame('order-rejected', $detail['exceptions'][0]['exception_type']);
        $this->assertNull($detail['exceptions'][0]['exception_resolved_class']);
        $this->assertSame('misconfigured', $detail['exceptions'][0]['exception_resolution_source']);
        $this->assertStringContainsString(\stdClass::class, (string) $detail['exceptions'][0]['exception_resolution_error']);
        $this->assertTrue($detail['exceptions'][0]['exception_replay_blocked']);
        $this->assertSame('misconfigured', $timelineFailure['failure']['exception_resolution_source'] ?? null);
        $this->assertTrue($timelineFailure['failure']['exception_replay_blocked'] ?? false);

        $export = HistoryExport::forRun($run);
        $this->assertSame('misconfigured', $export['failures'][0]['exception_resolution_source']);
        $this->assertTrue($export['failures'][0]['exception_replay_blocked']);

        $workflow->signal('resume', 'go');
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);

        /** @var WorkflowTask $blockedTask */
        $blockedTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->latest('updated_at')
            ->firstOrFail();

        $this->assertTrue($blockedTask->payload['replay_blocked'] ?? false);
        $this->assertSame('failure_resolution', $blockedTask->payload['replay_blocked_reason'] ?? null);
        $this->assertSame('misconfigured', $blockedTask->payload['replay_blocked_resolution_source'] ?? null);
        $this->assertSame('order-rejected', $blockedTask->payload['replay_blocked_exception_type'] ?? null);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($workflow->runId()));
        $replayBlockedTask = collect($detail['tasks'])->firstWhere('transport_state', 'replay_blocked');

        $this->assertSame('workflow_replay_blocked', $detail['liveness_state']);
        $this->assertNotNull($replayBlockedTask);
        $this->assertSame('failure_resolution', $replayBlockedTask['replay_blocked_reason']);

        config()->set('workflows.v2.types.exceptions.order-rejected', TestReplayedDomainException::class);

        $repair = WorkflowStub::loadRun($workflow->runId())->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
            'resume' => 'go',
        ], $workflow->output());
    }

    public function testUnrestorableHistoricalFailureClassBlocksReplayAndOperatorDetail(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestBroadFailureCatchWorkflow::class, 'query-history-failure-unrestorable');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->firstOrFail();

        $payload = $event->payload;
        unset($payload['exception_type'], $payload['exception']['type']);
        $payload['exception_class'] = TestAbstractReplayedException::class;
        $payload['exception']['class'] = TestAbstractReplayedException::class;

        DB::transaction(static function () use ($event, $payload): void {
            $event->forceFill([
                'payload' => $payload,
            ])->save();

            WorkflowFailure::query()
                ->where('id', $payload['failure_id'])
                ->update([
                    'exception_class' => TestAbstractReplayedException::class,
                    'message' => 'abstract replay row drift',
                ]);
        });

        try {
            $workflow->refresh()->currentState();
            $this->fail('Expected unrestorable failure replay to block the query.');
        } catch (UnresolvedWorkflowFailureException $exception) {
            $this->assertSame(TestAbstractReplayedException::class, $exception->originalExceptionClass());
            $this->assertSame('unrestorable', $exception->resolutionSource());
            $this->assertNull($exception->exceptionType());
            $this->assertStringContainsString('abstract throwable', (string) $exception->resolutionError());
        }

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $detail = RunDetailView::forRun($run);
        $timelineFailure = collect($detail['timeline'])->firstWhere('type', HistoryEventType::ActivityFailed->value);

        $this->assertSame(TestAbstractReplayedException::class, $detail['exceptions'][0]['exception_class']);
        $this->assertSame(TestAbstractReplayedException::class, $detail['exceptions'][0]['exception_resolved_class']);
        $this->assertSame('unrestorable', $detail['exceptions'][0]['exception_resolution_source']);
        $this->assertStringContainsString('abstract throwable', (string) $detail['exceptions'][0]['exception_resolution_error']);
        $this->assertTrue($detail['exceptions'][0]['exception_replay_blocked']);
        $this->assertSame('unrestorable', $timelineFailure['failure']['exception_resolution_source'] ?? null);
        $this->assertTrue($timelineFailure['failure']['exception_replay_blocked'] ?? false);

        $export = HistoryExport::forRun($run);
        $this->assertSame('unrestorable', $export['failures'][0]['exception_resolution_source']);
        $this->assertTrue($export['failures'][0]['exception_replay_blocked']);

        $workflow->signal('resume', 'go');
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);

        /** @var WorkflowTask $blockedTask */
        $blockedTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Failed->value)
            ->latest('updated_at')
            ->firstOrFail();

        $this->assertTrue($blockedTask->payload['replay_blocked'] ?? false);
        $this->assertSame('unrestorable', $blockedTask->payload['replay_blocked_resolution_source'] ?? null);
        $this->assertSame(TestAbstractReplayedException::class, $blockedTask->payload['replay_blocked_exception_class'] ?? null);

        config()->set('workflows.v2.types.exception_class_aliases', [
            TestAbstractReplayedException::class => TestReplayedDomainException::class,
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
        ], WorkflowStub::loadRun($workflow->runId())->currentState());

        $repair = WorkflowStub::loadRun($workflow->runId())->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
            'resume' => 'go',
        ], $workflow->output());
    }

    public function testUnmappedHistoricalChildFailureClassBlocksReplayInsteadOfFallingThroughBroadRuntimeCatch(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestBroadChildFailureCatchWorkflow::class, 'query-child-failure-unmapped');
        $workflow->start('order-123');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame([
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
        ], $workflow->currentState());

        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ChildRunFailed->value)
            ->firstOrFail();

        $payload = $event->payload;
        unset($payload['exception_type'], $payload['exception']['type']);
        $payload['exception_class'] = 'App\\Legacy\\OrderRejected';
        $payload['exception']['class'] = 'App\\Legacy\\OrderRejected';

        foreach ($payload['exception']['properties'] as &$property) {
            $property['declaring_class'] = 'App\\Legacy\\OrderRejected';
        }

        unset($property);

        DB::transaction(static function () use ($event, $payload): void {
            $event->forceFill([
                'payload' => $payload,
            ])->save();

            WorkflowFailure::query()
                ->where('id', $payload['failure_id'])
                ->update([
                    'exception_class' => 'App\\Legacy\\OrderRejected',
                    'message' => 'legacy child row drift',
                ]);
        });

        try {
            $workflow->refresh()->currentState();
            $this->fail('Expected unresolved child failure replay to block the query.');
        } catch (UnresolvedWorkflowFailureException $exception) {
            $this->assertSame('App\\Legacy\\OrderRejected', $exception->originalExceptionClass());
            $this->assertSame('unresolved', $exception->resolutionSource());
            $this->assertNull($exception->exceptionType());
        }

        $workflow->signal('resume', 'go');
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::WorkflowFailed->value,
        ]);
        $this->assertDatabaseHas('workflow_tasks', [
            'workflow_run_id' => $workflow->runId(),
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Failed->value,
        ]);

        config()->set('workflows.v2.types.exception_class_aliases', [
            'App\\Legacy\\OrderRejected' => TestReplayedDomainException::class,
        ]);

        $this->assertSame([
            'stage' => 'waiting-for-resume',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
        ], WorkflowStub::loadRun($workflow->runId())->currentState());

        $repair = WorkflowStub::loadRun($workflow->runId())->attemptRepair();

        $this->assertTrue($repair->accepted());
        $this->runReadyTaskForRun($workflow->runId(), TaskType::Workflow);
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'stage' => 'completed',
            'caught' => [
                'class' => TestReplayedDomainException::class,
                'message' => 'Order order-123 rejected via api',
                'code' => 422,
            ],
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

    public function testQueriesAndReplayStayBlockedOnScheduledTimerHistoryWhenTimerRowClaimsItAlreadyFired(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestPendingTimerSignalWorkflow::class, 'query-pending-timer-history');
        $workflow->start(60);

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('before-timer', $workflow->currentStage());
        $this->assertSame(['started'], $workflow->currentEvents());

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $timer->forceFill([
            'status' => TimerStatus::Fired,
            'fired_at' => now(),
        ])->save();

        $this->assertSame('before-timer', $workflow->refresh()->currentStage());
        $this->assertSame(['started'], $workflow->currentEvents());

        $workflow->signal('resume', 'go');
        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('before-timer', $workflow->currentStage());
        $this->assertSame(['started'], $workflow->currentEvents());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerScheduled->value)
            ->count());
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::TimerFired->value)
            ->count());
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

    public function testParentChildFailuresProjectFromTypedChildRunFailedHistoryWhenChildFailureRowsDrift(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestHistoryReplayedChildFailureWorkflow::class, 'query-child-failure-projection');
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
        /** @var WorkflowHistoryEvent $childFailedEvent */
        $childFailedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ChildRunFailed->value)
            ->firstOrFail();

        $childFailureId = $childFailedEvent->payload['failure_id'] ?? null;
        $this->assertIsString($childFailureId);

        DB::transaction(static function () use ($childRun): void {
            WorkflowFailure::query()
                ->where('workflow_run_id', $childRun->id)
                ->delete();

            $childRun->forceFill([
                'status' => RunStatus::Completed,
                'closed_reason' => 'completed',
                'output' => Serializer::serialize('corrupted-child-output'),
            ])->save();
        });

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($workflow->runId());

        RunSummaryProjector::project($parentRun->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'updates',
        ]));

        $detail = RunDetailView::forRun($parentRun->fresh());
        $exception = unserialize($detail['exceptions'][0]['exception']);
        $export = HistoryExport::forRun($parentRun->fresh());

        $this->assertSame(1, $detail['exception_count']);
        $this->assertSame(1, $detail['exceptions_count']);
        $this->assertSame(TestReplayedDomainException::class, $detail['exceptions'][0]['exception_class']);
        $this->assertSame(TestReplayedDomainException::class, $detail['exceptions'][0]['exception_resolved_class']);
        $this->assertSame('recorded_class', $detail['exceptions'][0]['exception_resolution_source']);
        $this->assertSame(TestReplayedDomainException::class, $exception['__constructor']);
        $this->assertSame('Order order-123 rejected via api', $exception['message']);
        $this->assertSame(422, $exception['code']);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $workflow->runId(),
            'event_type' => HistoryEventType::FailureHandled->value,
        ]);
        $this->assertSame(1, $parentRun->fresh('summary')->summary?->exception_count);
        $this->assertSame($childFailureId, $export['failures'][0]['id']);
        $this->assertSame('child_workflow_run', $export['failures'][0]['source_kind']);
        $this->assertSame($childRun->id, $export['failures'][0]['source_id']);
        $this->assertSame('child', $export['failures'][0]['propagation_kind']);
        $this->assertTrue($export['failures'][0]['handled']);
        $this->assertSame(TestReplayedDomainException::class, $export['failures'][0]['exception_class']);
        $this->assertSame('Order order-123 rejected via api', $export['failures'][0]['message']);
    }

    public function testQueriesKeepWaitingForChildUntilParentCommitsChildResolutionHistory(): void
    {
        $parentInstance = WorkflowInstance::create([
            'id' => 'query-child-resolution-authority-parent',
            'workflow_class' => TestQueryChildResolutionAuthorityWorkflow::class,
            'workflow_type' => 'test-query-child-resolution-authority-workflow',
            'run_count' => 1,
        ]);

        $childInstance = WorkflowInstance::create([
            'id' => 'query-child-resolution-authority-child',
            'workflow_class' => TestHistoryReplayedChildWorkflow::class,
            'workflow_type' => 'workflow.child',
            'run_count' => 1,
        ]);

        $parentRun = WorkflowRun::create([
            'workflow_instance_id' => $parentInstance->id,
            'run_number' => 1,
            'workflow_class' => TestQueryChildResolutionAuthorityWorkflow::class,
            'workflow_type' => 'test-query-child-resolution-authority-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([60]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'last_progress_at' => now()->subMinute(),
        ]);

        $childRun = WorkflowRun::create([
            'workflow_instance_id' => $childInstance->id,
            'run_number' => 1,
            'workflow_class' => TestHistoryReplayedChildWorkflow::class,
            'workflow_type' => 'workflow.child',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize([
                'child' => 'corrupted-terminal-row',
            ]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(2),
            'closed_at' => now()->subSeconds(30),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $parentInstance->update(['current_run_id' => $parentRun->id]);
        $childInstance->update(['current_run_id' => $childRun->id]);

        $link = WorkflowLink::create([
            'link_type' => 'child_workflow',
            'sequence' => 1,
            'parent_workflow_instance_id' => $parentInstance->id,
            'parent_workflow_run_id' => $parentRun->id,
            'child_workflow_instance_id' => $childInstance->id,
            'child_workflow_run_id' => $childRun->id,
            'is_primary_parent' => true,
            'created_at' => now()->subSeconds(45),
            'updated_at' => now()->subSeconds(45),
        ]);

        WorkflowHistoryEvent::create([
            'workflow_run_id' => $parentRun->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::ChildWorkflowScheduled->value,
            'payload' => [
                'workflow_link_id' => $link->id,
                'child_call_id' => $link->id,
                'sequence' => 1,
                'child_workflow_instance_id' => $childInstance->id,
                'child_workflow_run_id' => $childRun->id,
                'child_workflow_type' => 'workflow.child',
                'child_workflow_class' => TestHistoryReplayedChildWorkflow::class,
                'child_run_number' => 1,
            ],
            'recorded_at' => now()->subSeconds(45),
            'created_at' => now()->subSeconds(45),
            'updated_at' => now()->subSeconds(45),
        ]);

        $state = (new QueryStateReplayer())->query($parentRun->fresh(), 'currentState');

        $this->assertSame([
            'stage' => 'waiting-for-child',
            'child' => [
                'instance_id' => $link->child_workflow_instance_id,
                'run_id' => $childRun->id,
                'call_id' => $link->id,
            ],
        ], $state);
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

    public function testQueriesExposeCurrentChildHandleWhileParentWaitsOnChild(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestChildHandleParentWorkflow::class, 'query-child-handle');
        $workflow->start();
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $deadline = microtime(true) + 5;

        while ($workflow->refresh()->summary()?->wait_kind !== 'child') {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the parent workflow to project a child wait.');
            }

            usleep(100000);
        }

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        $this->assertSame([
            'instance_id' => $link->child_workflow_instance_id,
            'run_id' => $link->child_workflow_run_id,
            'call_id' => $link->id,
        ], $workflow->query('current-child-handle'));
    }

    public function testQueriesExposeParallelChildHandlesInSequenceOrder(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestParallelChildHandlesWorkflow::class, 'query-child-handles');
        $workflow->start();
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();

        $links = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->orderBy('sequence')
            ->get();

        $this->assertCount(3, $links);

        $expectedHandles = $links
            ->map(static fn (WorkflowLink $link): array => [
                'instance_id' => $link->child_workflow_instance_id,
                'run_id' => $link->child_workflow_run_id,
                'call_id' => $link->id,
            ])
            ->all();

        $this->assertSame($expectedHandles, $workflow->query('child-handles'));
        $this->assertSame($expectedHandles[2], $workflow->query('latest-child-handle'));
    }

    public function testQueriesFollowTheLatestContinuedChildRunOnCurrentHandle(): void
    {
        Queue::fake();
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');

        $workflow = WorkflowStub::make(
            TestParentWaitingOnContinuingChildWorkflow::class,
            'query-continued-child-handle',
        );
        $workflow->start(0, 1);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $deadline = microtime(true) + 10;

        while (WorkflowRun::query()
            ->where('workflow_type', 'test-continue-as-new-workflow')
            ->count() < 2) {
            if (microtime(true) >= $deadline) {
                $this->fail('Timed out waiting for the child workflow to continue as new.');
            }

            $this->runNextReadyTask();
        }

        /** @var WorkflowHistoryEvent $childStarted */
        $childStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', HistoryEventType::ChildRunStarted->value)
            ->orderByDesc('sequence')
            ->firstOrFail();
        $childInstanceId = $childStarted->payload['child_workflow_instance_id'] ?? null;
        $childCallId = $childStarted->payload['child_call_id'] ?? null;

        $this->assertIsString($childInstanceId);
        $this->assertIsString($childCallId);

        /** @var WorkflowRun $latestChildRun */
        $latestChildRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childInstanceId)
            ->orderByDesc('run_number')
            ->firstOrFail();

        WorkflowRun::query()->create([
            'workflow_instance_id' => $childInstanceId,
            'run_number' => $latestChildRun->run_number + 1,
            'workflow_class' => $latestChildRun->workflow_class,
            'workflow_type' => $latestChildRun->workflow_type,
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([999, 1000]),
            'connection' => $latestChildRun->connection,
            'queue' => $latestChildRun->queue,
            'started_at' => now()->addMinute(),
            'last_progress_at' => now()->addMinute(),
        ]);

        WorkflowInstance::query()
            ->findOrFail($childInstanceId)
            ->forceFill([
                'current_run_id' => null,
            ])
            ->save();

        WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->delete();
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('event_type', HistoryEventType::ChildRunStarted->value)
            ->get()
            ->each
            ->delete();

        $this->assertSame([
            'instance_id' => $childInstanceId,
            'run_id' => $latestChildRun->id,
            'call_id' => $childCallId,
        ], $workflow->query('current-child-handle'));
        $this->assertSame([[
            'instance_id' => $childInstanceId,
            'run_id' => $latestChildRun->id,
            'call_id' => $childCallId,
        ]], $workflow->query('child-handles'));
    }

    public function testQueriesReplayParallelChildFailureAfterParentCommitsChildResolutionHistory(): void
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

        $this->runReadyTaskForRun($parentRunId, TaskType::Workflow);

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

    public function testQueriesReplayNestedParallelActivityGroupsBeforeParentWorkflowTaskRuns(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestNestedParallelActivityWorkflow::class, 'query-nested-parallel-activities');
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->runReadyActivityTaskForSequence($parentRunId, 2);
        $this->runReadyActivityTaskForSequence($parentRunId, 3);

        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
        $this->assertSame([
            'stage' => 'waiting-for-activities',
        ], $workflow->currentState());

        $this->runReadyActivityTaskForSequence($parentRunId, 1);

        $this->assertSame(1, WorkflowTask::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
        $this->assertSame([
            'stage' => 'completed',
        ], $workflow->currentState());
    }

    public function testQueriesRejectParallelActivityBarrierTopologyDrift(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestNestedParallelActivityWorkflow::class,
            'query-parallel-topology-drift',
        );
        $workflow->start('Taylor', 'Abigail', 'Selena');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->replaceActivityScheduledParallelPath($parentRunId, 2, [[
            'parallel_group_id' => 'parallel-activities:1:3',
            'parallel_group_kind' => 'activity',
            'parallel_group_base_sequence' => 1,
            'parallel_group_size' => 3,
            'parallel_group_index' => 1,
        ]]);

        $this->expectException(HistoryEventShapeMismatchException::class);
        $this->expectExceptionMessage('recorded [ActivityScheduled]');
        $this->expectExceptionMessage('current workflow yielded parallel all barrier matching current topology');

        $workflow->refresh()->currentState();
    }

    public function testQueriesRejectParallelActivityHistoryWithoutGroupMetadata(): void
    {
        config()->set('queue.default', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(
            TestParallelActivityWorkflow::class,
            'query-parallel-missing-group-metadata',
        );
        $workflow->start('Taylor', 'Abigail');
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->runNextReadyTask();
        $this->removeActivityHistoryParallelMetadata($parentRunId, 1);

        try {
            $workflow->refresh()->currentState();
            $this->fail('Expected query replay to reject parallel activity history without group metadata.');
        } catch (HistoryEventShapeMismatchException $exception) {
            $this->assertSame(1, $exception->workflowSequence);
            $this->assertSame('parallel all barrier matching current topology', $exception->expectedHistoryShape);
            $this->assertSame(['ActivityScheduled'], $exception->recordedEventTypes);
        }
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

    public function testQueriesBreakParallelFailureTimestampTiesByBarrierIndexBeforeParentWorkflowTaskRuns(): void
    {
        Queue::fake();

        Carbon::setTestNow(Carbon::parse('2026-04-09 12:30:00'));

        try {
            $workflow = WorkflowStub::make(
                TestParallelMultipleActivityFailureWorkflow::class,
                'query-parallel-multiple-activity-failure',
            );
            $workflow->start();
            $parentRunId = $workflow->runId();

            $this->assertNotNull($parentRunId);

            $this->runNextReadyTask();
            $this->runReadyActivityTaskForSequence($parentRunId, 2);
            $this->runReadyActivityTaskForSequence($parentRunId, 1);

            $this->assertSame([
                'stage' => 'caught-activity-failure',
                'message' => 'first failure',
            ], $workflow->currentState());
        } finally {
            Carbon::setTestNow();
        }
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

    private function runReadyActivityTaskForSequence(string $runId, int $sequence): void
    {
        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', $sequence)
            ->firstOrFail();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->get()
            ->sole(static fn (WorkflowTask $task): bool => ($task->payload['activity_execution_id'] ?? null) === $execution->id);

        $this->app->call([new RunActivityTask($task->id), 'handle']);
    }

    /**
     * @param list<array<string, mixed>> $path
     */
    private function replaceActivityScheduledParallelPath(string $runId, int $sequence, array $path): void
    {
        /** @var WorkflowHistoryEvent $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->get()
            ->sole(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['sequence'] ?? null) === $sequence
            );

        $payload = is_array($event->payload) ? $event->payload : [];
        $last = $path[array_key_last($path)] ?? [];

        $event->forceFill([
            'payload' => array_merge($payload, $last, [
                'parallel_group_path' => $path,
            ]),
        ])->save();
    }

    private function removeActivityHistoryParallelMetadata(string $runId, int $sequence): void
    {
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityHeartbeatRecorded->value,
                HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::ActivityCompleted->value,
                HistoryEventType::ActivityFailed->value,
            ])
            ->get()
            ->each(static function (WorkflowHistoryEvent $event) use ($sequence): void {
                $payload = is_array($event->payload) ? $event->payload : [];

                if (($payload['sequence'] ?? null) !== $sequence) {
                    return;
                }

                unset(
                    $payload['parallel_group_id'],
                    $payload['parallel_group_kind'],
                    $payload['parallel_group_base_sequence'],
                    $payload['parallel_group_size'],
                    $payload['parallel_group_index'],
                    $payload['parallel_group_path'],
                );

                $event->forceFill(['payload' => $payload])->save();
            });
    }
}
