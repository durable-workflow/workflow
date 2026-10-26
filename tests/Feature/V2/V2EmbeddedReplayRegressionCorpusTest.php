<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DateTimeImmutable;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\Uid\Ulid;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestLocalActivityWorkflow;
use Tests\Fixtures\V2\TestQueryReplayGuardActivity;
use Tests\Fixtures\V2\TestRepeatedPatchClockWorkflow;
use Tests\Fixtures\V2\TestSinglePatchReplayWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\Fixtures\V2\TestVersionPendingRangeRepairWorkflow;
use Tests\Fixtures\V2\TestVersionRangeRepairActivity;
use Tests\Support\NexusCallerDeliveryScenario;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Activity;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\ProtocolAwareWorkflowTaskBridge;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowServiceOperationAuthorizer;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ActivityRetryPolicyMismatchException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Exceptions\VersionRangeMismatchException;
use Workflow\V2\Exceptions\WorkflowEntryArgumentsMismatchException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunServiceCall;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceCallDispatch;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\ConditionWaits;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\ReplayDiff;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\ServiceCallScheduler;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Support\WorkflowStepHistory;
use Workflow\V2\Support\WorkflowTaskPayload;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2EmbeddedReplayRegressionCorpusTest extends TestCase
{
    use NexusCallerDeliveryScenario;

    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/V2/ReplayRegression';

    private int $workflowNumber = 0;

    public function testRetriedActivityCancellationCorpusKeepsTheCompletedTaskAndAcknowledgesTheLiveTask(): void
    {
        $path = self::FIXTURE_DIR . '/retried-activity-cancellation-live-task.json';
        if (! is_file($path)) {
            $this->markTestSkipped('The retained retried-activity cancellation evidence is absent.');
        }
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::stopWorkers();
        Queue::fake();
        config([
            'queue.default' => 'database',
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
        ]);
        $this->executeColdReplayFixture($fixture);
        $scenario = $fixture['retry_cancellation'];
        $scheduled = collect($fixture['history'])->firstWhere('event_type', 'ActivityScheduled')['payload'];
        $recordedCancellation = collect($fixture['history'])->firstWhere('event_type', 'ActivityCancelled')['payload'];
        $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $lease = static function (WorkflowTask $task): WorkflowTask {
            $task->forceFill([
                'status' => TaskStatus::Leased,
                'lease_owner' => 'portable-worker',
                'lease_expires_at' => now()
                    ->addMinutes(5),
            ])->save();
            return $task;
        };
        foreach ($scenario['policies'] as $policyValue) {
            $this->clearWorkflowState();
            $policy = CancellationPolicy::from($policyValue);
            $stub = WorkflowStub::make(
                $fixture['workflow']['type'],
                'corpus-retried-cancellation-' . ++$this->workflowNumber
            );
            $stub->start(...$fixture['workflow']['arguments']);
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $initial = $lease($run->tasks()->where('task_type', TaskType::Workflow)->sole());
            $commands = WorkflowCommandNormalizer::normalize([[
                'type' => 'schedule_activity',
                'activity_type' => $scheduled['activity_type'],
                'arguments' => Serializer::serialize($scenario['activity_arguments']),
                'cancellation_policy' => $policy->value,
                'retry_policy' => $scenario['retry_policy'],
            ]], $fixture['protocol_version']);
            $this->assertTrue($bridge->complete($initial->id, $commands)['completed']);
            $execution = $run->activityExecutions()
                ->sole();
            $firstTask = $run->tasks()
                ->where('task_type', TaskType::Activity)->sole();
            $this->assertTrue($activities->claimStatus($firstTask->id, 'first-owner')['claimed']);
            $firstAttempt = $execution->attempts()
                ->sole();
            $retry = $activities->fail($firstAttempt->id, [
                'class' => \RuntimeException::class,
                'message' => 'temporary failure',
            ]);
            $this->assertTrue($retry['recorded']);
            $liveTask = WorkflowTask::query()->findOrFail($retry['next_task_id']);
            $this->assertTrue($activities->claimStatus($liveTask->id, 'replacement-owner')['claimed']);
            $liveAttempt = $execution->attempts()
                ->where('attempt_number', $recordedCancellation['attempt_number'])->sole();
            $this->assertSame($scenario['completed_task_status'], $firstTask->refresh()->status->value);
            $completedBefore = $firstTask->getRawOriginal();
            $this->assertTrue(
                $stub->requestCancellation('maintenance', $scenario['cleanup_timeout_seconds'])->accepted()
            );
            $run->refresh();
            $deadline = $run->cancellation_deadline_at->toISOString();
            $ready = static fn (): WorkflowTask => $run->tasks()
                ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->sole();
            $deliver = static fn (WorkflowTask $task): array => $bridge->deliverCancellation(
                $task->id,
                $run->cancellation_request_command_id,
                $scheduled['sequence'],
                'activity'
            );
            $delivery = $deliver($lease($ready()));
            $this->assertSame($policy === CancellationPolicy::TryCancel, $delivery['delivered']);
            if ($policy === CancellationPolicy::WaitCancellationCompleted) {
                $this->assertTrue($delivery['claim_released']);
                $this->assertSame('cancellation_waiting_for_activity', $delivery['reason']);
            }
            $this->assertSame($completedBefore, $firstTask->refresh()->getRawOriginal());
            $this->assertSame($scenario['live_task_status'], $liveTask->refresh()->status->value);
            $this->assertNull($liveTask->lease_expires_at);
            $cancelled = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancelled)->sole();
            $this->assertSame($liveTask->id, $cancelled->payload['task']['id']);
            $this->assertSame($liveAttempt->id, $cancelled->payload['activity_attempt']['id']);
            $accepted = ActivityCancellationAcknowledgement::recordStopped(
                $liveAttempt->id,
                'replacement-owner',
                $run->cancellation_request_command_id
            );
            $this->assertTrue($accepted['acknowledged']);
            $this->assertFalse($accepted['duplicate']);
            $duplicate = ActivityCancellationAcknowledgement::recordStopped(
                $liveAttempt->id,
                'replacement-owner',
                $run->cancellation_request_command_id
            );
            $this->assertTrue($duplicate['acknowledged']);
            $this->assertTrue($duplicate['duplicate']);
            $this->assertSame($accepted['history_event_id'], $duplicate['history_event_id']);
            $this->assertSame(
                1,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
            );
            if ($policy === CancellationPolicy::WaitCancellationCompleted) {
                $this->assertTrue($deliver($lease($ready()))['delivered']);
            }
            $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCommittedTimerContinuationUsesOneWorkflowConstruction(): void
    {
        $path = self::FIXTURE_DIR . '/embedded-committed-timer-continuation.json';
        if (! is_file($path)) {
            $this->markTestSkipped('The retained timer evidence is not present in this source revision.');
        }
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->clearWorkflowState();
        $previousQueue = Queue::getFacadeRoot();
        $keys = ['queue.default', 'queue.connections.database', 'workflows.v2.queue',
            'workflows.v2.task_dispatch_mode', 'workflows.v2.live_execution'];
        $previous = array_combine($keys, array_map(static fn (string $key): mixed => config($key), $keys));
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'corpus-live',
                'retry_after' => 90,
            ],
            'workflows.v2.queue' => 'corpus-live',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.live_execution' => [
                'workflows' => [$fixture['workflow']['type']],
                'build' => 'corpus-live',
            ],
        ]);
        $this->app->forgetInstance('queue');
        Queue::swap($this->app->make('queue'));
        $constructions = 0;
        $count = false;
        $this->app->resolving($fixture['workflow']['type'], static function () use (&$constructions, &$count): void {
            if ($count) {
                ++$constructions;
            }
        });
        Carbon::setTestNow('2026-10-17 15:15:00.123456');
        try {
            $stub = WorkflowStub::make($fixture['workflow']['type'], 'corpus-live');
            $stub->start(...[...$fixture['workflow']['arguments'], new StartOptions(
                executionTimeoutSeconds: 120,
                runTimeoutSeconds: 90,
            )]);
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
            $worker = $this->app->make('queue.worker');
            $work = static fn () => $worker->runNextJob('database', 'corpus-live', new WorkerOptions(sleep: 0));
            $count = true;
            $work();
            $prefix = $run->historyEvents()
                ->get()
                ->map->getRawOriginal()
                ->all();
            Carbon::setTestNow(now()->addSeconds(2));
            $work();
            $work();
            $this->assertSame('completed', $stub->refresh()->status());
            $this->assertSame([
                'waited' => true,
                'workflow_id' => $stub->id(),
                'run_id' => $run->id,
            ], $stub->output());
            $this->assertSame(
                1,
                $constructions,
                'The committed timer continuation must not reconstruct its completed prefix.'
            );
            $this->assertSame(
                $prefix,
                $run->historyEvents()
                    ->limit(count($prefix))
                    ->get()
                    ->map->getRawOriginal()
                    ->all()
            );
            $this->assertSame($deadlines, [$run->fresh()->getRawOriginal('execution_deadline_at'),
                $run->fresh()
                    ->getRawOriginal('run_deadline_at')]);
            $this->assertSame(3, $run->tasks()->count());
            $this->assertSame(3, $run->tasks()->where('status', 'completed')->where('attempt_count', 1)->count());
            $this->assertSame(0, (int) $run->tasks()->sum('repair_count'));
        } finally {
            Carbon::setTestNow();
            Queue::swap($previousQueue);
            config($previous);
            DB::table('jobs')->where('queue', 'corpus-live')->delete();
        }
    }

    public function testFixturesExecuteThroughDeclaredReplayConsumers(): void
    {
        self::stopWorkers();

        config([
            'app.name' => 'Embedded Upgrade Host',
            'queue.default' => 'database',
            'workflows.v2.compatibility.current' => 'build-a',
            'workflows.v2.compatibility.supported' => ['build-a'],
        ]);
        Queue::fake();

        foreach ($this->fixtures() as $fixture) {
            $this->executeColdReplayFixture($fixture);

            if ($fixture['id'] === 'retried-activity-cancellation-live-task') {
                // Its live retry/delivery proof is exercised by the dedicated corpus test.
                continue;
            }

            if ($fixture['id'] === 'embedded-committed-timer-continuation') {
                // Its positive executor path runs in the isolated queue test above.
                continue;
            }

            if ($fixture['id'] === 'external-worker-pending-count-limit-rollback') {
                $this->assertExternalWorkerPendingCountLimitRollsBackCompletion($fixture);
                continue;
            }

            if ($fixture['id'] === 'embedded-local-activity-retry-queue-delivery') {
                $this->assertEmbeddedLocalRetryQueuesItsOriginalTask($fixture);
                continue;
            }

            if ($fixture['id'] === 'nexus-sdk-caller-attribution') {
                $this->clearWorkflowState();
                $this->assertNexusCallerSdkAttribution($fixture);
                continue;
            }

            if ($fixture['id'] === 'workflow-service-operation-host-authority') {
                $this->clearWorkflowState();
                $this->assertNexusWorkerCannotSupplyAuthority($fixture);
                continue;
            }

            if (str_starts_with($fixture['id'], 'workflow-service-operation-protocol-')) {
                $this->assertServiceOperationProtocolPersistence($fixture);
                if ($fixture['protocol_version'] === '1.21') {
                    $this->assertServiceOperationProtocolPersistence($fixture, embedded: true);
                }
                continue;
            }

            if ($fixture['id'] === 'nexus-waiting-caller-terminal-delivery') {
                $this->clearWorkflowState();
                $this->assertWaitingNexusCallerReceivesResult(
                    $fixture['workflow']['type'],
                    $fixture['workflow']['arguments'],
                    $fixture['terminal_result'],
                );
                continue;
            }

            if ($fixture['id'] === 'nexus-original-deadline-cold-delivery') {
                $this->clearWorkflowState();
                $this->assertWaitingNexusCallerReceivesResult(
                    $fixture['workflow']['type'],
                    $fixture['workflow']['arguments'],
                    $fixture['terminal_result'],
                    $fixture['deadline_policy'],
                );
                continue;
            }

            if ($fixture['id'] === 'manual-repair-blocked-replay-with-pending-activity') {
                $this->assertRepairPrioritizesBlockedReplayAndPreservesAnOlderPendingActivity($fixture);
                continue;
            }

            if ($fixture['id'] === 'datetime-search-projection-history-repair') {
                $this->assertDatetimeSearchProjectionRepairPreservesItsOriginal($fixture);
                continue;
            }

            if ($fixture['id'] === 'repeated-version-legacy-clock-boundaries') {
                $this->assertRepeatedVersionClockBoundaries($fixture);
                continue;
            }

            if (in_array($fixture['id'], [
                'repeated-version-legacy-duplicates-completed',
                'repeated-version-legacy-duplicates-pending',
            ], true)) {
                $this->assertRepeatedVersionLegacyDuplicates($fixture);
                continue;
            }

            if (($fixture['id'] ?? null) === 'adjacent-condition-wait-occurrence-identity') {
                $this->assertAdjacentConditionWaitOccurrencesRemainDistinct($fixture);
            }

            if (($fixture['id'] ?? null) === 'parallel-child-group-final-sibling-release') {
                $this->assertLegacyParallelChildRawHistoryGap($fixture);
            }

            if (in_array($fixture['id'] ?? null, [
                'parallel-child-group-durable-command-sequence',
                'parallel-child-group-stranded-parent-recovery',
            ], true)) {
                $this->assertStandaloneParallelChildBarrierFixture($fixture);
            }

            if (($fixture['id'] ?? null) === 'signal-resumed-mixed-group-command-sequence') {
                $this->assertSignalResumedMixedGroupCommandSequenceFixture($fixture);
            }

            if (($fixture['id'] ?? null) === 'service-grouped-condition-physical-reopen') {
                $this->assertServiceGroupedConditionPhysicalReopen($fixture);
            }

            if (($fixture['id'] ?? null) === 'signal-applied-envelope-cold-replay') {
                $this->assertSignalAppliedEnvelopeColdReplay($fixture);
            }

            if (($fixture['id'] ?? null) === 'buffered-signal-committed-wait-reconciliation') {
                $this->assertBufferedSignalCommittedWaitReconciliation($fixture);
            }

            if (($fixture['id'] ?? null) === 'portable-local-activity-attempt-identity-cold-reload') {
                $this->assertPortableLocalActivityAttemptIdentitySurvivesColdReload($fixture);
            }

            if (($fixture['id'] ?? null) === 'portable-local-activity-mixed-worker-heartbeat-replay') {
                $this->assertPortableLocalActivityMixedWorkerHeartbeatReplay($fixture);
            }

            if (($fixture['id'] ?? null) === 'portable-local-activity-attempt-timeline-replay') {
                $this->assertPortableLocalActivityAttemptTimelineReplay($fixture);
            }

            if (($fixture['id'] ?? null) === 'portable-local-activity-timing-caps-cold-replay') {
                $this->assertLocalActivityTimingCapsBoundTheRecordedWalk($fixture);
            }

            if (($fixture['id'] ?? null) === 'portable-local-activity-failure-avro-cold-replay') {
                $this->assertPortableLocalActivityFailureUsesAvro($fixture);
            }

            if (($fixture['id'] ?? null) === 'portable-cooperative-cleanup-deadline-cold-reload') {
                $this->assertPortableCleanupDeadlineAfterColdReload($fixture);
            }

            if (($fixture['id'] ?? null) === 'run-inherited-scoped-cleanup-cold-reload') {
                $this->assertRunInheritedScopedCleanupAfterColdReload($fixture);
            }

            if ($fixture['id'] === 'legacy-version-shared-step-position') {
                $this->assertLegacyVersionSharesTheRecordedStepPosition($fixture);

                continue;
            }

            if ($fixture['id'] === 'parallel-child-parent-resolution-authority') {
                $this->assertParallelChildParentResolutionAuthority($fixture);

                continue;
            }

            if ($fixture['id'] === 'cooperative-delivery-before-wait-sequence') {
                $run = $this->createRunFromFixture($fixture);
                $this->assertSame(2, WorkflowStepHistory::nextDurableCommandSequence($run->fresh()));
            }

            $consumers = $fixture['consumers'] ?? ['workflow-fiber-runner'];
            if (in_array('embedded-history-import', $consumers, true)) {
                $this->assertHistoryImportMetadataRoundTrips($fixture);
            }

            $embeddedConsumers = array_intersect(['query-state-replayer', 'workflow-executor'], $consumers);

            if ($embeddedConsumers === []) {
                continue;
            }

            $this->assertArrayHasKey(
                'expected_failure',
                $fixture,
                "{$fixture['id']} embedded replay evidence must declare its failure.",
            );
            $mismatches = [];

            if (in_array('query-state-replayer', $consumers, true)) {
                $run = $this->createRunFromFixture($fixture);
                $operation = static fn (): mixed => (new QueryStateReplayer())->replay(
                    $run->fresh(['historyEvents']),
                );
                $mismatch = $this->replayFailureMismatch($operation, $fixture, 'QueryStateReplayer');
                if ($mismatch !== null) {
                    $mismatches[] = $mismatch;
                }
            }

            if (in_array('workflow-executor', $consumers, true)) {
                $run = $this->createRunFromFixture($fixture);
                $historyBefore = $run->historyEvents()
                    ->orderBy('sequence')
                    ->get()
                    ->toArray();
                $this->runReadyWorkflowTask($run);

                /** @var WorkflowFailure|null $failure */
                $failure = WorkflowFailure::query()
                    ->where('workflow_run_id', $run->id)
                    ->latest('created_at')
                    ->first();

                $blockedTask = WorkflowTask::query()
                    ->where('workflow_run_id', $run->id)
                    ->where('status', TaskStatus::Failed->value)
                    ->latest('created_at')
                    ->first();
                $blockedPayload = is_array($blockedTask?->payload) ? $blockedTask->payload : [];
                $expectedReplayBlockReason = match ($fixture['expected_failure']['exception']) {
                    ActivityRetryPolicyMismatchException::class => 'activity_retry_policy_mismatch',
                    HistoryEventShapeMismatchException::class => 'history_shape_mismatch',
                    VersionRangeMismatchException::class => 'version_marker_incompatible_range',
                    WorkflowEntryArgumentsMismatchException::class => 'workflow_entry_arguments_mismatch',
                    default => null,
                };
                $expectedReplayBlock = $expectedReplayBlockReason !== null
                    && ($blockedPayload['replay_blocked'] ?? false) === true
                    && ($blockedPayload['replay_blocked_reason'] ?? null) === $expectedReplayBlockReason;

                if ($expectedReplayBlock) {
                    $this->assertFalse($run->fresh()->status->isTerminal());
                    $this->assertNull($run->fresh()->closed_at);
                    $this->assertSame($historyBefore, $run->historyEvents()->orderBy('sequence')->get()->toArray());
                    $this->assertFalse($run->historyEvents()->whereIn('event_type', [
                        HistoryEventType::WorkflowCompleted->value,
                        HistoryEventType::WorkflowFailed->value,
                    ])->exists());
                }

                if ($expectedReplayBlockReason !== null) {
                    if (! $expectedReplayBlock || $failure !== null) {
                        $mismatches[] = "{$fixture['id']} did not preserve a nonterminal blocked replay.";
                    }
                } elseif ($failure === null) {
                    if (! $expectedReplayBlock) {
                        $mismatches[] = "{$fixture['id']} was accepted by WorkflowExecutor instead of failing closed.";
                    }
                } elseif ($failure->exception_class !== $fixture['expected_failure']['exception']) {
                    $mismatches[] = "{$fixture['id']} produced the wrong WorkflowExecutor failure "
                        . "[{$failure->exception_class}].";
                }
            }

            $this->assertSame([], $mismatches, implode(' ', $mismatches));
        }
    }

    public function testDurableOnlyTimelineProjectionRetainsEveryBufferedSignal(): void
    {
        $fixturePath = self::FIXTURE_DIR . '/unrelated-buffered-signals-cold-replay.json';

        if (! is_file($fixturePath)) {
            $this->markTestSkipped('Durable-only timeline projection fixture is not present in the base corpus.');
        }

        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();

        /** @var array<string, mixed> $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertDurableOnlyTimelineProjection($fixture);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertExternalWorkerPendingCountLimitRollsBackCompletion(array $fixture): void
    {
        $cases = $fixture['external_worker_pending_counts'];
        $limitKeys = array_map(
            static fn (array $case): string => "workflows.v2.structural_limits.{$case['limit']}",
            $cases,
        );
        $previous = array_combine(
            $limitKeys,
            array_map(static fn (string $key): mixed => config($key), $limitKeys),
        );
        $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);

        try {
            foreach ($cases as $case) {
                $this->clearWorkflowState();
                config($previous);
                config([
                    "workflows.v2.structural_limits.{$case['limit']}" => 1,
                ]);
                $run = $this->createRunFromFixture($fixture);
                /** @var WorkflowTask $task */
                $task = $run->tasks()
                    ->where('task_type', TaskType::Workflow->value)
                    ->sole();
                $task->forceFill([
                    'status' => TaskStatus::Leased->value,
                    'lease_owner' => 'regression-corpus-worker',
                    'lease_expires_at' => now()
                        ->addMinutes(5),
                ])->save();
                $leasedTask = $task->fresh()
                    ->getRawOriginal();
                $before = self::pendingCountDurableState($run);

                try {
                    $bridge->complete($task->id, [$case['command'], $case['command']]);
                    $this->fail("Two pending {$case['command']['type']} commands passed a limit of one.");
                } catch (StructuralLimitExceededException $exception) {
                    $this->assertSame($case['limit'], $exception->limitKind->value);
                    $this->assertSame(1, $exception->currentValue);
                    $this->assertSame(1, $exception->configuredLimit);
                }

                $this->assertSame($before, self::pendingCountDurableState($run));
                $this->assertSame($leasedTask, $task->fresh()->getRawOriginal());
                $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
            }
        } finally {
            config($previous);
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertServiceOperationProtocolPersistence(array $fixture, bool $embedded = false): void
    {
        $this->clearWorkflowState();
        Queue::fake();
        $previousControlPlane = $this->app->make(ServiceControlPlane::class);
        $previousAuthorizer = $this->installNexusServiceAuthority();
        $scheduled = $fixture['protocol_version'] === '1.21';
        $failed = str_ends_with($fixture['id'], '-failure');
        $targetInstance = WorkflowInstance::query()->create([
            'id' => 'protocol-target',
            'namespace' => 'service',
            'workflow_class' => 'protocol-service',
            'workflow_type' => 'protocol-service',
            'run_count' => 1,
        ]);
        $targetRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $targetInstance->id,
            'namespace' => 'service',
            'run_number' => 1,
            'workflow_class' => 'protocol-service',
            'workflow_type' => 'protocol-service',
            'status' => $failed ? RunStatus::Failed->value : RunStatus::Completed->value,
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'started_at' => now(),
            'closed_at' => now(),
        ]);
        WorkflowHistoryEvent::record(
            $targetRun,
            $failed ? HistoryEventType::WorkflowFailed : HistoryEventType::WorkflowCompleted,
            $failed ? [
                'exception_type' => 'invoice_declined',
                'message' => 'The invoice approval was denied.',
            ] : [
                'output' => Serializer::serializeWithCodec('avro', $fixture['terminal_result']),
                'payload_codec' => 'avro',
            ],
        );
        $handlerCalls = 0;
        $handler = Mockery::mock(WorkflowControlPlane::class);
        $handler->shouldReceive('start')
            ->once()
            ->withArgs(
                function (string $workflowType, ?string $instanceId, array $options) use (&$handlerCalls): bool {
                    ++$handlerCalls;
                    $this->assertGreaterThan(0, DB::transactionLevel());
                    return $workflowType === 'protocol-service';
                },
            )->andReturn([
                'started' => true,
                'workflow_instance_id' => $targetInstance->id,
                'workflow_run_id' => $targetRun->id,
            ]);
        $this->app->instance(ServiceControlPlane::class, new DefaultServiceControlPlane($handler));

        try {
            $endpoint = WorkflowServiceEndpoint::query()->create([
                'namespace' => 'service',
                'endpoint_name' => 'php-service',
            ]);
            $service = WorkflowService::query()->create([
                'namespace' => 'service',
                'workflow_service_endpoint_id' => $endpoint->id,
                'service_name' => 'greeter',
            ]);
            WorkflowServiceOperation::query()->create([
                'namespace' => 'service',
                'workflow_service_endpoint_id' => $endpoint->id,
                'workflow_service_id' => $service->id,
                'operation_name' => 'greet',
                'operation_mode' => 'sync',
                'handler_binding_kind' => 'workflow_run',
                'handler_target_reference' => 'protocol-service',
            ]);
            $workflow = $fixture['workflow'];
            $stub = WorkflowStub::make(
                $workflow['type'],
                sprintf('regression-corpus-protocol-%d', ++$this->workflowNumber),
                'caller',
            );
            $stub->start(...$workflow['arguments']);
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $task = $run->tasks()
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->sole();
            $bridge = $this->app->make(WorkflowTaskBridge::class);
            $claim = $bridge->claimStatus($task->id, 'regression-corpus-protocol-worker');
            $this->assertTrue($claim['claimed'], json_encode($claim, JSON_THROW_ON_ERROR));
            $history = $bridge->historyPayload($task->id);
            $this->assertNotNull($history);
            $authored = WorkflowFiberRunner::forClass(
                $workflow['type'],
                $run->workflow_instance_id,
                $run->id,
                $workflow['arguments'],
                $workflow['payload_codec'],
                $history['history_events'],
            )->step();
            $this->assertSame(['start_service_operation'], array_column($authored->commands, 'type'));
            Queue::fake();

            DB::transaction(function () use ($bridge, $task, $authored, $fixture, $embedded, $scheduled): void {
                $result = $embedded
                    ? $bridge->execute($task->id)
                    : ($bridge instanceof ProtocolAwareWorkflowTaskBridge
                        ? $bridge->completeForProtocol($task->id, $authored->commands, $fixture['protocol_version'])
                        : $bridge->complete($task->id, $authored->commands));
                $this->assertTrue(
                    $result[$embedded ? 'executed' : 'completed'],
                    json_encode($result, JSON_THROW_ON_ERROR)
                );
                $this->assertSame($scheduled ? 1 : 0, WorkflowServiceCallDispatch::query()->count());
                Queue::assertNothingPushed();
            });

            $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $run->id)->sole();
            $this->assertSame($scheduled ? 'accepted' : ($failed ? 'failed' : 'completed'), $call->status);
            $event = $run->historyEvents()
                ->whereIn(
                    'event_type',
                    ['ServiceCallCompleted', 'ServiceCallFailed', 'ServiceOperationScheduled']
                )->sole();
            $this->assertSame($fixture['history'][1]['event_type'], $event->event_type->value);
            $this->assertSame(
                $scheduled ? 1 : 0,
                $run->historyEvents()
                    ->where('event_type', 'ServiceOperationScheduled')
                    ->count()
            );
            if ($scheduled) {
                $this->assertFalse($event->payload['workflow_resume_on_admission']);
                $dispatch = WorkflowServiceCallDispatch::query()->sole();
                $this->assertSame($call->id, $dispatch->service_call_id);
                $this->assertSame('scheduled', $dispatch->state);
                $this->assertFalse(ServiceCallScheduler::hasAfterCommitAuthority($dispatch->dispatch_options));
                Queue::assertPushed(RunServiceCall::class, 1);
                Queue::assertPushed(
                    RunServiceCall::class,
                    static fn (RunServiceCall $job): bool => $job->serviceCallId === $call->id
                );
            } else {
                Queue::assertNothingPushed();
                if ($failed) {
                    $this->assertSame('invoice_declined', $event->payload['exception_type']);
                    $this->assertSame('The invoice approval was denied.', $event->payload['message']);
                }
            }
            $cold = WorkflowFiberRunner::forClass(
                $workflow['type'],
                $run->workflow_instance_id,
                $run->id,
                $workflow['arguments'],
                $workflow['payload_codec'],
                HistoryExport::forRun($run->fresh())['history_events'],
            )->step();
            $this->assertStepMatches($fixture['expected'], $cold, $fixture['id'] . ' persisted history');
            $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
            $this->assertSame($scheduled ? 0 : 1, $handlerCalls);
            if ($scheduled) {
                if ($embedded) {
                    // Cold embedded replay of the retained scheduled history
                    // must wait without dispatching its handler a second time.
                    $replayRun = $this->createRunFromFixture($fixture);
                    Queue::fake();
                    $this->runReadyWorkflowTask($replayRun);
                    $this->assertSame(RunStatus::Waiting, $replayRun->fresh()->status);
                    $this->assertSame(
                        1,
                        $replayRun->historyEvents()
                            ->where('event_type', 'ServiceOperationScheduled')
                            ->count()
                    );
                    $this->assertSame(1, WorkflowServiceCallDispatch::query()->count());
                    $this->assertSame(0, $handlerCalls);
                    Queue::assertNothingPushed();
                }

                // The same 1.21 host must still execute a direct HTTP-style
                // idempotent call inline, despite carrying the caller identity.
                Queue::fake();
                $direct = $this->app->make(ServiceControlPlane::class)->execute('php-service', 'greeter', 'greet', [
                    'namespace' => 'service',
                    'caller_namespace' => 'caller',
                    'caller_workflow_instance_id' => $run->workflow_instance_id,
                    'caller_workflow_run_id' => $run->id,
                    'principal' => new ServiceCallPrincipal(subject: 'workflow:' . $run->id, method: 'workflow'),
                    'idempotency_key' => 'direct-protocol-call',
                    'wait_for' => 'completed',
                ]);
                $this->assertSame('completed', $direct['status']);
                $this->assertSame($fixture['terminal_result'], Serializer::unserializeWithCodec(
                    $direct['result_envelope']['codec'],
                    $direct['result_envelope']['blob'],
                ));
                $this->assertSame(1, $handlerCalls);
                $this->assertSame(1, WorkflowServiceCallDispatch::query()->count());
                Queue::assertNothingPushed();
            }
        } finally {
            $this->app->instance(ServiceControlPlane::class, $previousControlPlane);
            if ($previousAuthorizer !== null) {
                $this->app->instance(WorkflowServiceOperationAuthorizer::class, $previousAuthorizer);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private static function pendingCountDurableState(WorkflowRun $run): array
    {
        return [
            'history' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count(),
            'activity_executions' => ActivityExecution::query()->where('workflow_run_id', $run->id)->count(),
            'activity_attempts' => ActivityAttempt::query()->count(),
            'timers' => WorkflowTimer::query()->where('workflow_run_id', $run->id)->count(),
            'child_calls' => WorkflowChildCall::query()->where('parent_workflow_run_id', $run->id)->count(),
            'links' => WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->count(),
            'commands' => WorkflowCommand::query()->where('workflow_run_id', $run->id)->count(),
            'instances' => WorkflowInstance::query()->count(),
            'runs' => WorkflowRun::query()->count(),
            'tasks' => WorkflowTask::query()->where('workflow_run_id', $run->id)->count(),
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertRepairPrioritizesBlockedReplayAndPreservesAnOlderPendingActivity(array $fixture): void
    {
        self::stopWorkers();
        Queue::fake();
        $previousProjection = $this->app->make(HistoryProjectionRole::class);
        $this->app->instance(HistoryProjectionRole::class, new DefaultHistoryProjectionRole());
        Carbon::setTestNow('2026-10-11 10:00:00 UTC');
        TestVersionRangeRepairActivity::$executions = 0;
        TestVersionPendingRangeRepairWorkflow::$minSupported = 1;
        TestVersionPendingRangeRepairWorkflow::$maxSupported = 1;
        $value = $fixture['workflow']['arguments'][0];

        try {
            $workflow = WorkflowStub::make(
                TestVersionPendingRangeRepairWorkflow::class,
                'version-pending-range-repair'
            );
            $workflow->start($value);
            $runId = (string) $workflow->runId();
            $initial = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->firstOrFail();
            $this->app->call([new RunWorkflowTask($initial->id), 'handle']);
            $activity = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Activity)->where('status', TaskStatus::Ready)->firstOrFail();
            $timer = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Timer)->where('status', TaskStatus::Ready)->firstOrFail();
            $this->assertNotNull($timer->available_at);
            Carbon::setTestNow($timer->available_at->copy()->addSecond());
            $this->app->call([new RunTimerTask($timer->id), 'handle']);
            $resume = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->firstOrFail();

            TestVersionPendingRangeRepairWorkflow::$minSupported = 2;
            TestVersionPendingRangeRepairWorkflow::$maxSupported = 2;
            $this->app->call([new RunWorkflowTask($resume->id), 'handle']);
            $blocked = $resume->fresh();
            $this->assertSame(TaskStatus::Failed, $blocked->status);
            $this->assertSame(true, $blocked->payload['replay_blocked']);
            $this->assertSame('version_marker_incompatible_range', $blocked->payload['replay_blocked_reason']);
            $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($runId)->status);
            $this->assertFalse(WorkflowFailure::query()->where('workflow_run_id', $runId)->exists());
            $this->assertSame($resume->id, $blocked->id);
            $this->assertSame(0, TestVersionRangeRepairActivity::$executions);
            $pendingBeforeRepair = $activity->fresh()
                ->toArray();
            $prefix = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->orderBy('sequence')
                ->get()
                ->toArray();

            TestVersionPendingRangeRepairWorkflow::$minSupported = 1;
            TestVersionPendingRangeRepairWorkflow::$maxSupported = 1;
            $repair = WorkflowStub::loadSelection('version-pending-range-repair', $runId)->attemptRepair();
            $this->assertTrue($repair->accepted());
            $this->assertSame('repair_dispatched', $repair->outcome());
            $repairEvent = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::RepairRequested)->firstOrFail();
            $this->assertSame($blocked->id, $repairEvent->payload['task_id']);
            $this->assertSame('workflow', $repairEvent->payload['task_type']);
            $this->assertSame('workflow_replay_blocked', $repairEvent->payload['liveness_state']);
            $this->assertSame('activity', $repairEvent->payload['wait_kind']);
            $this->assertSame($pendingBeforeRepair, $activity->fresh()->toArray());
            $this->assertSame(TaskStatus::Ready, $blocked->fresh()->status);
            $this->assertSame($blocked->repair_count + 1, $blocked->fresh()->repair_count);
            $afterRepair = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->orderBy('sequence')
                ->get()
                ->toArray();
            $this->assertSame($prefix, array_slice($afterRepair, 0, count($prefix)));
            $this->assertCount(count($prefix) + 1, $afterRepair);

            $this->app->call([new RunWorkflowTask($blocked->id), 'handle']);
            $this->assertSame(TaskStatus::Completed, $blocked->fresh()->status);
            $this->assertSame($blocked->attempt_count + 1, $blocked->fresh()->attempt_count);
            $this->assertSame($pendingBeforeRepair, $activity->fresh()->toArray());
            $this->assertSame(0, TestVersionRangeRepairActivity::$executions);
            $this->assertSame('waiting', $workflow->refresh()->status());
            $this->assertSame($afterRepair, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->orderBy('sequence')
                ->get()
                ->toArray());

            $this->app->call([new RunActivityTask($activity->id), 'handle']);
            $final = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->firstOrFail();
            $this->assertNotSame($blocked->id, $final->id);
            $this->app->call([new RunWorkflowTask($final->id), 'handle']);
            $this->assertTrue($workflow->refresh()->completed());
            $this->assertSame($runId, $workflow->runId());
            $this->assertSame($value, $workflow->output());
            $this->assertSame(1, TestVersionRangeRepairActivity::$executions);
            $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->orderBy('sequence')
                ->get()
                ->toArray();
            $this->assertSame($prefix, array_slice($history, 0, count($prefix)));
            $this->assertSame(
                ['RepairRequested', 'ActivityStarted', 'ActivityCompleted', 'WorkflowCompleted'],
                array_column(array_slice($history, count($prefix)), 'event_type')
            );
        } finally {
            $this->app->instance(HistoryProjectionRole::class, $previousProjection);
            Carbon::setTestNow();
            TestVersionPendingRangeRepairWorkflow::$minSupported = 1;
            TestVersionPendingRangeRepairWorkflow::$maxSupported = 1;
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertRepeatedVersionClockBoundaries(array $fixture): void
    {
        $run = $this->createRunFromFixture($fixture);
        $markers = $run->historyEvents()
            ->where('event_type', HistoryEventType::VersionMarkerRecorded)->get()
            ->keyBy(static fn (WorkflowHistoryEvent $marker): int => $marker->payload['sequence']);
        foreach ($fixture['history'] as $event) {
            if ($event['event_type'] === HistoryEventType::VersionMarkerRecorded->value) {
                $markers[$event['payload']['sequence']]->update([
                    'recorded_at' => Carbon::parse($event['recorded_at']),
                ]);
            }
        }
        $before = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        TestQueryReplayGuardActivity::$executions = 0;

        foreach ([1, 2] as $coldReplay) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertInstanceOf(TestRepeatedPatchClockWorkflow::class, $state->workflow);
            $this->assertSame($fixture['expected']['result'], $state->workflow->currentState());
            $this->assertSame(4, $state->sequence);
            $this->assertNull($state->current);
        }

        $this->runReadyWorkflowTask($run);
        $events = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $this->assertSame($before, array_slice($events->toArray(), 0, count($before)));
        $this->assertCount(2, $events->where('event_type', HistoryEventType::VersionMarkerRecorded));
        $this->assertCount(1, $events->where('event_type', HistoryEventType::WorkflowCompleted));
        $activity = $events->firstWhere('event_type', HistoryEventType::ActivityScheduled);
        $this->assertNotNull($activity);
        $this->assertSame(3, $activity->payload['sequence']);
        $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
        $this->assertSame('completed', $run->fresh()->status->value);
        $this->assertSame($fixture['expected']['result'], $run->fresh()->workflowOutput());
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertRepeatedVersionLegacyDuplicates(array $fixture): void
    {
        $completed = ($fixture['expected']['completed'] ?? false) === true;
        $run = $this->createRunFromFixture($fixture);
        $before = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        TestQueryReplayGuardActivity::$executions = 0;

        foreach ([1, 2] as $coldReplay) {
            $state = (new QueryStateReplayer())->replayState($run->fresh());
            $this->assertInstanceOf(TestSinglePatchReplayWorkflow::class, $state->workflow);
            $this->assertSame([true, $completed ? 'recorded-result' : null], $state->workflow->currentState());
            $this->assertSame($completed ? 4 : 3, $state->sequence);
            $this->assertSame($completed, $state->current === null);
        }
        $this->runReadyWorkflowTask($run);
        $events = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $this->assertSame($before, array_slice($events->toArray(), 0, count($before)));
        $this->assertCount(2, $events->where('event_type', HistoryEventType::VersionMarkerRecorded));
        $this->assertCount(1, $events->where('event_type', HistoryEventType::ActivityScheduled));
        $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
        if ($completed) {
            $this->assertSame('completed', $run->fresh()->status->value);
            $this->assertSame([true, 'recorded-result'], $run->fresh()->workflowOutput());
        } else {
            $this->assertSame('waiting', $run->fresh()->status->value);
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertAdjacentConditionWaitOccurrencesRemainDistinct(array $fixture): void
    {
        $run = $this->createRunFromFixture($fixture);

        $this->assertSame(
            $fixture['expected_condition_wait_occurrence_ids'],
            array_column(ConditionWaits::forRun($run->fresh(['historyEvents'])), 'condition_wait_occurrence_id'),
        );
    }

    /**
     * The fixture's unrelated signals cross a timeline page boundary. A full
     * projection must persist every row without returning a growing model list.
     *
     * @param array<string, mixed> $fixture
     */
    private function assertDurableOnlyTimelineProjection(array $fixture): void
    {
        $run = $this->createRunFromFixture($fixture);
        $signalPayload = $fixture['history'][3]['payload'];
        $extraSignals = $fixture['projection_extra_unrelated_signals'];

        for ($index = 0; $index < $extraSignals; $index++) {
            WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, array_merge($signalPayload, [
                'signal_id' => sprintf('projection-unrelated-%d', $index),
                'signal_wait_id' => sprintf('projection-unrelated-wait-%d', $index),
            ]));
        }

        $eventCount = $run->historyEvents()
            ->count();
        $this->assertSame([], RunTimelineProjector::project($run, collectRows: false));
        $this->assertSame(
            $eventCount,
            WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count(),
        );
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertPortableLocalActivityFailureUsesAvro(array $fixture): void
    {
        $this->clearWorkflowState();

        $terminal = $fixture['history'][count($fixture['history']) - 1]['payload'];
        $expectedException = $terminal['activity']['exception'];
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $stub = WorkflowStub::make(
            $fixture['workflow']['type'],
            sprintf('regression-corpus-local-failure-%d', ++$this->workflowNumber),
        );
        $stub->start(...$fixture['workflow']['arguments']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();
        $this->assertTrue($bridge->claimStatus($task->id, 'regression-corpus-local-failure')['claimed']);

        $completion = $bridge->complete($task->id, [[
            'type' => 'record_local_activity',
            'activity_type' => $terminal['activity_type'],
            'outcome' => 'failed',
            'message' => $terminal['message'],
            'attempts' => [[
                'attempt_number' => 1,
                'outcome' => 'failed',
                'message' => $terminal['message'],
            ]],
            'retry_policy' => [
                'max_attempts' => 1,
            ],
            'execution_mode' => 'local',
        ]]);

        $this->assertTrue($completion['completed']);
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->sole();
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityFailed->value)
            ->sole();
        $this->assertSame($expectedException, $execution->exception);
        $this->assertSame($expectedException, $event->payload['activity']['exception']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertLegacyParallelChildRawHistoryGap(array $fixture): void
    {
        $scheduledEvents = array_values(array_filter(
            $fixture['history'],
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'ChildWorkflowScheduled',
        ));
        $completionEvents = array_values(array_filter(
            $fixture['history'],
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'ChildRunCompleted',
        ));

        $this->assertSame([2, 3], array_column($scheduledEvents, 'sequence'));
        $this->assertSame([3, 4], array_column(array_column($scheduledEvents, 'payload'), 'sequence'));
        $this->assertSame([3, 3], array_column(
            array_column($scheduledEvents, 'payload'),
            'parallel_group_base_sequence',
        ));
        $this->assertSame([4, 3], array_column(array_column($completionEvents, 'payload'), 'sequence'));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertStandaloneParallelChildBarrierFixture(array $fixture): void
    {
        $this->clearWorkflowState();

        $workflow = $fixture['workflow'];
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $stub = WorkflowStub::make(
            $workflow['type'],
            sprintf('regression-corpus-bridge-%d', ++$this->workflowNumber),
        );
        $stub->start(...$workflow['arguments']);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($stub->runId());
        /** @var WorkflowTask $parentTask */
        $parentTask = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $claim = $bridge->claimStatus($parentTask->id, 'regression-corpus-parent-worker');
        $this->assertTrue($claim['claimed']);

        $initialHistory = $bridge->historyPayload($parentTask->id);
        $this->assertNotNull($initialHistory);

        $authored = WorkflowFiberRunner::forClass(
            $workflow['type'],
            $parentRun->workflow_instance_id,
            $parentRun->id,
            $workflow['arguments'],
            $workflow['payload_codec'],
            $initialHistory['history_events'],
        )->step();
        $this->assertFalse($authored->completed);
        $this->assertSame(
            ['start_child_workflow', 'start_child_workflow'],
            array_column($authored->commands, 'type'),
        );

        $scheduledEvents = array_values(array_filter(
            $fixture['history'],
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'ChildWorkflowScheduled',
        ));
        $this->assertCount(2, $scheduledEvents);

        $parallelKeys = array_flip([
            'parallel_group_id',
            'parallel_group_kind',
            'parallel_group_base_sequence',
            'parallel_group_size',
            'parallel_group_index',
            'parallel_group_path',
        ]);
        $this->assertSame([1, 2], array_column(array_column($scheduledEvents, 'payload'), 'sequence'));
        $this->assertEquals(
            array_map(
                static fn (array $event): array => array_intersect_key($event['payload'], $parallelKeys),
                $scheduledEvents,
            ),
            array_map(
                static fn (array $command): array => array_intersect_key($command, $parallelKeys),
                $authored->commands,
            ),
        );

        $scheduled = $bridge->complete($parentTask->id, $authored->commands);
        $this->assertTrue($scheduled['completed'], json_encode($scheduled, JSON_THROW_ON_ERROR));
        $this->assertCount(2, $scheduled['created_task_ids']);
        $this->assertSame(0, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::SideEffectRecorded->value)
            ->count());
        $this->assertSame([1, 2], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildWorkflowScheduled->value)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): mixed => $event->payload['sequence'] ?? null)
            ->all());

        $childTasks = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->get()
            ->mapWithKeys(static fn (WorkflowLink $link): array => [
                $link->sequence => WorkflowTask::query()
                    ->where('workflow_run_id', $link->child_workflow_run_id)
                    ->where('task_type', TaskType::Workflow->value)
                    ->firstOrFail(),
            ]);
        $completionEvents = array_values(array_filter(
            $fixture['history'],
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'ChildRunCompleted',
        ));
        $this->assertSame([2, 1], array_column(array_column($completionEvents, 'payload'), 'sequence'));

        $openParentTaskCount = static fn (): int => WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();

        foreach ($completionEvents as $index => $event) {
            $payload = $event['payload'];
            $sequence = $payload['sequence'];
            /** @var WorkflowTask $childTask */
            $childTask = $childTasks->get($sequence);
            $this->assertInstanceOf(WorkflowTask::class, $childTask);

            $childClaim = $bridge->claimStatus($childTask->id, "regression-corpus-child-{$sequence}");
            $this->assertTrue($childClaim['claimed']);

            $completion = $bridge->complete($childTask->id, [[
                'type' => 'complete_workflow',
                'result' => $payload['output'],
                'payload_codec' => $payload['payload_codec'],
            ]]);
            $this->assertTrue($completion['completed'], json_encode($completion, JSON_THROW_ON_ERROR));

            if ($index === 0) {
                $this->assertSame(0, $openParentTaskCount());
                continue;
            }

            $this->assertSame(1, $openParentTaskCount());
        }

        $firstPayload = $completionEvents[0]['payload'];
        /** @var WorkflowTask $firstChildTask */
        $firstChildTask = $childTasks->get($firstPayload['sequence']);
        $this->assertInstanceOf(WorkflowTask::class, $firstChildTask);
        $duplicate = $bridge->complete($firstChildTask->id, [[
            'type' => 'complete_workflow',
            'result' => $firstPayload['output'],
            'payload_codec' => $firstPayload['payload_codec'],
        ]]);

        $this->assertFalse($duplicate['completed']);
        $this->assertSame('task_not_leased', $duplicate['reason']);
        $this->assertSame(1, $openParentTaskCount());

        $this->assertSame(2, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ChildRunCompleted->value)
            ->count());

        if ($fixture['id'] === 'parallel-child-group-stranded-parent-recovery') {
            $this->app->instance(HistoryProjectionRole::class, new DefaultHistoryProjectionRole());
            // Keep closed child histories, but reconstruct the parent's pre-fix lost-wake state.
            WorkflowTask::query()->where('workflow_run_id', $parentRun->id)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])->delete();
            WorkflowHistoryEvent::query()->where('workflow_run_id', $parentRun->id)
                ->where('event_type', HistoryEventType::ChildRunCompleted->value)->delete();
            $parentRun->refresh()
                ->forceFill([
                    'last_history_sequence' => $parentRun->historyEvents()
                        ->max('sequence'),
                ])->save();
            $projection = $this->app->make(HistoryProjectionRole::class);
            $this->assertSame('waiting_for_child', $projection->projectRun($parentRun->fresh())->liveness_state);
            DB::purge();
            DB::reconnect();
            $this->assertSame(1, TaskWatchdog::runPass(runIds: [$parentRun->id])['repaired_missing_tasks']);
            $this->assertSame(0, TaskWatchdog::runPass(runIds: [$parentRun->id])['repaired_missing_tasks']);
            $this->assertSame(1, $openParentTaskCount());
        }

        /** @var WorkflowTask $replacementTask */
        $replacementTask = WorkflowTask::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $replacementClaim = $bridge->claimStatus($replacementTask->id, 'regression-corpus-replacement-worker');
        $this->assertTrue($replacementClaim['claimed']);

        $replacementHistory = $bridge->historyPayload($replacementTask->id);
        $this->assertNotNull($replacementHistory);
        $coldReplay = WorkflowFiberRunner::forClass(
            $workflow['type'],
            $parentRun->workflow_instance_id,
            $parentRun->id,
            $workflow['arguments'],
            $workflow['payload_codec'],
            $replacementHistory['history_events'],
        )->step();

        $this->assertTrue($coldReplay->completed);
        $this->assertSame(['complete_workflow'], array_column($coldReplay->commands, 'type'));
        $this->assertSame($fixture['expected']['result']['children'], $coldReplay->result['children'] ?? null);
        $this->assertSame($stub->workflowId(), $coldReplay->result['workflow_id'] ?? null);
        $this->assertSame($parentRun->id, $coldReplay->result['run_id'] ?? null);

        $finished = $bridge->complete($replacementTask->id, $coldReplay->commands);
        $this->assertTrue($finished['completed'], json_encode($finished, JSON_THROW_ON_ERROR));
        $this->assertSame('completed', $finished['run_status']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertSignalResumedMixedGroupCommandSequenceFixture(array $fixture): void
    {
        $this->clearWorkflowState();

        $workflow = $fixture['workflow'];
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $stub = WorkflowStub::make(
            $workflow['type'],
            sprintf('regression-corpus-signal-resume-%d', ++$this->workflowNumber),
        );
        $stub->start(...$workflow['arguments']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $claim = $bridge->claimStatus($task->id, 'regression-corpus-signal-resume-worker');
        $this->assertTrue($claim['claimed']);

        foreach ($fixture['history'] as $event) {
            if (is_array($event['payload']['parallel_group_path'] ?? null)) {
                continue;
            }

            WorkflowHistoryEvent::record(
                $run,
                HistoryEventType::from($event['event_type']),
                $event['payload'],
                $task,
            );
        }

        $parallelKeys = array_flip([
            'parallel_group_id',
            'parallel_group_kind',
            'parallel_group_base_sequence',
            'parallel_group_size',
            'parallel_group_index',
            'parallel_group_path',
        ]);
        $commands = [];
        foreach ($fixture['history'] as $event) {
            $payload = $event['payload'];
            $command = match ($event['event_type']) {
                'ActivityScheduled' => [
                    'type' => 'schedule_activity',
                    'activity_type' => $payload['activity_type'],
                    'arguments' => Serializer::serializeWithCodec($workflow['payload_codec'], ['Ada']),
                    'payload_codec' => $workflow['payload_codec'],
                ],
                'ChildWorkflowScheduled' => [
                    'type' => 'start_child_workflow',
                    'workflow_type' => $payload['child_workflow_type'],
                    'arguments' => Serializer::serializeWithCodec($workflow['payload_codec'], [0]),
                    'payload_codec' => $workflow['payload_codec'],
                ],
                'TimerScheduled' => [
                    'type' => 'start_timer',
                    'delay_seconds' => $payload['delay_seconds'],
                ],
                default => null,
            };

            if ($command === null || ! is_array($payload['parallel_group_path'] ?? null)) {
                continue;
            }

            $commands[] = [...$command, ...array_intersect_key($payload, $parallelKeys)];
        }

        $scheduled = $bridge->complete($task->id, $commands);

        $this->assertTrue($scheduled['completed'], json_encode($scheduled, JSON_THROW_ON_ERROR));
        $this->assertCount(3, $scheduled['created_task_ids']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertDatetimeSearchProjectionRepairPreservesItsOriginal(array $fixture): void
    {
        $run = $this->createRunFromFixture($fixture);
        $payload = $fixture['history'][1]['payload'];
        $original = $payload['attributes']['SubmittedAt'];
        $attribute = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => 'SubmittedAt',
            'upserted_at_sequence' => $payload['sequence'],
        ]);
        $attribute->setTypedValue($original, WorkflowSearchAttribute::TYPE_DATETIME);
        $attribute->save();
        $connection = $run->getConnection();
        $connection->table('workflow_search_attributes')
            ->where('id', $attribute->id)
            ->update([
                'value_datetime' => (new DateTimeImmutable($original))->format('Y-m-d H:i:s.u'),
            ]);
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();
        $tasks = $connection->table('workflow_tasks')
            ->where('workflow_run_id', $run->id)
            ->get()
            ->all();
        $options = [
            'run' => $run->id,
            '--namespace' => $run->namespace,
            '--json' => true,
        ];

        $this->assertSame(0, Artisan::call('workflow:v2:repair-search-datetimes', $options));
        $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $preview['would_repair']);
        $this->assertSame(0, $preview['repaired']);
        $this->assertSame(0, Artisan::call('workflow:v2:repair-search-datetimes', [
            ...$options,
            '--apply' => true,
        ]));
        $this->assertSame('2026-10-08T10:34:56.123456Z', $attribute->fresh() ->getValue()?->toISOString());
        $this->assertSame($history, $run->historyEvents()->orderBy('sequence')->get()->toArray());
        $tasksAfter = $connection->table('workflow_tasks')
            ->where('workflow_run_id', $run->id)
            ->get()
            ->all();
        $this->assertEquals($tasks, $tasksAfter);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertHistoryImportMetadataRoundTrips(array $fixture): void
    {
        $metadata = $fixture['history_import_metadata'];
        $run = $this->createRunFromFixture($fixture);

        foreach ($metadata['memo'] as $key => $value) {
            $memo = new WorkflowMemo([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key,
                'upserted_at_sequence' => $run->last_history_sequence,
                'inherited_from_parent' => false,
            ]);
            $memo->setValue($value);
            $memo->save();
        }

        foreach ($metadata['search_attributes'] as $key => $value) {
            $attribute = new WorkflowSearchAttribute([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key,
                'upserted_at_sequence' => $run->last_history_sequence,
                'inherited_from_parent' => false,
            ]);
            $attribute->setTypedValueWithInference($value);
            $attribute->save();
        }

        $bundle = HistoryExport::forRun($run->fresh());
        $runId = $bundle['workflow']['run_id'];
        $this->assertEquals($metadata['memo'], $bundle['workflow']['memo']);
        $this->assertEquals($metadata['search_attributes'], $bundle['workflow']['search_attributes']);

        $this->clearWorkflowState();
        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);
        $this->assertNotContains(
            'payload_codec.unsupported',
            array_column($report['eligibility']['errors'], 'rule'),
        );

        /** @var WorkflowRun $importedRun */
        $importedRun = WorkflowRun::query()->findOrFail($runId);
        $roundTrip = HistoryExport::forRun($importedRun->fresh());

        $this->assertEquals($metadata['memo'], $roundTrip['workflow']['memo']);
        $this->assertEquals($metadata['search_attributes'], $roundTrip['workflow']['search_attributes']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertPortableLocalActivityAttemptIdentitySurvivesColdReload(array $fixture): void
    {
        $attemptsByActivity = [];
        $workerAttemptId = null;

        foreach ($fixture['history'] as $event) {
            if (($event['event_type'] ?? null) !== HistoryEventType::ActivityStarted->value) {
                continue;
            }

            $payload = $event['payload'];
            $activityExecutionId = $payload['activity_execution_id'] ?? null;
            $activityAttemptId = $payload['activity_attempt_id'] ?? null;
            $reportedAttemptId = $payload['worker_attempt_id'] ?? null;

            $this->assertIsString($activityExecutionId);
            $this->assertIsString($activityAttemptId);
            $this->assertIsString($reportedAttemptId);
            $this->assertTrue(Ulid::isValid($activityAttemptId));
            $this->assertSame(255, strlen($reportedAttemptId));

            $workerAttemptId ??= $reportedAttemptId;
            $this->assertSame($workerAttemptId, $reportedAttemptId);
            $attemptsByActivity[$activityExecutionId] = $activityAttemptId;
        }

        $this->assertIsString($workerAttemptId);
        $this->assertCount(2, $attemptsByActivity);
        $this->assertCount(2, array_unique(array_values($attemptsByActivity)));

        $run = $this->createRunFromFixture($fixture);
        $this->assertPortableOperatorActivityIdentities($run, $attemptsByActivity, $workerAttemptId);

        $bundle = HistoryExport::forRun($run->fresh());
        $this->assertPortableExportActivityIdentities($bundle, $attemptsByActivity, $workerAttemptId);

        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);
        $this->assertSame('imported', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertSame(2, $report['rows']['activity_executions']);
        $this->assertSame(2, $report['rows']['activity_attempts']);

        /** @var WorkflowRun $importedRun */
        $importedRun = WorkflowRun::query()->findOrFail($runId);
        $this->assertPortableStoredActivityIdentities($importedRun, $attemptsByActivity, $workerAttemptId);
        $this->assertPortableOperatorActivityIdentities($importedRun, $attemptsByActivity, $workerAttemptId);

        $roundTrip = HistoryExport::forRun($importedRun->fresh());
        $this->assertPortableExportActivityIdentities($roundTrip, $attemptsByActivity, $workerAttemptId);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertPortableLocalActivityMixedWorkerHeartbeatReplay(array $fixture): void
    {
        $expectedWorkerAttemptIds = ['worker-a-attempt-1', 'worker-b-attempt-1'];
        $expectedHeartbeats = ['2026-08-28T11:59:57.500000Z', '2026-08-28T11:59:58.500000Z'];
        $run = $this->createRunFromFixture($fixture);
        $activity = RunActivityView::activitiesForRun($run->fresh())[0];

        $this->assertSame('worker-b-attempt-1', $activity['worker_attempt_id']);
        $this->assertSame($expectedWorkerAttemptIds, array_column($activity['attempts'], 'worker_attempt_id'));
        $this->assertSame($expectedHeartbeats, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($activity['attempts'], 'last_heartbeat_at'),
        ));

        $bundle = HistoryExport::forRun($run->fresh());
        $exportedActivity = $bundle['activities'][0];
        $this->assertSame('worker-b-attempt-1', $exportedActivity['current_worker_attempt_id']);
        $this->assertSame($expectedWorkerAttemptIds, array_column(
            $exportedActivity['attempts'],
            'worker_attempt_id',
        ));
        $this->assertSame($expectedHeartbeats, array_column($exportedActivity['attempts'], 'last_heartbeat_at'));

        $replayed = (new WorkflowReplayer())->runFromHistoryExport($bundle);
        $replayedActivity = RunActivityView::activitiesForRun($replayed)[0];
        $this->assertSame($expectedWorkerAttemptIds, array_column(
            $replayedActivity['attempts'],
            'worker_attempt_id',
        ));
        $this->assertSame($expectedHeartbeats, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($replayedActivity['attempts'], 'last_heartbeat_at'),
        ));

        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();
        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $report['rows']['activity_executions']);
        $this->assertSame(2, $report['rows']['activity_attempts']);

        $importedAttempts = ActivityAttempt::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('attempt_number')
            ->get();
        $this->assertSame($expectedWorkerAttemptIds, $importedAttempts->pluck('worker_attempt_id')->all());
        $this->assertSame($expectedHeartbeats, $importedAttempts->pluck('last_heartbeat_at')
            ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
            ->all());
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertPortableLocalActivityAttemptTimelineReplay(array $fixture): void
    {
        $expectedWorkerAttemptIds = ['worker-a-attempt-1', 'worker-b-attempt-1'];
        $expectedStartedAt = ['2026-08-28T11:59:53.000000Z', '2026-08-28T11:59:58.000000Z'];
        $expectedHeartbeats = ['2026-08-28T11:59:54.500000Z', '2026-08-28T11:59:58.500000Z'];
        $expectedClosedAt = ['2026-08-28T11:59:57.000000Z', '2026-08-28T12:00:00.000000Z'];
        $attemptEvents = array_values(array_filter(
            $fixture['history'],
            static fn (array $event): bool => in_array($event['event_type'], [
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityHeartbeatRecorded->value,
            ], true),
        ));
        $this->assertSame(['running', 'running', 'running', 'running'], array_map(
            static fn (array $event): mixed => $event['payload']['activity']['status'] ?? null,
            $attemptEvents,
        ));
        $this->assertSame(['running', 'running', 'running', 'running'], array_map(
            static fn (array $event): mixed => $event['payload']['activity_attempt']['status'] ?? null,
            $attemptEvents,
        ));

        $run = $this->createRunFromFixture($fixture);
        $activity = RunActivityView::activitiesForRun($run->fresh())[0];

        $this->assertSame('worker-b-attempt-1', $activity['worker_attempt_id']);
        $this->assertSame($expectedHeartbeats[1], $activity['last_heartbeat_at']);
        $this->assertSame($expectedWorkerAttemptIds, array_column($activity['attempts'], 'worker_attempt_id'));
        $this->assertSame($expectedStartedAt, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($activity['attempts'], 'started_at'),
        ));
        $this->assertSame($expectedHeartbeats, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($activity['attempts'], 'last_heartbeat_at'),
        ));
        $this->assertSame($expectedClosedAt, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($activity['attempts'], 'closed_at'),
        ));

        $bundle = HistoryExport::forRun($run->fresh());
        $exportedActivity = $bundle['activities'][0];
        $this->assertSame('worker-b-attempt-1', $exportedActivity['current_worker_attempt_id']);
        $this->assertSame($expectedWorkerAttemptIds, array_column(
            $exportedActivity['attempts'],
            'worker_attempt_id',
        ));
        $this->assertSame($expectedStartedAt, array_column($exportedActivity['attempts'], 'started_at'));
        $this->assertSame($expectedHeartbeats, array_column($exportedActivity['attempts'], 'last_heartbeat_at'));
        $this->assertSame($expectedClosedAt, array_column($exportedActivity['attempts'], 'closed_at'));

        $replayed = (new WorkflowReplayer())->runFromHistoryExport($bundle);
        $replayedActivity = RunActivityView::activitiesForRun($replayed)[0];
        $this->assertSame($expectedWorkerAttemptIds, array_column(
            $replayedActivity['attempts'],
            'worker_attempt_id',
        ));
        $this->assertSame($expectedStartedAt, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($replayedActivity['attempts'], 'started_at'),
        ));
        $this->assertSame($expectedHeartbeats, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($replayedActivity['attempts'], 'last_heartbeat_at'),
        ));
        $this->assertSame($expectedClosedAt, array_map(
            static fn (Carbon $timestamp): string => $timestamp->toJSON(),
            array_column($replayedActivity['attempts'], 'closed_at'),
        ));

        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();
        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $report['rows']['activity_executions']);
        $this->assertSame(2, $report['rows']['activity_attempts']);

        $importedExecution = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->sole();
        $importedAttempts = ActivityAttempt::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('attempt_number')
            ->get();
        $this->assertSame($expectedStartedAt[0], $importedExecution->started_at?->toJSON());
        $this->assertSame($expectedHeartbeats[1], $importedExecution->last_heartbeat_at?->toJSON());
        $this->assertSame($expectedWorkerAttemptIds, $importedAttempts->pluck('worker_attempt_id')->all());
        $this->assertSame($expectedStartedAt, $importedAttempts->pluck('started_at')
            ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
            ->all());
        $this->assertSame($expectedHeartbeats, $importedAttempts->pluck('last_heartbeat_at')
            ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
            ->all());
        $this->assertSame($expectedClosedAt, $importedAttempts->pluck('closed_at')
            ->map(static fn (Carbon $timestamp): string => $timestamp->toJSON())
            ->all());
    }

    /**
     * The longest local activity timings a worker may report record exactly
     * the attempt walk this fixture replays, and one past any of them is
     * refused before anything is written.
     *
     * @param array<string, mixed> $fixture
     */
    private function assertLocalActivityTimingCapsBoundTheRecordedWalk(array $fixture): void
    {
        $report = $fixture['local_activity_report'];
        $expected = [];
        foreach ($fixture['history'] as $event) {
            $attempt = $event['payload']['activity_attempt'] ?? null;
            if (is_array($attempt)) {
                $expected[$attempt['attempt_number']] = [
                    $attempt['started_at'] ?? null,
                    $attempt['last_heartbeat_at'] ?? null,
                    $attempt['closed_at'] ?? null,
                ];
            }
        }
        ksort($expected);
        $command = static fn (array $attempts): array => [
            'type' => 'record_local_activity',
            'activity_type' => TestGreetingActivity::class,
            'arguments' => Serializer::serializeWithCodec('avro', $fixture['workflow']['arguments']),
            'result' => Serializer::serializeWithCodec('avro', 'Hello, Ada!'),
            'payload_codec' => 'avro',
            'outcome' => 'completed',
            'attempts' => $attempts,
            'retry_policy' => [
                'max_attempts' => count($attempts),
            ],
            'execution_mode' => 'local',
        ];
        $bridge = $this->app->make(WorkflowTaskBridge::class);

        Carbon::setTestNow($report['completed_at']);

        try {
            [$run, $task] = $this->leasedLocalActivityRun();
            $accepted = $bridge->complete($task->id, [$command($report['attempts'])]);
            $this->assertTrue($accepted['completed'] ?? false, json_encode($accepted, JSON_THROW_ON_ERROR));
            $recorded = [];
            $attempts = ActivityAttempt::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('attempt_number')
                ->get();
            foreach ($attempts as $attempt) {
                $recorded[$attempt->attempt_number] = [
                    $attempt->started_at?->toJSON(),
                    $attempt->last_heartbeat_at?->toJSON(),
                    $attempt->closed_at?->toJSON(),
                ];
            }
            $this->assertSame($expected, $recorded);

            foreach ($report['one_over'] as $case) {
                $over = $command($report['attempts']);
                data_set($over, $case['field'], $case['value']);
                $errors = [];
                try {
                    WorkflowCommandNormalizer::normalize([$over]);
                } catch (ValidationException $refusal) {
                    $errors = $refusal->errors();
                }
                $this->assertSame(
                    ["commands.0.{$case['field']}"],
                    array_keys($errors),
                    "{$case['field']} one past its cap must be refused by that field alone."
                );

                [$run, $task] = $this->leasedLocalActivityRun();
                $refused = $bridge->complete($task->id, [$over]);

                $this->assertFalse($refused['completed'] ?? true, "{$case['field']} one past its cap was accepted.");
                $this->assertSame(0, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
                $this->assertSame(TaskStatus::Leased, $task->fresh()->status);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{WorkflowRun, WorkflowTask}
     */
    private function leasedLocalActivityRun(): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestLocalActivityWorkflow::class,
            'workflow_type' => TestLocalActivityWorkflow::class,
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestLocalActivityWorkflow::class,
            'workflow_type' => TestLocalActivityWorkflow::class,
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serializeWithCodec('avro', ['Ada']),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 1,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'timing-caps-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        return [$run, $task];
    }

    /**
     * @param array<string, string> $attemptsByActivity
     */
    private function assertPortableStoredActivityIdentities(
        WorkflowRun $run,
        array $attemptsByActivity,
        string $workerAttemptId,
    ): void {
        $executions = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('id');
        $attempts = ActivityAttempt::query()
            ->where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('activity_execution_id');

        $this->assertCount(2, $executions);
        $this->assertCount(2, $attempts);

        foreach ($attemptsByActivity as $activityExecutionId => $activityAttemptId) {
            /** @var ActivityExecution $execution */
            $execution = $executions->get($activityExecutionId);
            /** @var ActivityAttempt $attempt */
            $attempt = $attempts->get($activityExecutionId);

            $this->assertInstanceOf(ActivityExecution::class, $execution);
            $this->assertInstanceOf(ActivityAttempt::class, $attempt);
            $this->assertSame($activityAttemptId, $execution->current_attempt_id);
            $this->assertSame($activityAttemptId, $attempt->id);
            $this->assertSame($workerAttemptId, $attempt->worker_attempt_id);
            $this->assertTrue(Ulid::isValid($execution->current_attempt_id));
        }
    }

    /**
     * @param array<string, string> $attemptsByActivity
     */
    private function assertPortableOperatorActivityIdentities(
        WorkflowRun $run,
        array $attemptsByActivity,
        string $workerAttemptId,
    ): void {
        $activities = RunActivityView::activitiesForRun($run->fresh());
        $this->assertCount(2, $activities);

        foreach ($activities as $activity) {
            $activityExecutionId = $activity['id'] ?? null;
            $this->assertIsString($activityExecutionId);
            $this->assertArrayHasKey($activityExecutionId, $attemptsByActivity);

            $activityAttemptId = $attemptsByActivity[$activityExecutionId];
            $this->assertSame($activityAttemptId, $activity['attempt_id']);
            $this->assertSame($workerAttemptId, $activity['worker_attempt_id']);
            $this->assertTrue(Ulid::isValid($activity['attempt_id']));
            $this->assertCount(1, $activity['attempts']);
            $this->assertSame($activityAttemptId, $activity['attempts'][0]['id']);
            $this->assertSame($workerAttemptId, $activity['attempts'][0]['worker_attempt_id']);
        }
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, string> $attemptsByActivity
     */
    private function assertPortableExportActivityIdentities(
        array $bundle,
        array $attemptsByActivity,
        string $workerAttemptId,
    ): void {
        $activities = $bundle['activities'] ?? null;
        $this->assertIsArray($activities);
        $this->assertCount(2, $activities);

        foreach ($activities as $activity) {
            $activityExecutionId = $activity['id'] ?? null;
            $this->assertIsString($activityExecutionId);
            $this->assertArrayHasKey($activityExecutionId, $attemptsByActivity);

            $activityAttemptId = $attemptsByActivity[$activityExecutionId];
            $this->assertSame($activityAttemptId, $activity['current_attempt_id']);
            $this->assertSame($workerAttemptId, $activity['current_worker_attempt_id']);
            $this->assertTrue(Ulid::isValid($activity['current_attempt_id']));
            $this->assertCount(1, $activity['attempts']);
            $this->assertSame($activityAttemptId, $activity['attempts'][0]['id']);
            $this->assertSame($workerAttemptId, $activity['attempts'][0]['worker_attempt_id']);
        }

        $attemptEventsByActivity = [];
        foreach ($bundle['history_events'] as $event) {
            if (! in_array($event['type'] ?? null, [
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityCompleted->value,
            ], true)) {
                continue;
            }

            $payload = $event['payload'];
            $activityExecutionId = $payload['activity_execution_id'] ?? null;
            $this->assertIsString($activityExecutionId);
            $this->assertArrayHasKey($activityExecutionId, $attemptsByActivity);
            $this->assertSame($attemptsByActivity[$activityExecutionId], $payload['activity_attempt_id']);
            $this->assertSame($workerAttemptId, $payload['worker_attempt_id']);
            $attemptEventsByActivity[$activityExecutionId][] = $event['type'];
        }

        foreach (array_keys($attemptsByActivity) as $activityExecutionId) {
            $this->assertSame([
                HistoryEventType::ActivityStarted->value,
                HistoryEventType::ActivityCompleted->value,
            ], $attemptEventsByActivity[$activityExecutionId] ?? []);
        }
    }

    private function assertEmbeddedLocalRetryQueuesItsOriginalTask(array $fixture): void
    {
        $this->clearWorkflowState();
        $previousQueue = Queue::getFacadeRoot();
        $keys = [
            'queue.default',
            'queue.connections.database',
            'workflows.v2.queue',
            'workflows.v2.task_dispatch_mode',
        ];
        $previous = array_combine($keys, array_map(static fn (string $key): mixed => config($key), $keys));
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'corpus-local-retry',
                'retry_after' => 90,
            ],
            'workflows.v2.queue' => 'corpus-local-retry',
            'workflows.v2.task_dispatch_mode' => 'queue',
        ]);
        $this->app->forgetInstance('queue');
        Queue::swap($this->app->make('queue'));
        $activityClass = \Tests\Fixtures\V2\TestGreetingActivity::class;
        $this->app->bind($activityClass, static fn () => new class() extends Activity {
            public int $tries = 2;

            public function backoff(): int
            {
                return 0;
            }

            public function handle(string $name): string
            {
                if ($name === 'Retry' && $this->attemptCount() === 1) {
                    throw new \RuntimeException('A real local callback failure.');
                }

                return "Hello, {$name}!";
            }
        });
        try {
            $stub = WorkflowStub::make($fixture['workflow']['type'], 'corpus-local-retry');
            $stub->start(...[...$fixture['workflow']['arguments'], new StartOptions(
                executionTimeoutSeconds: 30,
                runTimeoutSeconds: 20,
            )]);
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
            $work = static fn (): int => Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'corpus-local-retry',
                '--once' => true,
                '--tries' => 1,
            ]);
            $this->assertSame(0, $work());
            $retry = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)->sole();
            $this->assertSame(1, DB::table('jobs')->where('queue', 'corpus-local-retry')->count());
            $this->assertNotNull(
                WorkflowTask::query()->findOrFail($retry->payload['retry_task_id'])->last_dispatched_at
            );
            $this->assertSame(0, $work());
            $this->assertSame($fixture['expected']['result'], $stub->refresh()->output());
            $this->assertSame('completed', $run->fresh()->status->value);
            $this->assertSame($deadlines, [
                $run->fresh()
                    ->getRawOriginal('execution_deadline_at'), $run->fresh()
                    ->getRawOriginal('run_deadline_at'),
            ]);
            $this->assertSame(3, ActivityAttempt::query()->count());
            $this->assertSame(2, ActivityExecution::query()->count());
            $this->assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
            $this->assertSame(0, DB::table('jobs')->where('queue', 'corpus-local-retry')->count());
        } finally {
            $this->app->offsetUnset($activityClass);
            Queue::swap($previousQueue);
            config($previous);
            DB::table('jobs')->where('queue', 'corpus-local-retry')->delete();
        }
    }

    private function clearWorkflowState(): void
    {
        foreach ([
            'workflow_service_call_dispatches',
            'workflow_service_calls',
            'workflow_service_operations',
            'workflow_services',
            'workflow_service_endpoints',
            'workflow_run_summaries',
            'workflow_run_waits',
            'workflow_run_timeline_entries',
            'workflow_run_timer_entries',
            'workflow_run_lineage_entries',
            'workflow_search_attributes',
            'workflow_memos',
            'workflow_history_events',
            'workflow_tasks',
            'activity_attempts',
            'activity_executions',
            'workflow_run_timers',
            'workflow_failures',
            'workflow_links',
            'workflow_signal_records',
            'workflow_updates',
            'workflow_commands',
            'workflow_runs',
            'workflow_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertLegacyVersionSharesTheRecordedStepPosition(array $fixture): void
    {
        $run = $this->createRunFromFixture($fixture);
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();
        $payload = $started->payload;
        unset($payload['workflow_definition_fingerprint']);
        $started->forceFill([
            'payload' => $payload,
        ])->save();
        $bundle = HistoryExport::forRun($run->fresh());
        $before = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->get()->toArray();
        TestQueryReplayGuardActivity::$executions = 0;

        $this->assertSame(
            $fixture['expected']['result'],
            (new QueryStateReplayer())->query($run->fresh(), 'currentState'),
        );
        $this->assertSame(ReplayDiff::STATUS_REPLAYED, (new ReplayDiff())->diffExport($bundle)['status']);
        $this->assertSame(
            $before,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->get()->toArray(),
        );

        $this->runReadyWorkflowTask($run);
        $workflow = WorkflowStub::loadRun($run->id);
        $this->assertTrue($workflow->completed());
        $this->assertSame($fixture['expected']['result'], $workflow->output());
        $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
        $this->assertFalse(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded->value)
            ->exists());
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertParallelChildParentResolutionAuthority(array $fixture): void
    {
        $run = $this->createRunFromFixture($fixture);
        foreach (array_slice($fixture['history'], 1) as $event) {
            $payload = $event['payload'];
            $instance = WorkflowInstance::query()->create([
                'id' => $payload['child_workflow_instance_id'],
                'workflow_class' => TestTimerWorkflow::class,
                'workflow_type' => TestTimerWorkflow::class,
                'run_count' => 1,
            ]);
            $child = WorkflowRun::query()->create([
                'id' => $payload['child_workflow_run_id'],
                'workflow_instance_id' => $instance->id,
                'run_number' => 1,
                'workflow_class' => TestTimerWorkflow::class,
                'workflow_type' => TestTimerWorkflow::class,
                'status' => RunStatus::Completed->value,
                'closed_reason' => 'completed',
                'closed_at' => now(),
                'payload_codec' => $run->payload_codec,
                'output_payload_codec' => $run->payload_codec,
                'output' => Serializer::serializeWithCodec($run->payload_codec, 'uncommitted-child-output'),
                'connection' => 'database',
                'queue' => 'default',
            ]);
            $instance->forceFill([
                'current_run_id' => $child->id,
            ])->save();
        }
        $before = WorkflowRun::query()->orderBy('id')->get()->toArray();
        $history = WorkflowHistoryEvent::query()->orderBy('id')->get()->toArray();
        foreach ([1, 2] as $repetition) {
            $this->assertSame(
                [
                    'stage' => 'waiting-for-children',
                    'value' => null,
                    'failure' => null,
                ],
                (new QueryStateReplayer())->query($run->fresh(), 'currentState'),
            );
            $this->assertSame($before, WorkflowRun::query()->orderBy('id')->get()->toArray());
            $this->assertSame($history, WorkflowHistoryEvent::query()->orderBy('id')->get()->toArray());
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function executeColdReplayFixture(array $fixture): void
    {
        $workflow = $fixture['workflow'];
        $workflowClass = $workflow['type'];

        $this->assertIsString($workflowClass);
        $this->assertTrue(
            is_a($workflowClass, Workflow::class, true),
            sprintf('Replay fixture workflow [%s] must be an autoloadable V2 workflow.', $workflowClass),
        );

        if (isset($fixture['expected_failure'])) {
            $this->assertReplayFailure(
                static fn (): WorkflowStep => WorkflowFiberRunner::forClass(
                    $workflowClass,
                    'regression-corpus-' . $fixture['id'],
                    'regression-corpus-run-' . $fixture['id'],
                    $workflow['arguments'],
                    $workflow['payload_codec'],
                    $fixture['history'],
                )->step(),
                $fixture,
                'WorkflowFiberRunner',
            );

            return;
        }

        $runner = WorkflowFiberRunner::forClass(
            $workflowClass,
            'regression-corpus-' . $fixture['id'],
            'regression-corpus-run-' . $fixture['id'],
            $workflow['arguments'],
            $workflow['payload_codec'],
            $fixture['history'] ?? [],
        );
        $step = $runner->step();

        if (isset($fixture['command_sequence'])) {
            foreach ($fixture['command_sequence'] as $index => $expectedStep) {
                $this->assertStepMatches($expectedStep, $step, "{$fixture['id']} command step {$index}");

                if ($index < count($fixture['command_sequence']) - 1) {
                    $step = $runner->step($expectedStep['resume_with']);
                }
            }
        }

        $this->assertStepMatches($fixture['expected'], $step, "{$fixture['id']} final outcome");
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertServiceGroupedConditionPhysicalReopen(array $fixture): void
    {
        $this->clearWorkflowState();
        $workflow = $fixture['workflow'];
        $stub = WorkflowStub::make(
            $workflow['type'],
            sprintf('regression-corpus-condition-reopen-%d', ++$this->workflowNumber),
        );
        $stub->start(...$workflow['arguments']);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $runner = WorkflowFiberRunner::forClass(
            $workflow['type'],
            $stub->id(),
            $run->id,
            $workflow['arguments'],
            $workflow['payload_codec'],
        );
        $commands = $runner->step()
->commands;
        foreach ($commands as &$command) {
            if ($command['type'] === 'open_condition_wait') {
                // Service Workers supply the authored identity across physical
                // reopens, as in the published Rust reproduction for #601.
                $command['condition_wait_occurrence_id'] = 'rust:condition-wait:0';
            }
        }
        unset($command);
        $condition = collect($commands)
            ->firstWhere('type', 'open_condition_wait');
        $this->assertIsArray($condition);

        for ($wake = 0; $wake < 3; ++$wake) {
            $task = WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->firstOrFail();
            $this->assertTrue($bridge->claimStatus($task->id, 'corpus-condition-worker')['claimed']);
            $outcome = $bridge->complete($task->id, $wake === 0 ? $commands : [$condition]);
            $this->assertTrue($outcome['completed'], json_encode($outcome, JSON_THROW_ON_ERROR));
            $this->assertSame('waiting', $outcome['run_status']);
            $opens = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::ConditionWaitOpened->value)
                ->orderBy('sequence')
                ->get();
            $this->assertCount($wake + 1, $opens);
            $this->assertSame(
                [$condition['condition_wait_occurrence_id']],
                $opens->pluck('payload.condition_wait_occurrence_id')
                    ->unique()
                    ->values()
                    ->all(),
            );
            if ($wake < 2) {
                $this->assertTrue($stub->signal('vote', 'insufficient')->accepted());
            }
        }
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertSignalAppliedEnvelopeColdReplay(array $fixture): void
    {
        $this->clearWorkflowState();
        $run = $this->createRunFromFixture($fixture);

        $this->runReadyWorkflowTask($run);

        $this->assertSame('completed', $run->fresh()->status->value);
        $this->assertSame($fixture['expected']['result'], $run->fresh()->workflowOutput());
    }

    private function assertBufferedSignalCommittedWaitReconciliation(array $fixture): void
    {
        $this->clearWorkflowState();
        $prefix = $fixture;
        $prefix['history'] = array_values(
            array_filter(
                $fixture['history'],
                static fn (array $event): bool => $event['event_type'] !== 'SignalApplied'
            )
        );
        $run = $this->createRunFromFixture($prefix);
        $received = $fixture['history'][1]['payload'];
        $expectedApplied = $fixture['history'][3]['payload'];
        $instance = WorkflowInstance::query()->findOrFail($run->workflow_instance_id);
        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => 'signal',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'payload_codec' => 'avro',
            'payload' => Serializer::serializeWithCodec('avro', [
                'name' => 'append',
                'arguments' => ['first'],
            ]),
            'accepted_at' => now(),
        ]);
        $signal = WorkflowSignal::query()->create([
            'id' => $received['signal_id'],
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'run',
            'signal_name' => $received['signal_name'],
            'signal_wait_id' => $received['signal_wait_id'],
            'status' => 'received',
            'outcome' => 'signal_received',
            'payload_codec' => 'avro',
            'arguments' => $received['arguments'],
            'command_sequence' => $command->command_sequence,
            'received_at' => now(),
        ]);
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->sole();
        $task->forceFill([
            'payload' => WorkflowTaskPayload::forSignal($signal),
        ])->save();
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $this->assertTrue($bridge->claimStatus($task->id, 'buffered-signal-worker')['claimed']);
        $this->assertTrue($bridge->complete($task->id, [])['completed']);

        $applied = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalApplied->value)->sole();
        $this->assertSame($received['signal_id'], $applied->payload['signal_id']);
        $this->assertSame($expectedApplied['signal_wait_id'], $applied->payload['signal_wait_id']);
        $this->assertSame($expectedApplied['sequence'], $applied->payload['sequence']);
        $this->assertSame($expectedApplied['signal_wait_id'], $signal->fresh()->signal_wait_id);
        $cold = WorkflowFiberRunner::forClass(
            $fixture['workflow']['type'],
            $instance->id,
            $run->id,
            $fixture['workflow']['arguments'],
            'avro',
            HistoryExport::forRun($run->fresh())['history_events']
        )->step();
        $this->assertTrue($cold->completed);
        $this->assertSame($fixture['expected']['result'], $cold->result);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtures(): array
    {
        $paths = glob(self::FIXTURE_DIR . '/*.json') ?: [];
        sort($paths);

        return array_map(
            static fn (string $path): array => json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $paths,
        );
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function createRunFromFixture(array $fixture): WorkflowRun
    {
        $workflow = $fixture['workflow'];
        $stub = WorkflowStub::make(
            $workflow['type'],
            sprintf('regression-corpus-embedded-%d', ++$this->workflowNumber),
        );
        $stub->start(...$workflow['arguments']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($stub->runId());

        foreach ($fixture['history'] as $event) {
            $eventType = HistoryEventType::from($event['event_type']);
            if ($eventType === HistoryEventType::WorkflowStarted) {
                continue;
            }

            WorkflowHistoryEvent::record($run, $eventType, $event['payload']);
        }

        return $run;
    }

    /**
     * @param callable(): mixed $operation
     * @param array<string, mixed> $fixture
     */
    private function assertReplayFailure(callable $operation, array $fixture, string $consumer): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->assertSame(
                $fixture['expected_failure']['exception'],
                $exception::class,
                "{$fixture['id']} produced the wrong {$consumer} failure.",
            );

            return;
        }

        $this->fail("{$fixture['id']} was accepted by {$consumer} instead of failing closed.");
    }

    /**
     * @param callable(): mixed $operation
     * @param array<string, mixed> $fixture
     */
    private function replayFailureMismatch(callable $operation, array $fixture, string $consumer): ?string
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            if ($exception::class === $fixture['expected_failure']['exception']) {
                return null;
            }

            $exceptionClass = $exception::class;

            return "{$fixture['id']} produced the wrong {$consumer} failure [{$exceptionClass}].";
        }

        return "{$fixture['id']} was accepted by {$consumer} instead of failing closed.";
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertStepMatches(array $expected, WorkflowStep $actual, string $context): void
    {
        $this->assertSame($expected['completed'], $actual->completed, "{$context} completion mismatch.");
        $this->assertSame($expected['result'], $actual->result, "{$context} result mismatch.");
        $this->assertCount(count($expected['commands']), $actual->commands, "{$context} command count mismatch.");

        foreach ($expected['commands'] as $index => $expectedCommand) {
            $this->assertArrayContains($expectedCommand, $actual->commands[$index], "{$context} command {$index}");
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertArrayContains(array $expected, array $actual, string $context): void
    {
        foreach ($expected as $key => $expectedValue) {
            $this->assertArrayHasKey($key, $actual, "{$context} is missing [{$key}].");

            if (is_array($expectedValue) && is_array($actual[$key])) {
                $this->assertArrayContains($expectedValue, $actual[$key], "{$context}.{$key}");
                continue;
            }

            $this->assertSame($expectedValue, $actual[$key], "{$context}.{$key} mismatch.");
        }
    }

    private function runReadyWorkflowTask(WorkflowRun $run): void
    {
        $this->bindNoOpHistoryProjection();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertPortableCleanupDeadlineAfterColdReload(array $fixture): void
    {
        /** @var WorkflowTaskBridge $bridge */
        $bridge = $this->app->make(WorkflowTaskBridge::class);

        try {
            foreach (['heartbeat', 'completion'] as $boundary) {
                Carbon::setTestNow(Carbon::parse($fixture['history'][0]['recorded_at']));
                $stub = WorkflowStub::make(
                    $fixture['workflow']['type'],
                    sprintf('regression-corpus-deadline-%d', ++$this->workflowNumber),
                );
                $stub->start(...$fixture['workflow']['arguments']);
                /** @var WorkflowRun $run */
                $run = WorkflowRun::query()->findOrFail($stub->runId());
                $requestCommandId = null;

                foreach ($fixture['history'] as $event) {
                    Carbon::setTestNow(Carbon::parse($event['recorded_at']));
                    $eventType = HistoryEventType::from($event['event_type']);
                    if ($eventType === HistoryEventType::WorkflowStarted) {
                        continue;
                    }
                    if ($eventType === HistoryEventType::CooperativeCancellationRequested) {
                        $request = $stub->requestCancellation('cleanup deadline corpus', 60);
                        $this->assertTrue($request->accepted());
                        $requestCommandId = $request->commandId();
                        $run->refresh();
                        continue;
                    }

                    $payload = $event['payload'];
                    if (isset($payload['workflow_command_id'])) {
                        $payload['workflow_command_id'] = $requestCommandId;
                    }
                    WorkflowHistoryEvent::record($run, $eventType, $payload);
                }

                $run = $run->fresh();
                $this->assertNotNull($run);
                $deadline = $run->cancellation_deadline_at;
                $this->assertNotNull($deadline);
                /** @var WorkflowTask $task */
                $task = $run->tasks()
                    ->where('task_type', TaskType::Workflow->value)->sole();
                $this->assertTrue($bridge->claimStatus($task->id, 'cold-cleanup-worker')['claimed']);

                Carbon::setTestNow($deadline);
                $result = $boundary === 'heartbeat'
                    ? $bridge->heartbeat($task->id)
                    : $bridge->complete($task->id, [[
                        'type' => 'start_timer',
                        'delay_seconds' => 1,
                    ]]);
                $this->assertFalse($result[$boundary === 'heartbeat' ? 'renewed' : 'completed']);
                $this->assertSame('cancelled', $result['run_status']);
                $this->assertSame('cancelled', $run->refresh()->status->value);
                $this->assertNull($run->output);
                $this->assertSame(1, $run->historyEvents()
                    ->where('event_type', HistoryEventType::WorkflowCancelled->value)
                    ->where('workflow_command_id', $requestCommandId)
                    ->count());
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * The portable bridge resumes the frozen scope prefix. Embedded scope
     * authoring remains unavailable, as the fixture's cold replay asserts.
     *
     * @param array<string, mixed> $fixture
     */
    private function assertRunInheritedScopedCleanupAfterColdReload(array $fixture): void
    {
        $this->clearWorkflowState();
        Carbon::setTestNow(Carbon::parse($fixture['history'][0]['recorded_at']));
        try {
            $stub = WorkflowStub::make(
                $fixture['workflow']['type'],
                sprintf('regression-corpus-inherited-cleanup-%d', ++$this->workflowNumber),
            );
            $stub->start(...$fixture['workflow']['arguments']);
            $run = WorkflowRun::query()->findOrFail($stub->runId());
            foreach (array_slice($fixture['history'], 1) as $event) {
                // Only the synthetic run identity changes on import.
                WorkflowHistoryEvent::record($run, HistoryEventType::CancellationScopeOpened, [
                    ...$event['payload'],
                    'workflow_run_id' => $run->id,
                ]);
            }
            $scope = $fixture['history'][1]['payload']['scope_id'];
            $child = $fixture['history'][2]['payload']['scope_id'];
            DB::purge();
            DB::reconnect();
            $run = $run->fresh();
            $this->assertSame([$scope, $child], array_keys(CancellationScopeHistory::forRun($run)));
            $task = $run->tasks()
                ->where('task_type', TaskType::Workflow)->sole();
            $task->forceFill([
                'status' => TaskStatus::Leased,
                'lease_owner' => 'cold-scoped-cleanup',
                'attempt_count' => 1,
                'lease_expires_at' => now()
                    ->addSeconds(60),
            ])->save();
            $this->assertTrue($stub->requestCancellation('clean up the run', 30)->accepted());
            $run->refresh();
            $originalRequest = $run->cancellation_request_command_id;
            $originalDeadline = $run->cancellation_deadline_at->toISOString();
            $request = CancellationScopeRequests::request($run, $scope, '1.20', 300);
            CancellationScopeDelivery::prepare(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                3,
                'local_activity',
                '1.20'
            );
            $delivery = CancellationScopeDelivery::record(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                3,
                'local_activity',
                '1.20'
            );
            $descriptor = [
                'type' => 'record_local_activity',
                'activity_type' => 'tests.scoped-cleanup',
                'arguments' => Serializer::serializeWithCodec('avro', []),
                'payload_codec' => 'avro',
                'cancellation_scope_id' => $child,
                'cancellation_cleanup' => [
                    'scope_id' => $scope,
                    'request_id' => $request->payload['request_id'],
                    'delivery_history_event_id' => $delivery->id,
                ],
            ];
            $bridge = $this->app->make(DefaultWorkflowTaskBridge::class);
            $prepared = $bridge->prepareLocalActivity(
                $task->id,
                'cold-scoped-cleanup',
                1,
                4,
                'inherited-cleanup',
                $descriptor,
                '1.20'
            );
            $this->assertTrue($prepared['prepared'], $prepared['reason'] ?? '');
            $snapshot = $prepared['cancellation_cleanup'];
            $this->assertSame($originalRequest, $snapshot['root_request_id']);
            $this->assertSame($originalDeadline, $snapshot['cleanup_deadline_at']);
            DB::purge();
            DB::reconnect();
            foreach ([8, 8, 8] as $seconds) {
                Carbon::setTestNow(now()->addSeconds($seconds));
                $control = $bridge->controlLocalActivity(
                    $prepared['activity_attempt_id'],
                    'cold-scoped-cleanup',
                    1,
                    true,
                    '1.20'
                );
                $this->assertTrue($control['active'], $control['reason'] ?? '');
            }
            $this->assertSame($originalDeadline, $task->fresh()->lease_expires_at->toISOString());
            $completed = $bridge->recordLocalActivityOutcome(
                $prepared['activity_attempt_id'],
                'cold-scoped-cleanup',
                1,
                [
                    'outcome' => 'completed',
                    'result' => Serializer::serializeWithCodec('avro', 'cleaned'),
                    'payload_codec' => 'avro',
                ],
                '1.20'
            );
            $this->assertTrue($completed['recorded'], $completed['reason'] ?? '');
            $this->assertTrue($stub->requestCancellation('duplicate with longer budget', 300)->accepted());
            $run->refresh();
            $this->assertSame($originalRequest, $run->cancellation_request_command_id);
            $this->assertSame($originalDeadline, $run->cancellation_deadline_at->toISOString());
            $this->assertTrue($bridge->deliverCancellation($task->id, $originalRequest, 5, 'timer')['delivered']);
            $this->assertTrue($bridge->complete($task->id, [[
                'type' => 'complete_workflow',
                'output' => Serializer::serializeWithCodec('avro', 'cleaned'),
                'payload_codec' => 'avro',
            ]])['completed']);
            $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
            $this->assertTrue(now()->lt($run->cancellation_deadline_at));
            $this->assertSame(
                1,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
            );
            $this->assertSame(
                1,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::CooperativeCancellationDelivered)->count()
            );
            $this->assertSame(
                0,
                $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityHeartbeatRecorded)->count()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function bindNoOpHistoryProjection(): void
    {
        $this->app->instance(HistoryProjectionRole::class, new class() implements HistoryProjectionRole {
            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                return new WorkflowRunSummary();
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->projectRun($run);
            }
        });
    }
}
