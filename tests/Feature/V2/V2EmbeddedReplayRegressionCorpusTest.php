<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Uid\Ulid;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConditionWaits;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2EmbeddedReplayRegressionCorpusTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/V2/ReplayRegression';

    private int $workflowNumber = 0;

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

            if (($fixture['id'] ?? null) === 'adjacent-condition-wait-occurrence-identity') {
                $this->assertAdjacentConditionWaitOccurrencesRemainDistinct($fixture);
            }

            if (($fixture['id'] ?? null) === 'parallel-child-group-final-sibling-release') {
                $this->assertLegacyParallelChildRawHistoryGap($fixture);
            }

            if (($fixture['id'] ?? null) === 'parallel-child-group-durable-command-sequence') {
                $this->assertStandaloneParallelChildBarrierFixture($fixture);
            }

            if (($fixture['id'] ?? null) === 'signal-resumed-mixed-group-command-sequence') {
                $this->assertSignalResumedMixedGroupCommandSequenceFixture($fixture);
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
                $expectedReplayBlock = $fixture['expected_failure']['exception']
                    === HistoryEventShapeMismatchException::class
                    && ($blockedPayload['replay_blocked'] ?? false) === true
                    && ($blockedPayload['replay_blocked_reason'] ?? null) === 'history_shape_mismatch';

                if ($failure === null) {
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
        $this->assertSame([
            [
                'child' => 'first',
            ],
            [
                'child' => 'second',
            ],
        ], $coldReplay->result['children'] ?? null);
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

    private function clearWorkflowState(): void
    {
        foreach ([
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
