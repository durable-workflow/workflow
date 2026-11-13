<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestParentFailingChildWorkflow;
use Tests\Fixtures\V2\TestRedriveBuggyWorkflow;
use Tests\Fixtures\V2\TestRedriveChildWorkflow;
use Tests\Fixtures\V2\TestRedriveFinishesEarlyWorkflow;
use Tests\Fixtures\V2\TestRedriveFixedWorkflow;
use Tests\Fixtures\V2\TestRedriveIncompatibleWorkflow;
use Tests\Fixtures\V2\TestRedriveMarkersWorkflow;
use Tests\Fixtures\V2\TestRedrivePatchedWorkflow;
use Tests\Fixtures\V2\TestRedriveRetryProbeWorkflow;
use Tests\Fixtures\V2\TestRedriveSignalWorkflow;
use Tests\Fixtures\V2\TestRedriveTimerWorkflow;
use Tests\Fixtures\V2\TestRedriveTwoSignalsWorkflow;
use Tests\Fixtures\V2\TestRedriveUpdateWorkflow;
use Tests\Fixtures\V2\TestRedriveVersionedBuggyWorkflow;
use Tests\Fixtures\V2\TestRedriveVersionedIncompatibleWorkflow;
use Tests\Fixtures\V2\TestRedriveVisibilityWorkflow;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\Fixtures\V2\TestThrowAfterGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\FailedRunRedrivePlan;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunTimerView;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2FailedRunRedriveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');

        Cache::forget('test:redrive:first-calls');
        Cache::forget('test:redrive:second-calls');
    }

    public function testFailedRunReusesCompletedActivityAndRetriesOnlyFailedActivity(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-activity-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());
        $failedRunId = $workflow->runId();
        $originalHistoryCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $failedRunId)
            ->count();
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, (int) Cache::get('test:redrive:second-calls'));

        $controlPlane = app(WorkflowControlPlane::class);
        $source = WorkflowRun::query()->findOrFail($failedRunId);
        $source->forceFill([
            'execution_deadline_at' => now()
                ->subSecond(),
        ])->save();
        $expired = $controlPlane->redrive('redrive-activity-1', $failedRunId);
        $this->assertFalse($expired['accepted']);
        $this->assertSame('execution_deadline_elapsed', $expired['reason']);
        $source->forceFill([
            'execution_deadline_at' => null,
        ])->save();

        $foreignNamespace = $controlPlane->redrive('redrive-activity-1', $failedRunId, [
            'namespace' => 'another-namespace',
        ]);
        $this->assertFalse($foreignNamespace['accepted']);
        $this->assertSame('instance_not_found', $foreignNamespace['reason']);

        $result = $controlPlane->redrive('redrive-activity-1', $failedRunId, [
            'request_id' => 'retry-payment-once',
        ]);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertNotSame($failedRunId, $result['workflow_run_id']);
        $this->assertSame($failedRunId, $result['continued_from_run_id']);
        $this->assertSame(2, $result['resume_step_sequence']);

        $repeated = $controlPlane->redrive('redrive-activity-1', $failedRunId, [
            'request_id' => 'retry-payment-once',
        ]);
        $this->assertSame($result['workflow_run_id'], $repeated['workflow_run_id']);
        $this->assertSame(2, WorkflowRun::query()->where('workflow_instance_id', 'redrive-activity-1')->count());
        $this->assertSame(1, WorkflowCommand::query()
            ->where('workflow_instance_id', 'redrive-activity-1')
            ->where('command_type', 'redrive')
            ->count());

        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, (int) Cache::get('test:redrive:second-calls'));
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($failedRunId)->status->value);
        $this->assertSame(
            $originalHistoryCount,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $failedRunId)->count(),
        );

        $describe = $controlPlane->describe('redrive-activity-1', [
            'run_id' => $result['workflow_run_id'],
        ]);
        $this->assertSame('completed', $describe['run']['status']);
        $this->assertSame($failedRunId, $describe['run']['continued_from_run_id']);
        $this->assertSame(2, $describe['run']['resume_step_sequence']);

        $reused = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $result['workflow_run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame($failedRunId, $reused->payload['reused_from_run_id']);

        $sourceRun = WorkflowRun::query()->findOrFail($failedRunId);
        $successorRun = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $sourceCompletion = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $failedRunId)
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame($sourceRun->started_at?->toIso8601String(), $successorRun->workflowOutput()['time_at_start']);
        $this->assertSame(
            $sourceCompletion->recorded_at?->toIso8601String(),
            $successorRun->workflowOutput()['time_after_first_activity'],
        );

        $activities = RunActivityView::activitiesForRun(WorkflowRun::query()->findOrFail($result['workflow_run_id']));
        $this->assertSame($failedRunId, $activities[0]['reused_from_run_id']);
        $this->assertNull($activities[1]['reused_from_run_id']);

        $export = HistoryExport::forRun($successorRun->fresh());
        $this->assertSame($failedRunId, $export['activities'][0]['reused_from_run_id']);
        $this->assertNull($export['activities'][1]['reused_from_run_id']);

        $portableExport = json_decode(json_encode($export, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $replayed = (new WorkflowReplayer())->replayExport($portableExport);
        $this->assertNull($replayed->current);
        $this->assertSame(3, $replayed->sequence);
        $this->assertSame([
            'time_at_start' => $successorRun->workflowOutput()['time_at_start'],
            'time_after_first_activity' => $successorRun->workflowOutput()['time_after_first_activity'],
        ], $replayed->workflow->replayTimes());

        DB::disconnect();
        DB::reconnect();
        $reloadedRun = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(
            $replayed->workflow->replayTimes(),
            (new WorkflowReplayer())->replay($reloadedRun)
                ->workflow->replayTimes()
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function retriedActivityCases(): array
    {
        return [
            'retried failed boundary' => [0],
            'retried completed prefix and failed boundary' => [1],
        ];
    }

    #[DataProvider('retriedActivityCases')]
    public function testOrdinaryRetriedActivitiesRedriveWithoutRepeatingTheCompletedPrefix(int $firstFailures): void
    {
        config()
            ->set('queue.default', 'redis');
        Queue::fake();
        config([
            'workflows.v2.testing.redrive_retry_failures' => [
                'first' => $firstFailures,
                'second' => 2,
            ],
        ]);
        $counter = 'test:redrive:retry-probe:' . $firstFailures;
        Cache::forget($counter . ':first');
        Cache::forget($counter . ':second');
        $workflow = WorkflowStub::make(TestRedriveRetryProbeWorkflow::class, 'redrive-retry-probe-' . $firstFailures);
        $workflow->start($counter, new StartOptions(executionTimeoutSeconds: 600));
        $source = $this->drainRetryProbe($workflow->runId());
        $this->assertSame(RunStatus::Failed, $source->status);
        $this->assertSame(1 + $firstFailures, (int) Cache::get($counter . ':first'));
        $this->assertSame(2, (int) Cache::get($counter . ':second'));
        $history = $source->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->toArray();

        $control = app(WorkflowControlPlane::class);
        $redrive = $control->redrive($source->workflow_instance_id, $source->id, [
            'request_id' => 'retry-probe',
        ]);
        $this->assertTrue($redrive['accepted'], (string) $redrive['reason']);
        $this->assertSame(2, $redrive['resume_step_sequence']);
        $successor = $this->drainRetryProbe($redrive['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame('value:first:second', $successor->workflowOutput());
        $this->assertSame(1 + $firstFailures, (int) Cache::get($counter . ':first'));
        $this->assertSame(3, (int) Cache::get($counter . ':second'));
        $this->assertSame(
            $source->execution_deadline_at->toISOString(),
            $successor->execution_deadline_at->toISOString()
        );
        $this->assertSame($history, $source->historyEvents()->orderBy('sequence')->get()->toArray());
        $repeated = $control->redrive($source->workflow_instance_id, $source->id, [
            'request_id' => 'retry-probe',
        ]);
        $this->assertSame($successor->id, $repeated['workflow_run_id']);
        $this->assertSame(3, (int) Cache::get($counter . ':second'));
    }

    public function testRedriveCarriesStartMemoAndSearchAttributesToTheSuccessor(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-visibility-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withMemo([
                    'customer' => 'Taylor',
                    'attempt_note' => 'first deploy',
                ])
                ->withSearchAttributes([
                    'region' => 'us-east',
                ]),
        );

        $this->assertTrue($workflow->refresh()->failed());
        $failedRunId = $workflow->runId();
        $source = WorkflowRun::query()->findOrFail($failedRunId);
        $sourceMemos = $source->typedMemos();
        $sourceAttributes = $source->typedSearchAttributes();
        $this->assertNotSame([], $sourceMemos);
        $this->assertSame('us-east', $sourceAttributes['region'] ?? null);

        $result = app(WorkflowControlPlane::class)->redrive('redrive-visibility-1', $failedRunId);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame('completed', $successor->status->value);
        $this->assertSame($sourceMemos, $successor->typedMemos());
        $this->assertSame($sourceAttributes, $successor->typedSearchAttributes());
        $this->assertSame($sourceMemos, WorkflowRun::query()->findOrFail($failedRunId)->typedMemos());
        $this->assertSame(
            'us-east',
            WorkflowRunSummary::query()->findOrFail($successor->id)->getTypedSearchAttributes()['region'] ?? null,
        );

        $sourceStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $failedRunId)
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $successorStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $this->assertSame($sourceStarted->payload['memo'] ?? null, $successorStarted->payload['memo'] ?? null);
        $this->assertSame(
            $sourceStarted->payload['search_attributes'] ?? null,
            $successorStarted->payload['search_attributes'] ?? null,
        );

        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, (int) Cache::get('test:redrive:second-calls'));
    }

    public function testCompletedRunIsNotEligibleForRedrive(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'redrive-completed-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $result = app(WorkflowControlPlane::class)->redrive(
            'redrive-completed-1',
            $workflow->runId(),
            [
                'request_id' => 'invalid-redrive',
            ],
        );

        $this->assertFalse($result['accepted']);
        $this->assertSame('run_not_failed', $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-completed-1')->count());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function serviceRetryHistories(): array
    {
        return [
            'ordinary prefix' => [false],
            'retried completed prefix' => [true],
        ];
    }

    #[DataProvider('serviceRetryHistories')]
    public function testServiceRunRequiresMatchingRecordedWorkerDefinitionFingerprint(bool $retriedPrefix): void
    {
        Queue::fake();

        $controlPlane = app(WorkflowControlPlane::class);
        $start = $controlPlane->start('remote.redrive', 'redrive-service-1', [
            'namespace' => 'default',
            'queue' => 'service-workflows',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'external_workflow_definition_fingerprint' => 'worker-definition-1',
            'execution_timeout_seconds' => 3600,
            'run_timeout_seconds' => 600,
        ]);
        $this->assertTrue($start['started']);

        $source = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame('remote.redrive', $source->workflow_class);
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $this->assertSame('worker-definition-1', $started->payload['workflow_definition_fingerprint']);
        $this->assertSame('worker', $started->payload['workflow_definition_fingerprint_source']);

        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'service-activity-1',
            'activity_type' => 'remote.first',
            'sequence' => 1,
        ]);
        if ($retriedPrefix) {
            WorkflowHistoryEvent::record($source, HistoryEventType::ActivityStarted, [
                'activity_execution_id' => 'service-activity-1',
                'activity_type' => 'remote.first',
                'activity_attempt_id' => 'service-attempt-1',
                'workflow_task_id' => 'service-activity-task-1',
                'attempt_number' => 1,
                'sequence' => 1,
            ]);
            WorkflowHistoryEvent::record($source, HistoryEventType::ActivityRetryScheduled, [
                'activity_execution_id' => 'service-activity-1',
                'activity_type' => 'remote.first',
                'activity_attempt_id' => 'service-attempt-1',
                'retry_after_attempt_id' => 'service-attempt-1',
                'retry_after_attempt' => 1,
                'retry_of_task_id' => 'service-activity-task-1',
                'retry_task_id' => 'service-activity-task-2',
                'max_attempts' => 2,
                'sequence' => 1,
            ]);
            WorkflowHistoryEvent::record($source, HistoryEventType::ActivityStarted, [
                'activity_execution_id' => 'service-activity-1',
                'activity_type' => 'remote.first',
                'activity_attempt_id' => 'service-attempt-2',
                'workflow_task_id' => 'service-activity-task-2',
                'attempt_number' => 2,
                'sequence' => 1,
            ]);
        }
        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'service-activity-1',
            'activity_type' => 'remote.first',
            'sequence' => 1,
            'result' => Serializer::serializeWithCodec('avro', 'first-result'),
            'payload_codec' => 'avro',
            ...($retriedPrefix ? [
                'activity_attempt_id' => 'service-attempt-2',
                'attempt_number' => 2,
            ] : []),
        ]);
        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => 'service-activity-2',
            'activity_type' => 'remote.second',
            'sequence' => 2,
        ]);
        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityFailed, [
            'activity_execution_id' => 'service-activity-2',
            'activity_type' => 'remote.second',
            'sequence' => 2,
            'message' => 'temporary failure',
        ]);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $source->id)
            ->firstOrFail();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'service-worker',
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $source->forceFill([
            'status' => RunStatus::Waiting,
        ])->save();
        $failure = app(DefaultWorkflowTaskBridge::class)->complete($task->id, [[
            'type' => 'fail_workflow',
            'message' => 'temporary failure',
            'failed_step_sequence' => 2,
            'failed_activity_execution_id' => 'service-activity-2',
        ]]);
        $this->assertTrue($failure['completed']);

        $startedPayload = $started->payload;
        unset($startedPayload['workflow_definition_fingerprint']);
        $started->forceFill([
            'payload' => $startedPayload,
        ])->save();
        $unrecorded = $controlPlane->redrive('redrive-service-1', $source->id, [
            'namespace' => 'default',
            'external_workflow_definition_fingerprint' => 'worker-definition-1',
        ]);
        $this->assertSame('workflow_definition_unavailable_or_changed', $unrecorded['reason']);
        $started->forceFill([
            'payload' => array_merge($startedPayload, [
                'workflow_definition_fingerprint' => 'worker-definition-1',
            ]),
        ])->save();

        $unverified = $controlPlane->redrive('redrive-service-1', $source->id, [
            'namespace' => 'default',
        ]);
        $this->assertSame('workflow_definition_unavailable_or_changed', $unverified['reason']);
        $changed = $controlPlane->redrive('redrive-service-1', $source->id, [
            'namespace' => 'default',
            'external_workflow_definition_fingerprint' => 'worker-definition-2',
        ]);
        $this->assertSame('workflow_definition_unavailable_or_changed', $changed['reason']);

        $redrive = $controlPlane->redrive('redrive-service-1', $source->id, [
            'namespace' => 'default',
            'external_workflow_definition_fingerprint' => 'worker-definition-1',
        ]);
        $this->assertTrue($redrive['accepted'], (string) $redrive['reason']);
        $this->assertSame(2, $redrive['resume_step_sequence']);
        $this->assertSame(RunStatus::Failed, $source->fresh()->status);
        $this->assertSame('service-workflows', WorkflowRun::query()->findOrFail($redrive['workflow_run_id'])->queue);
        $successor = WorkflowRun::query()->findOrFail($redrive['workflow_run_id']);
        $this->assertSame(
            $source->fresh()
                ->execution_deadline_at?->toIso8601String(),
            $successor->execution_deadline_at?->toIso8601String(),
        );
        $this->assertSame(600, $successor->run_timeout_seconds);
        $this->assertTrue($successor->run_deadline_at->gt($source->fresh()->run_deadline_at));
        $this->assertSame(600.0, $successor->started_at->diffInSeconds($successor->run_deadline_at));
        $successorStarted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $redrive['workflow_run_id'])
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $this->assertSame('worker', $successorStarted->payload['workflow_definition_fingerprint_source']);
        $this->assertSame(
            $successor->run_deadline_at->toIso8601String(),
            $successorStarted->payload['run_deadline_at']
        );
    }

    public function testWorkflowCodeFailureOnTheSameDefinitionIsNotRedriven(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestThrowAfterGreetingWorkflow::class, 'redrive-ambiguous-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        $result = app(WorkflowControlPlane::class)->redrive('redrive-ambiguous-1', $workflow->runId());

        // The boundary is recorded, but the same code would throw there again.
        $this->assertFalse($result['accepted']);
        $this->assertSame('workflow_code_failure_requires_corrected_definition', $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-ambiguous-1')->count());
    }

    public function testRedriveReusesSideEffectsAndVersionMarkersAtTheirRecordedTime(): void
    {
        WorkflowStub::fake();
        Cache::forget('test:redrive:side-effect-calls');

        $workflow = WorkflowStub::make(TestRedriveMarkersWorkflow::class, 'redrive-markers-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $sourceRunId = $workflow->runId();
        $this->assertSame(1, (int) Cache::get('test:redrive:side-effect-calls'));
        $sourceEvent = static fn (HistoryEventType $type): WorkflowHistoryEvent => WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $sourceRunId)
            ->where('event_type', $type)
            ->firstOrFail();
        $sourceSideEffect = $sourceEvent(HistoryEventType::SideEffectRecorded);
        $sourceMarker = $sourceEvent(HistoryEventType::VersionMarkerRecorded);

        // Redrive later than the source, so a successor that used its own
        // event times would report different workflow times.
        $this->travel(5)
            ->minutes();
        $result = app(WorkflowControlPlane::class)->redrive('redrive-markers-1', $sourceRunId);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(4, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:side-effect-calls'));
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));

        $successorEvent = static fn (HistoryEventType $type): WorkflowHistoryEvent => WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->where('event_type', $type)
            ->firstOrFail();
        $reusedSideEffect = $successorEvent(HistoryEventType::SideEffectRecorded);
        $reusedMarker = $successorEvent(HistoryEventType::VersionMarkerRecorded);
        $this->assertSame($sourceSideEffect->payload['result'], $reusedSideEffect->payload['result']);
        $this->assertSame(1, $reusedSideEffect->payload['sequence']);
        $this->assertSame(2, $reusedMarker->payload['sequence']);
        $this->assertSame($sourceMarker->payload['version'], $reusedMarker->payload['version']);
        foreach ([[$sourceSideEffect, $reusedSideEffect], [$sourceMarker, $reusedMarker]] as [$original, $reused]) {
            $this->assertSame($sourceRunId, $reused->payload['reused_from_run_id'] ?? null);
            $this->assertReplayClock(
                $original->recorded_at,
                $reused->payload['reused_recorded_at'] ?? null,
            );
        }

        $expectedTimes = [
            'time_after_side_effect' => $sourceSideEffect->recorded_at?->toIso8601String(),
            'time_after_version' => $sourceMarker->recorded_at?->toIso8601String(),
        ];
        $output = $successor->workflowOutput();
        $this->assertSame('Hello, Taylor! Redrive completed.', $output['result']);
        $this->assertSame(1, $output['version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $output['token']);
        $this->assertSame($expectedTimes, [
            'time_after_side_effect' => $output['time_after_side_effect'],
            'time_after_version' => $output['time_after_version'],
        ]);

        $export = json_decode(
            json_encode(HistoryExport::forRun($successor->fresh()), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($expectedTimes, (new WorkflowReplayer())->replayExport($export)->workflow->replayTimes());
        $this->assertSame(
            $expectedTimes,
            (new WorkflowReplayer())->replay($successor->fresh())
                ->workflow->replayTimes(),
        );

        $history = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])
            ->all();
        $fiber = WorkflowFiberRunner::forClass(
            TestRedriveMarkersWorkflow::class,
            'redrive-markers-1',
            $successor->id,
            ['Taylor'],
            (string) $successor->payload_codec,
            $history,
        )->step();
        $this->assertTrue($fiber->completed);
        $this->assertSame($output, $fiber->result);
    }

    public function testRedriveReusesWorkflowCodeMemoAndSearchAttributeUpserts(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveVisibilityWorkflow::class, 'redrive-upserts-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $sourceRunId = $workflow->runId();
        $source = WorkflowRun::query()->findOrFail($sourceRunId);
        $sourceEvent = static fn (HistoryEventType $type): WorkflowHistoryEvent => WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $sourceRunId)
            ->where('event_type', $type)
            ->firstOrFail();
        $sourceMemoEvent = $sourceEvent(HistoryEventType::MemoUpserted);
        $sourceAttributesEvent = $sourceEvent(HistoryEventType::SearchAttributesUpserted);
        $rows = static fn (WorkflowRun $run): array => [
            'memos' => $run->memos()
                ->orderBy('key')
                ->get()
                ->map(static fn ($memo): array => [
                    $memo->key,
                    $memo->getValue(),
                    $memo->upserted_at_sequence,
                    (bool) $memo->inherited_from_parent,
                ])
                ->all(),
            'search_attributes' => $run->searchAttributes()
                ->orderBy('key')
                ->get()
                ->map(static fn ($attribute): array => [
                    $attribute->key,
                    $attribute->type,
                    $attribute->upserted_at_sequence,
                    (bool) $attribute->inherited_from_parent,
                ])
                ->all(),
        ];
        $sourceRows = $rows($source);
        $this->assertSame([
            ['customer', 'Taylor', 1, false],
            ['stage', 'greeting', 1, false],
        ], $sourceRows['memos']);
        $this->assertSame([['Stage', 'keyword', 2, false]], $sourceRows['search_attributes']);

        $this->travel(5)
            ->minutes();
        $result = app(WorkflowControlPlane::class)->redrive('redrive-upserts-1', $sourceRunId);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(4, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame($sourceRows, $rows($successor->fresh()));
        $this->assertSame($source->typedMemos(), $successor->typedMemos());
        $this->assertSame($source->typedSearchAttributes(), $successor->typedSearchAttributes());

        foreach ([
            [$sourceMemoEvent, HistoryEventType::MemoUpserted],
            [$sourceAttributesEvent, HistoryEventType::SearchAttributesUpserted],
        ] as [$original, $type]) {
            $reused = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $successor->id)
                ->where('event_type', $type)
                ->sole();
            $payload = $reused->payload;
            $this->assertSame($sourceRunId, $payload['reused_from_run_id'] ?? null);
            $this->assertReplayClock($original->recorded_at, $payload['reused_recorded_at'] ?? null);
            unset($payload['reused_from_run_id'], $payload['reused_recorded_at']);
            // The source's task and command are its recording context, not the step.
            $expected = $original->payload;
            unset($expected['task'], $expected['command']);
            $this->assertSame($expected, $payload);
        }

        $expectedTimes = [
            'time_after_memo' => $sourceMemoEvent->recorded_at?->toIso8601String(),
            'time_after_search_attributes' => $sourceAttributesEvent->recorded_at?->toIso8601String(),
        ];
        $output = $successor->workflowOutput();
        $this->assertSame('Hello, Taylor! Redrive completed.', $output['result']);
        $this->assertSame($expectedTimes, [
            'time_after_memo' => $output['time_after_memo'],
            'time_after_search_attributes' => $output['time_after_search_attributes'],
        ]);
        $export = json_decode(
            json_encode(HistoryExport::forRun($successor->fresh()), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($expectedTimes, (new WorkflowReplayer())->replayExport($export)->workflow->replayTimes());
        $this->assertSame(
            $expectedTimes,
            (new WorkflowReplayer())->replay($successor->fresh())
                ->workflow->replayTimes(),
        );
    }

    public function testARedriveSuccessorWithUpsertsCanBeRedrivenAgain(): void
    {
        WorkflowStub::fake();
        config([
            'workflows.v2.testing.redrive_failures_before_success' => 2,
        ]);

        $workflow = WorkflowStub::make(TestRedriveVisibilityWorkflow::class, 'redrive-upserts-chain');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $firstRunId = $workflow->runId();
        $memoRecordedAt = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $firstRunId)
            ->where('event_type', HistoryEventType::MemoUpserted)
            ->sole()
            ->recorded_at;

        $second = app(WorkflowControlPlane::class)->redrive('redrive-upserts-chain', $firstRunId);
        $this->assertTrue($second['accepted'], (string) $second['reason']);
        $secondRun = WorkflowRun::query()->findOrFail($second['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $secondRun->status);

        $this->travel(5)
            ->minutes();
        $third = app(WorkflowControlPlane::class)->redrive('redrive-upserts-chain', $secondRun->id);

        $this->assertTrue($third['accepted'], (string) $third['reason']);
        $thirdRun = WorkflowRun::query()->findOrFail($third['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $thirdRun->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(WorkflowRun::query()->findOrFail($firstRunId)->typedMemos(), $thirdRun->typedMemos());
        $this->assertSame(
            WorkflowRun::query()->findOrFail($firstRunId)->typedSearchAttributes(),
            $thirdRun->typedSearchAttributes(),
        );
        $reusedMemo = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $thirdRun->id)
            ->where('event_type', HistoryEventType::MemoUpserted)
            ->sole();
        // A step reused twice keeps the run it came from and its first time.
        $this->assertSame($secondRun->id, $reusedMemo->payload['reused_from_run_id']);
        $this->assertReplayClock($memoRecordedAt, $reusedMemo->payload['reused_recorded_at']);
        $this->assertSame($memoRecordedAt?->toIso8601String(), $thirdRun->workflowOutput()['time_after_memo']);
    }

    public function testRedriveReusesAnAppliedSignalWaitAtItsRecordedTime(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveSignalWorkflow::class, 'redrive-signal-1');
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $this->assertTrue($workflow->refresh()->failed());
        $sourceRunId = $workflow->runId();
        $sourceEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $sourceRunId)
            ->whereIn('event_type', [
                HistoryEventType::SignalWaitOpened,
                HistoryEventType::SignalReceived,
                HistoryEventType::SignalApplied,
            ])
            ->orderBy('sequence')
            ->get();
        $this->assertCount(3, $sourceEvents);
        $sourceApplied = $sourceEvents->firstWhere('event_type', HistoryEventType::SignalApplied);

        $this->travel(5)
            ->minutes();
        $result = app(WorkflowControlPlane::class)->redrive('redrive-signal-1', $sourceRunId);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(3, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));

        $reused = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->whereIn('event_type', [
                HistoryEventType::SignalWaitOpened,
                HistoryEventType::SignalReceived,
                HistoryEventType::SignalApplied,
            ])
            ->orderBy('sequence')
            ->get();
        $this->assertSame(
            ['SignalWaitOpened', 'SignalReceived', 'SignalApplied'],
            $reused->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
                ->all(),
        );
        foreach ($reused as $event) {
            $original = $sourceEvents->firstWhere('event_type', $event->event_type);
            $this->assertSame($sourceRunId, $event->payload['reused_from_run_id'] ?? null);
            $this->assertReplayClock(
                $original->recorded_at,
                $event->payload['reused_recorded_at'] ?? null,
            );
        }
        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $successor->id)->count());
        // The consumed message stays consumed for the successor.
        $this->assertGreaterThan(0, (int) WorkflowRun::query()->findOrFail($sourceRunId)->message_cursor_position);
        $this->assertSame(
            (int) WorkflowRun::query()->findOrFail($sourceRunId)->message_cursor_position,
            (int) $successor->message_cursor_position,
        );

        $expectedTime = $sourceApplied->recorded_at?->toIso8601String();
        $output = $successor->workflowOutput();
        $this->assertSame('Hello, Taylor! Redrive completed.', $output['result']);
        $this->assertSame('Grace', $output['approved_by']);
        $this->assertSame($expectedTime, $output['time_after_signal']);
        $export = json_decode(
            json_encode(HistoryExport::forRun($successor->fresh()), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame($expectedTime, (new WorkflowReplayer())->replayExport($export)->workflow->timeAfterSignal());
        $this->assertSame(
            $expectedTime,
            (new WorkflowReplayer())->replay($successor->fresh())
                ->workflow->timeAfterSignal(),
        );
    }

    public function testRedriveRefusesASignalTheSourceReceivedButNeverApplied(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveSignalWorkflow::class, 'redrive-signal-pending');
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $applied = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', HistoryEventType::SignalReceived)
            ->sole();
        // A second receipt the workflow never consumed.
        $receipt = $applied->payload;
        unset($receipt['task'], $receipt['command']);
        WorkflowHistoryEvent::record($source, HistoryEventType::SignalReceived, array_merge($receipt, [
            'signal_id' => 'never-applied-signal',
        ]));
        // Place the receipt before the terminal failure, as a buffered signal.
        $failure = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', HistoryEventType::WorkflowFailed)
            ->sole();
        $failureSequence = $failure->sequence;
        $pending = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', HistoryEventType::SignalReceived)
            ->orderByDesc('sequence')
            ->first();
        $failure->forceFill([
            'sequence' => $pending->sequence + 1,
        ])->save();
        $pending->forceFill([
            'sequence' => $failureSequence,
        ])->save();

        $result = app(WorkflowControlPlane::class)->redrive('redrive-signal-pending', $source->id);

        $this->assertFalse($result['accepted']);
        $this->assertSame('unsupported_pending_signal', $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-signal-pending')->count());
    }

    /**
     * @return iterable<string, array{HistoryEventType, string, mixed, string}>
     */
    public static function signalStepMutations(): iterable
    {
        $external = [
            'codec' => 'avro',
            'external_storage' => [
                'uri' => 's3://bucket/signal-payload',
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 4096,
                'codec' => 'avro',
            ],
        ];

        yield 'receipt for another wait' => [
            HistoryEventType::SignalReceived, 'signal_wait_id', 'another-wait', 'mismatched_signal_receipt',
        ];
        yield 'receipt for another command' => [
            HistoryEventType::SignalReceived, 'workflow_command_id', 'another-command', 'mismatched_signal_receipt',
        ];
        yield 'receipt for another run' => [
            HistoryEventType::SignalReceived, 'workflow_run_id', 'another-run', 'mismatched_signal_receipt',
        ];
        yield 'receipt for another instance' => [
            HistoryEventType::SignalReceived, 'workflow_instance_id', 'another-instance', 'mismatched_signal_receipt',
        ];
        yield 'external receipt arguments' => [
            HistoryEventType::SignalReceived, 'arguments', $external, 'external_signal_payload_not_supported',
        ];
        yield 'external applied value' => [
            HistoryEventType::SignalApplied, 'value', $external, 'external_signal_payload_not_supported',
        ];
        // Any external-storage key is external, even a null one.
        yield 'null external storage key on receipt arguments' => [
            HistoryEventType::SignalReceived,
            'arguments',
            [
                'codec' => 'avro',
                'external_storage' => null,
            ],
            'external_signal_payload_not_supported',
        ];
        yield 'null external storage key on applied value' => [
            HistoryEventType::SignalApplied,
            'value',
            [
                'codec' => 'avro',
                'external_storage' => null,
            ],
            'external_signal_payload_not_supported',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signalStepMutations')]
    public function testRedriveRefusesAMismatchedOrExternalSignalStepWithoutMutation(
        HistoryEventType $type,
        string $key,
        mixed $value,
        string $reason,
    ): void {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveSignalWorkflow::class, 'redrive-signal-mutation');
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', $type)
            ->sole();
        $event->forceFill([
            'payload' => array_merge($event->payload, [
                $key => $value,
            ]),
        ])->save();
        $cursor = (int) $source->fresh()
->message_cursor_position;
        $events = WorkflowHistoryEvent::query()->count();

        $result = app(WorkflowControlPlane::class)->redrive('redrive-signal-mutation', $source->id);

        $this->assertFalse($result['accepted']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', 'redrive-signal-mutation')->count());
        $this->assertSame($cursor, (int) $source->fresh()->message_cursor_position);
        $this->assertSame($events, WorkflowHistoryEvent::query()->count());
        $this->assertSame($source->id, $source->instance->fresh()->current_run_id);
    }

    public function testRedriveRefusesOneBufferedReceiptAppliedToTwoWaits(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveTwoSignalsWorkflow::class, 'redrive-signal-twice');
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $workflow->signal('approve', 'Ada');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        // The unmodified two-signal source is redrivable.
        $this->assertTrue(FailedRunRedrivePlan::forRun($source->fresh())['eligible']);
        $receipts = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', HistoryEventType::SignalReceived)
            ->orderBy('sequence')
            ->get();
        $applications = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', HistoryEventType::SignalApplied)
            ->orderBy('sequence')
            ->get();
        // The first receipt was buffered, and the second application claims it
        // too. The second receipt no longer exists, so nothing is pending.
        $receipts[0]->forceFill([
            'payload' => array_merge($receipts[0]->payload, [
                'signal_wait_id' => null,
            ]),
        ])->save();
        $applications[1]->forceFill([
            'payload' => array_merge($applications[1]->payload, [
                'signal_id' => $receipts[0]->payload['signal_id'],
                'workflow_command_id' => $receipts[0]->payload['workflow_command_id'],
            ]),
        ])->save();
        $receipts[1]->delete();

        $this->assertRefusedWithoutMutation('redrive-signal-twice', $source, 'mismatched_signal_receipt');
    }

    public function testRedriveRefusesASignalApplicationWithoutItsValue(): void
    {
        WorkflowStub::fake();

        $source = $this->failedSignalSource('redrive-signal-no-value');
        $application = $this->sourceEvent($source, HistoryEventType::SignalApplied);
        $payload = $application->payload;
        unset($payload['value']);
        $application->forceFill([
            'payload' => $payload,
        ])->save();

        $this->assertRefusedWithoutMutation('redrive-signal-no-value', $source, 'incomplete_signal_step');
    }

    public function testRedriveAcceptsASignalReceivedBeforeItsWaitOpened(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveSignalWorkflow::class, 'redrive-signal-buffered');
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $this->assertTrue($workflow->refresh()->failed());
        $receipt = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SignalReceived)
            ->sole();
        // A signal that arrived before the wait opened has no wait id yet.
        $receipt->forceFill([
            'payload' => array_merge($receipt->payload, [
                'signal_wait_id' => null,
            ]),
        ])->save();

        $result = app(WorkflowControlPlane::class)->redrive('redrive-signal-buffered', $workflow->runId());

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(
            'Grace',
            WorkflowRun::query()->findOrFail($result['workflow_run_id'])->workflowOutput()['approved_by']
        );
    }

    public function testRedriveRefusesASignalWaitWithATimeout(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveSignalWorkflow::class, 'redrive-signal-timeout');
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $this->assertTrue($workflow->refresh()->failed());
        $wait = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::SignalWaitOpened)
            ->sole();
        // Its timeout timer and race are not reused yet.
        $wait->forceFill([
            'payload' => array_merge($wait->payload, [
                'timeout_seconds' => 60,
            ]),
        ])->save();

        $result = app(WorkflowControlPlane::class)->redrive('redrive-signal-timeout', $workflow->runId());

        $this->assertFalse($result['accepted']);
        $this->assertSame('unsupported_signal_wait', $result['reason']);
    }

    public function testRedriveMovesAWorkflowCodeFailureOntoACorrectedDefinition(): void
    {
        WorkflowStub::fake();
        // The buggy source never reached the second activity, so its first call
        // in the corrected successor succeeds.
        config([
            'workflows.v2.testing.redrive_failures_before_success' => 0,
        ]);
        $controlPlane = app(WorkflowControlPlane::class);
        $useDefinition = static fn (string $class) => config([
            'workflows.v2.types.workflows' => [
                'test-redrive-correctable' => $class,
            ],
        ]);
        $useDefinition(TestRedriveBuggyWorkflow::class);

        $workflow = WorkflowStub::make(TestRedriveBuggyWorkflow::class, 'redrive-corrected-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $failure = $this->sourceEvent($source, HistoryEventType::WorkflowFailed)->payload;
        $this->assertSame('workflow_code', $failure['failed_step_kind'] ?? null);
        $this->assertSame(2, $failure['failed_step_sequence'] ?? null);
        $buggyFingerprint = WorkflowDefinition::fingerprint(TestRedriveBuggyWorkflow::class);

        // The same code would throw again.
        $this->assertRefusedWithoutMutation(
            'redrive-corrected-1',
            $source,
            'workflow_code_failure_requires_corrected_definition',
        );

        // A corrected definition is accepted only on request.
        $useDefinition(TestRedriveFixedWorkflow::class);
        $this->assertRefusedWithoutMutation(
            'redrive-corrected-1',
            $source,
            'workflow_definition_unavailable_or_changed',
        );

        // Code that can't replay the completed prefix is refused before any
        // write, naming the first step it disagrees with.
        $useDefinition(TestRedriveIncompatibleWorkflow::class);
        $incompatible = $this->assertRefusedWithoutMutation(
            'redrive-corrected-1',
            $source,
            'definition_incompatible_with_prefix',
            [
                'allow_definition_change' => true,
            ],
        );
        $this->assertSame([
            'sequence' => 1,
            'kind' => 'step_shape',
        ], $incompatible['definition_mismatch'] ?? null);

        $useDefinition(TestRedriveFinishesEarlyWorkflow::class);
        $finishesEarly = $this->assertRefusedWithoutMutation(
            'redrive-corrected-1',
            $source,
            'definition_incompatible_with_prefix',
            [
                'allow_definition_change' => true,
            ],
        );
        $this->assertSame([
            'sequence' => 1,
            'kind' => 'finished_before_boundary',
        ], $finishesEarly['definition_mismatch'] ?? null);

        // The check never replays more history than the budget allows.
        $useDefinition(TestRedriveFixedWorkflow::class);
        config([
            'workflows.v2.history_budget.continue_as_new_event_threshold' => 1,
        ]);
        $overBudget = $this->assertRefusedWithoutMutation(
            'redrive-corrected-1',
            $source,
            'definition_check_over_budget',
            [
                'allow_definition_change' => true,
            ],
        );
        // Only an incompatible prefix names a step.
        $this->assertArrayNotHasKey('definition_mismatch', $overBudget);
        config([
            'workflows.v2.history_budget.continue_as_new_event_threshold' => 10000,
        ]);

        $result = $controlPlane->redrive('redrive-corrected-1', $source->id, [
            'allow_definition_change' => true,
        ]);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(2, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(TestRedriveFixedWorkflow::class, $successor->workflow_class);
        $this->assertSame('Hello, Taylor! Redrive completed.', $successor->workflowOutput());
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $started = $this->sourceEvent($successor, HistoryEventType::WorkflowStarted)->payload;
        $this->assertSame($buggyFingerprint, $started['definition_changed_from'] ?? null);
        $this->assertSame(
            WorkflowDefinition::fingerprint(TestRedriveFixedWorkflow::class),
            $started['workflow_definition_fingerprint'] ?? null,
        );
        $this->assertSame(
            $buggyFingerprint,
            $controlPlane->describe('redrive-corrected-1', [
                'run_id' => $successor->id,
            ])['run']['definition_changed_from'] ?? null,
        );
        $this->assertSame(
            $buggyFingerprint,
            RunDetailView::forRun($successor->fresh())['workflow_definition_changed_from'] ?? null,
        );
        $this->assertNull(RunDetailView::forRun($source->fresh())['workflow_definition_changed_from'] ?? null);
    }

    public function testADefinitionThatDropsTheRecordedVersionNamesTheMarkerStep(): void
    {
        WorkflowStub::fake();
        config([
            'workflows.v2.types.workflows' => [
                'test-redrive-versioned' => TestRedriveVersionedBuggyWorkflow::class,
            ],
        ]);
        $workflow = WorkflowStub::make(TestRedriveVersionedBuggyWorkflow::class, 'redrive-versioned-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertSame(1, $this->sourceEvent($source, HistoryEventType::VersionMarkerRecorded)->payload['sequence']);
        config([
            'workflows.v2.types.workflows' => [
                'test-redrive-versioned' => TestRedriveVersionedIncompatibleWorkflow::class,
            ],
        ]);

        $result = $this->assertRefusedWithoutMutation(
            'redrive-versioned-1',
            $source,
            'definition_incompatible_with_prefix',
            [
                'allow_definition_change' => true,
            ],
        );

        $this->assertSame([
            'sequence' => 1,
            'kind' => 'version_range',
        ], $result['definition_mismatch'] ?? null);
    }

    public function testALaterRedriveOnTheSameDefinitionRecordsNoInheritedChange(): void
    {
        WorkflowStub::fake();
        $controlPlane = app(WorkflowControlPlane::class);
        // The corrected run's second activity fails once, so it is redriven
        // again on the same corrected definition.
        config([
            'workflows.v2.testing.redrive_failures_before_success' => 1,
            'workflows.v2.types.workflows' => [
                'test-redrive-correctable' => TestRedriveBuggyWorkflow::class,
            ],
        ]);
        $workflow = WorkflowStub::make(TestRedriveBuggyWorkflow::class, 'redrive-corrected-chain');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        config([
            'workflows.v2.types.workflows' => [
                'test-redrive-correctable' => TestRedriveFixedWorkflow::class,
            ],
        ]);

        $corrected = $controlPlane->redrive('redrive-corrected-chain', $workflow->runId(), [
            'allow_definition_change' => true,
        ]);
        $this->assertTrue($corrected['accepted'], (string) $corrected['reason']);
        $correctedRun = WorkflowRun::query()->findOrFail($corrected['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $correctedRun->status);
        $this->assertNotNull(
            $this->sourceEvent(
                $correctedRun,
                HistoryEventType::WorkflowStarted
            )->payload['definition_changed_from'] ?? null
        );

        $again = $controlPlane->redrive('redrive-corrected-chain', $correctedRun->id);

        $this->assertTrue($again['accepted'], (string) $again['reason']);
        $final = WorkflowRun::query()->findOrFail($again['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $final->status);
        $this->assertSame(TestRedriveFixedWorkflow::class, $final->workflow_class);
        $this->assertArrayNotHasKey(
            'definition_changed_from',
            $this->sourceEvent($final, HistoryEventType::WorkflowStarted)->payload,
        );
        $this->assertNull(RunDetailView::forRun($final->fresh())['workflow_definition_changed_from'] ?? null);
        $this->assertNull($controlPlane->describe('redrive-corrected-chain', [
            'run_id' => $final->id,
        ])['run']['definition_changed_from'] ?? null);
    }

    public function testRedriveReappliesAnUpdateTheSourceApplied(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveUpdateWorkflow::class, 'redrive-update-1');
        $workflow->start('Taylor');
        $this->assertSame('noted urgent', $workflow->update('set-note', 'urgent'));
        $workflow->signal('go');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $sourceApplied = $this->sourceEvent($source, HistoryEventType::UpdateApplied);

        $result = app(WorkflowControlPlane::class)->redrive('redrive-update-1', $source->id);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        // The update's state change is replayed, not delivered again.
        $this->assertSame([
            'result' => 'Hello, Taylor! Redrive completed.',
            'note' => 'urgent',
        ], $successor->workflowOutput());
        $this->assertSame(0, WorkflowUpdate::query()->where('workflow_run_id', $successor->id)->count());

        $types = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn (HistoryEventType $type): string => $type->value)
            ->all();
        $applied = array_search('UpdateApplied', $types, true);
        $this->assertNotFalse($applied);
        $this->assertSame('UpdateAccepted', $types[$applied - 1]);
        $this->assertSame('UpdateCompleted', $types[$applied + 1]);
        // It is written ahead of the step it ran before.
        $this->assertLessThan(array_search('SignalWaitOpened', $types, true), $applied);
        // Replay applies it from the same recorded position as the source.
        $this->assertSame(
            $sourceApplied->payload['sequence'],
            $this->sourceEvent($successor, HistoryEventType::UpdateApplied)->payload['sequence'],
        );
        foreach ([
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateApplied,
            HistoryEventType::UpdateCompleted,
        ] as $type) {
            $original = $this->sourceEvent($source, $type);
            $reused = $this->sourceEvent($successor, $type);
            $this->assertSame($source->id, $reused->payload['reused_from_run_id'] ?? null);
            $this->assertReplayClock(
                $original->recorded_at,
                $reused->payload['reused_recorded_at'] ?? null
            );
        }
    }

    public function testAnUpdateAndSignalWaitRedriveAcrossAChain(): void
    {
        WorkflowStub::fake();
        config([
            'workflows.v2.testing.redrive_failures_before_success' => 2,
        ]);
        $controlPlane = app(WorkflowControlPlane::class);

        $workflow = WorkflowStub::make(TestRedriveUpdateWorkflow::class, 'redrive-update-chain');
        $workflow->start('Taylor');
        $workflow->update('set-note', 'urgent');
        $workflow->signal('go');
        $this->assertTrue($workflow->refresh()->failed());
        $first = WorkflowRun::query()->findOrFail($workflow->runId());

        $second = $controlPlane->redrive('redrive-update-chain', $first->id);
        $this->assertTrue($second['accepted'], (string) $second['reason']);
        $secondRun = WorkflowRun::query()->findOrFail($second['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $secondRun->status);

        $third = $controlPlane->redrive('redrive-update-chain', $secondRun->id);

        $this->assertTrue($third['accepted'], (string) $third['reason']);
        $thirdRun = WorkflowRun::query()->findOrFail($third['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $thirdRun->status);
        $this->assertSame('urgent', $thirdRun->workflowOutput()['note']);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        foreach ([
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateApplied,
            HistoryEventType::UpdateCompleted,
            HistoryEventType::SignalReceived,
            HistoryEventType::SignalApplied,
        ] as $type) {
            $events = WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $thirdRun->id)
                ->where('event_type', $type)
                ->get();
            $this->assertCount(1, $events, $type->value);
            $original = $this->sourceEvent($first, $type);
            // The immediate source, the original run and its first time.
            $this->assertSame($secondRun->id, $events[0]->payload['reused_from_run_id'], $type->value);
            $this->assertSame($first->id, $events[0]->payload['workflow_run_id'] ?? $first->id, $type->value);
            $this->assertReplayClock(
                $original->recorded_at,
                $events[0]->payload['reused_recorded_at'],
                $type->value,
            );
        }
        $this->assertSame(
            1,
            WorkflowUpdate::query()->where('workflow_instance_id', 'redrive-update-chain')->count(),
        );
        $this->assertSame(
            0,
            WorkflowCommand::query()
                ->whereIn('workflow_run_id', [$secondRun->id, $thirdRun->id])
                ->whereIn('command_type', ['update', 'signal'])
                ->count(),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedUpdateHistories(): iterable
    {
        yield 'acceptance then rejection' => ['accepted_then_rejected', 'mismatched_update_history'];
        yield 'lone terminal completion' => ['lone_terminal_completion', 'mismatched_update_history'];
        yield 'application before acceptance' => ['reversed_order', 'mismatched_update_history'];
        yield 'no command or update name' => ['null_identity', 'mismatched_update_history'];
        yield 'applied arguments differ' => ['changed_arguments', 'mismatched_update_history'];
        yield 'failure key on a successful completion' => ['null_failure_key', 'incomplete_update_step'];
        yield 'null external storage key' => ['null_external_storage', 'external_update_payload_not_supported'];
        yield 'undecodable inline result' => ['undecodable_result', 'unavailable_update_payload'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedUpdateHistories')]
    public function testRedriveRefusesMalformedUpdateHistoryWithoutMutation(string $mutation, string $reason): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveUpdateWorkflow::class, 'redrive-update-malformed');
        $workflow->start('Taylor');
        $workflow->update('set-note', 'urgent');
        $workflow->signal('go');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $accepted = $this->sourceEvent($source, HistoryEventType::UpdateAccepted);
        $applied = $this->sourceEvent($source, HistoryEventType::UpdateApplied);
        $completed = $this->sourceEvent($source, HistoryEventType::UpdateCompleted);
        $merge = static function (WorkflowHistoryEvent $event, array $values): void {
            $event->forceFill([
                'payload' => array_merge($event->payload, $values),
            ])->save();
        };

        match ($mutation) {
            'accepted_then_rejected' => (static function () use ($applied, $completed): void {
                $completed->delete();
                $applied->forceFill([
                    'event_type' => HistoryEventType::UpdateRejected,
                ])->save();
            })(),
            'lone_terminal_completion' => (static function () use ($accepted, $applied, $completed, $merge): void {
                $accepted->delete();
                $applied->delete();
                $merge($completed, [
                    'terminal_reason' => 'failed',
                ]);
            })(),
            'reversed_order' => (static function () use ($accepted, $applied): void {
                [$acceptedSequence, $appliedSequence] = [$accepted->sequence, $applied->sequence];
                $accepted->forceFill([
                    'sequence' => 100000,
                ])->save();
                $applied->forceFill([
                    'sequence' => $acceptedSequence,
                ])->save();
                $accepted->forceFill([
                    'sequence' => $appliedSequence,
                ])->save();
            })(),
            'null_identity' => array_map(static fn (WorkflowHistoryEvent $event) => $merge($event, [
                'workflow_command_id' => null,
                'update_name' => null,
            ]), [$accepted, $applied, $completed]),
            'changed_arguments' => $merge($applied, [
                'arguments' => Serializer::serializeWithCodec('avro', ['calm']),
            ]),
            'null_failure_key' => $merge($completed, [
                'failure_id' => null,
            ]),
            'null_external_storage' => array_map(static fn (WorkflowHistoryEvent $event) => $merge($event, [
                'arguments' => [
                    'codec' => 'avro',
                    'external_storage' => null,
                ],
            ]), [$accepted, $applied]),
            'undecodable_result' => $merge($completed, [
                'result' => 'not-an-avro-payload',
            ]),
        };

        $before = $this->engineSnapshot();
        $currentRun = $source->instance->fresh()
->current_run_id;

        $result = app(WorkflowControlPlane::class)->redrive('redrive-update-malformed', $source->id);

        $this->assertFalse($result['accepted']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($before, $this->engineSnapshot());
        $this->assertSame($currentRun, $source->instance->fresh()->current_run_id);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function closedUpdateShapes(): iterable
    {
        yield 'closed with the run' => ['terminal', 'none', null];
        yield 'closed with a null terminal reason' => ['terminal', 'null_terminal_reason', 'mismatched_update_history'];
        yield 'closed with a blank terminal reason' => [
            'terminal',
            'blank_terminal_reason',
            'mismatched_update_history',
        ];
        yield 'closed with a result' => ['terminal', 'result', 'mismatched_update_history'];
        yield 'closed at a step' => ['terminal', 'step_sequence', 'mismatched_update_history'];
        yield 'closed without a failure' => ['terminal', 'missing_failure_id', 'mismatched_update_history'];
        yield 'closed by another run' => ['terminal', 'foreign_run', 'mismatched_update_history'];
        yield 'closed for another command' => ['terminal', 'foreign_command', 'mismatched_update_history'];
        yield 'closed for another handler' => ['terminal', 'foreign_name', 'mismatched_update_history'];
        yield 'closed with a lone reuse time' => ['terminal', 'lone_recorded_at', 'mismatched_update_history'];
        yield 'rejected' => ['rejected', 'none', null];
        yield 'rejected by another run' => ['rejected', 'foreign_run', 'mismatched_update_history'];
        yield 'rejected in another instance' => ['rejected', 'foreign_instance', 'mismatched_update_history'];
        yield 'rejected without a command' => ['rejected', 'null_command', 'mismatched_update_history'];
        yield 'rejected without a handler name' => ['rejected', 'blank_name', 'mismatched_update_history'];
        yield 'rejected with a lone reuse time' => ['rejected', 'lone_recorded_at', 'mismatched_update_history'];
        yield 'rejected with a lone reuse source' => ['rejected', 'lone_source', 'mismatched_update_history'];
    }

    /**
     * A rejected update, or one the run's failure closed before it ran, is not
     * replayed. Redrive skips it only when it is exactly that shape and belongs
     * to the source run.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('closedUpdateShapes')]
    public function testRedriveSkipsOnlyAnExactlyClosedOrRejectedUpdate(
        string $shape,
        string $mutation,
        ?string $reason,
    ): void {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveUpdateWorkflow::class, 'redrive-update-closed');
        $workflow->start('Taylor');
        $workflow->update('set-note', 'urgent');
        $workflow->signal('go');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $accepted = $this->sourceEvent($source, HistoryEventType::UpdateAccepted);
        $applied = $this->sourceEvent($source, HistoryEventType::UpdateApplied);
        $completed = $this->sourceEvent($source, HistoryEventType::UpdateCompleted);
        $applied->delete();

        if ($shape === 'terminal') {
            // The shape PendingUpdateCloser records: a failure, no result, no step.
            $closed = $completed->payload;
            unset($closed['result'], $closed['sequence']);
            $completed->forceFill([
                'payload' => array_merge($closed, [
                    'failure_id' => 'update-closed-failure',
                    'terminal_reason' => 'failed',
                ]),
            ])->save();
            $target = $completed;
        } else {
            $completed->delete();
            $accepted->forceFill([
                'event_type' => HistoryEventType::UpdateRejected,
            ])->save();
            $target = $accepted;
        }

        $payload = $target->fresh()
            ->payload;
        match ($mutation) {
            'none' => null,
            'null_terminal_reason' => $payload['terminal_reason'] = null,
            'blank_terminal_reason' => $payload['terminal_reason'] = ' ',
            'result' => $payload['result'] = Serializer::serializeWithCodec('avro', 'noted urgent'),
            'step_sequence' => $payload['sequence'] = 2,
            'missing_failure_id' => (static function () use (&$payload): void {
                unset($payload['failure_id']);
            })(),
            'foreign_run' => $payload['workflow_run_id'] = 'another-run',
            'foreign_instance' => $payload['workflow_instance_id'] = 'another-instance',
            'foreign_command' => $payload['workflow_command_id'] = 'another-command',
            'null_command' => $payload['workflow_command_id'] = null,
            'foreign_name' => $payload['update_name'] = 'another-update',
            'blank_name' => $payload['update_name'] = '',
            'lone_recorded_at' => $payload['reused_recorded_at'] = now()->toIso8601String(),
            'lone_source' => $payload['reused_from_run_id'] = $source->id,
        };
        $target->forceFill([
            'payload' => $payload,
        ])->save();

        $plan = FailedRunRedrivePlan::forRun($source->fresh());

        if ($reason === null) {
            $this->assertTrue($plan['eligible'], (string) $plan['reason']);
            $this->assertSame([], $plan['update_bundles']);

            return;
        }

        $this->assertRefusedWithoutMutation('redrive-update-closed', $source, $reason);
    }

    /**
     * @return iterable<string, array{string, HistoryEventType, string, string}>
     */
    public static function chainedProvenanceMutations(): iterable
    {
        $types = [
            HistoryEventType::UpdateAccepted,
            HistoryEventType::UpdateApplied,
            HistoryEventType::UpdateCompleted,
            HistoryEventType::SignalWaitOpened,
            HistoryEventType::SignalReceived,
            HistoryEventType::SignalApplied,
        ];

        foreach ($types as $type) {
            $reason = str_starts_with($type->value, 'Update')
                ? 'mismatched_update_history'
                : 'mismatched_signal_receipt';

            // Recorded by the source itself: the reuse keys are both absent.
            yield $type->value . ' original with a lone reuse time' => ['source', $type, 'lone_recorded_at', $reason];
            yield $type->value . ' original with a lone reuse source' => ['source', $type, 'lone_source', $reason];

            // Reused by a redrive: the keys are both present, name the
            // immediate source and keep the first time.
            foreach ([
                'missing_time',
                'null_time',
                'blank_time',
                'missing_source',
                'null_source',
                'foreign_source',
            ] as $mutation) {
                yield $type->value . ' reused with ' . str_replace('_', ' ', $mutation) => [
                    'successor',
                    $type,
                    $mutation,
                    $reason,
                ];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chainedProvenanceMutations')]
    public function testRedriveRefusesABrokenReuseProvenancePairWithoutMutation(
        string $run,
        HistoryEventType $type,
        string $mutation,
        string $reason,
    ): void {
        WorkflowStub::fake();
        config([
            'workflows.v2.testing.redrive_failures_before_success' => 2,
        ]);

        $workflow = WorkflowStub::make(TestRedriveUpdateWorkflow::class, 'redrive-update-pair');
        $workflow->start('Taylor');
        $workflow->update('set-note', 'urgent');
        $workflow->signal('go');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());

        if ($run === 'successor') {
            $redriven = app(WorkflowControlPlane::class)->redrive('redrive-update-pair', $source->id);
            $this->assertTrue($redriven['accepted'], (string) $redriven['reason']);
            $source = WorkflowRun::query()->findOrFail($redriven['workflow_run_id']);
            $this->assertSame(RunStatus::Failed, $source->status);
            // The unmodified redriven run is redrivable again.
            $this->assertTrue(FailedRunRedrivePlan::forRun($source->fresh())['eligible']);
        }

        $event = $this->sourceEvent($source, $type);
        $payload = $event->payload;
        match ($mutation) {
            'lone_recorded_at' => $payload['reused_recorded_at'] = now()->toIso8601String(),
            'lone_source' => $payload['reused_from_run_id'] = $source->id,
            'missing_time' => (static function () use (&$payload): void {
                unset($payload['reused_recorded_at']);
            })(),
            'null_time' => $payload['reused_recorded_at'] = null,
            'blank_time' => $payload['reused_recorded_at'] = '',
            'missing_source' => (static function () use (&$payload): void {
                unset($payload['reused_from_run_id']);
            })(),
            'null_source' => $payload['reused_from_run_id'] = null,
            'foreign_source' => $payload['reused_from_run_id'] = 'another-run',
        };
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $before = $this->engineSnapshot();
        $currentRun = $source->instance->fresh()
            ->current_run_id;

        $result = app(WorkflowControlPlane::class)->redrive('redrive-update-pair', $source->id);

        $this->assertFalse($result['accepted']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($before, $this->engineSnapshot());
        $this->assertSame($currentRun, $source->instance->fresh()->current_run_id);
    }

    public function testRedriveRefusesAnUpdateTheSourceNeverResolved(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveUpdateWorkflow::class, 'redrive-update-pending');
        $workflow->start('Taylor');
        $workflow->update('set-note', 'urgent');
        $workflow->signal('go');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        // Only the acceptance remains: the source never applied or closed it.
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->whereIn('event_type', [HistoryEventType::UpdateApplied->value, HistoryEventType::UpdateCompleted->value])
            ->delete();

        $this->assertRefusedWithoutMutation('redrive-update-pending', $source, 'unsupported_pending_update');
    }

    public function testRunDetailReportsRedriveLineageAsTypedHistory(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-lineage-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $result = app(WorkflowControlPlane::class)->redrive('redrive-lineage-1', $source->id);
        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $link = WorkflowLink::query()->where('link_type', 'redrive')->sole();

        $parents = array_values(array_filter(
            RunDetailView::forRun($successor->fresh())['parents'],
            static fn (array $entry): bool => $entry['link_type'] === 'redrive',
        ));
        $this->assertCount(1, $parents);
        $this->assertSame($link->id, $parents[0]['id']);
        $this->assertSame($source->id, $parents[0]['workflow_run_id']);
        $this->assertSame(1, $parents[0]['run_number']);
        $this->assertFalse($parents[0]['diagnostic_only']);
        $this->assertSame('typed_history', $parents[0]['history_authority']);

        $successors = array_values(array_filter(
            RunDetailView::forRun($source->fresh())['continuedWorkflows'],
            static fn (array $entry): bool => $entry['link_type'] === 'redrive',
        ));
        $this->assertCount(1, $successors);
        $this->assertSame($successor->id, $successors[0]['workflow_run_id']);
        $this->assertSame(2, $successors[0]['run_number']);
        $this->assertFalse($successors[0]['diagnostic_only']);
        $this->assertSame('typed_history', $successors[0]['history_authority']);

        // Both views take the step and time from the successor's WorkflowStarted,
        // not from the mutable link row.
        $started = $this->sourceEvent($successor, HistoryEventType::WorkflowStarted);
        $link->forceFill([
            'sequence' => 99,
            'created_at' => now()
                ->addDay(),
        ])->save();
        foreach ([
            RunDetailView::forRun($successor->fresh())['parents'],
            RunDetailView::forRun($source->fresh())['continuedWorkflows'],
        ] as $view) {
            $redrive = array_values(
                array_filter($view, static fn (array $entry): bool => $entry['link_type'] === 'redrive')
            );
            $this->assertCount(1, $redrive);
            $this->assertSame('typed_history', $redrive[0]['history_authority']);
            $this->assertSame($result['resume_step_sequence'], $redrive[0]['sequence']);
            $this->assertSame($started->payload['resume_step_sequence'], $redrive[0]['sequence']);
            $this->assertSame(
                ($started->recorded_at ?? $started->created_at)?->toJSON(),
                $redrive[0]['created_at'],
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redriveStartIdentityMutations(): iterable
    {
        yield 'missing run id' => ['missing_run'];
        yield 'another run id' => ['foreign_run'];
        yield 'missing instance id' => ['missing_instance'];
        yield 'another instance id' => ['foreign_instance'];
        yield 'missing link id' => ['missing_link'];
        yield 'blank link id' => ['blank_link'];
        yield 'another link id' => ['foreign_link'];
        yield 'missing resume step' => ['missing_resume'];
        yield 'zero resume step' => ['zero_resume'];
        yield 'string resume step' => ['string_resume'];
        yield 'missing source run' => ['missing_source'];
        yield 'another source run' => ['foreign_source'];
        yield 'source names the successor' => ['self_source'];
        yield 'not a redrive' => ['not_redrive'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redriveStartIdentityMutations')]
    public function testARedriveStartWithoutItsExactIdentityIsNotTypedLineage(string $mutation): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-lineage-identity');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $result = app(WorkflowControlPlane::class)->redrive('redrive-lineage-identity', $source->id);
        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $started = $this->sourceEvent($successor, HistoryEventType::WorkflowStarted);
        $payload = $started->payload;
        match ($mutation) {
            'missing_run' => (static function () use (&$payload): void {
                unset($payload['workflow_run_id']);
            })(),
            'foreign_run' => $payload['workflow_run_id'] = 'another-run',
            'missing_instance' => (static function () use (&$payload): void {
                unset($payload['workflow_instance_id']);
            })(),
            'foreign_instance' => $payload['workflow_instance_id'] = 'another-instance',
            'missing_link' => (static function () use (&$payload): void {
                unset($payload['workflow_link_id']);
            })(),
            'blank_link' => $payload['workflow_link_id'] = ' ',
            'foreign_link' => $payload['workflow_link_id'] = 'another-link',
            'missing_resume' => (static function () use (&$payload): void {
                unset($payload['resume_step_sequence']);
            })(),
            'zero_resume' => $payload['resume_step_sequence'] = 0,
            'string_resume' => $payload['resume_step_sequence'] = (string) $payload['resume_step_sequence'],
            'missing_source' => (static function () use (&$payload): void {
                unset($payload['continued_from_run_id']);
            })(),
            'foreign_source' => $payload['continued_from_run_id'] = 'another-run',
            'self_source' => $payload['continued_from_run_id'] = $successor->id,
            'not_redrive' => (static function () use (&$payload): void {
                unset($payload['recovery_kind']);
            })(),
        };
        $started->forceFill([
            'payload' => $payload,
        ])->save();

        $this->assertOneDiagnosticRedriveEdge($source, $successor);
    }

    /**
     * A redrive chain A -> B -> C where C's start names A, a real earlier
     * failed run of the same instance, instead of B. No link joins A and C,
     * so neither run gets a typed or a second edge.
     */
    public function testARedriveStartNamingAnotherEarlierFailedRunIsNotTypedLineage(): void
    {
        WorkflowStub::fake();
        config([
            'workflows.v2.testing.redrive_failures_before_success' => 2,
        ]);
        $controlPlane = app(WorkflowControlPlane::class);

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-lineage-chain');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $first = WorkflowRun::query()->findOrFail($workflow->runId());
        $second = $controlPlane->redrive('redrive-lineage-chain', $first->id);
        $this->assertTrue($second['accepted'], (string) $second['reason']);
        $secondRun = WorkflowRun::query()->findOrFail($second['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $secondRun->status);
        $third = $controlPlane->redrive('redrive-lineage-chain', $secondRun->id);
        $this->assertTrue($third['accepted'], (string) $third['reason']);
        $thirdRun = WorkflowRun::query()->findOrFail($third['workflow_run_id']);

        // The unmodified chain is typed on every edge.
        foreach ([[$first, $secondRun], [$secondRun, $thirdRun]] as [$source, $successor]) {
            $parents = $this->redriveEdges(RunDetailView::forRun($successor->fresh())['parents']);
            $this->assertCount(1, $parents);
            $this->assertSame('typed_history', $parents[0]['history_authority']);
            $this->assertSame($source->id, $parents[0]['workflow_run_id']);
        }

        $started = $this->sourceEvent($thirdRun, HistoryEventType::WorkflowStarted);
        $started->forceFill([
            'payload' => array_merge($started->payload, [
                'continued_from_run_id' => $first->id,
            ]),
        ])->save();

        $this->assertOneDiagnosticRedriveEdge($secondRun, $thirdRun);
        // The first run keeps its typed edge to the second run and gains no
        // edge to the third.
        $firstSuccessors = $this->redriveEdges(RunDetailView::forRun($first->fresh())['continuedWorkflows']);
        $this->assertCount(1, $firstSuccessors);
        $this->assertSame($secondRun->id, $firstSuccessors[0]['workflow_run_id']);
        $this->assertSame('typed_history', $firstSuccessors[0]['history_authority']);
    }

    public function testARedriveLinkTheSuccessorDoesNotConfirmStaysADiagnosticFallback(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-lineage-2');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $result = app(WorkflowControlPlane::class)->redrive('redrive-lineage-2', $source->id);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        // The successor's history names a different link.
        $started = $this->sourceEvent($successor, HistoryEventType::WorkflowStarted);
        $started->forceFill([
            'payload' => array_merge($started->payload, [
                'workflow_link_id' => 'another-link',
            ]),
        ])->save();

        $successors = array_values(array_filter(
            RunDetailView::forRun($source->fresh())['continuedWorkflows'],
            static fn (array $entry): bool => $entry['link_type'] === 'redrive',
        ));
        $this->assertCount(1, $successors);
        $this->assertTrue($successors[0]['diagnostic_only']);
    }

    public function testRedriveReusesOneMarkerForARepeatedPatchCall(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedrivePatchedWorkflow::class, 'redrive-patched-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $sourceRunId = $workflow->runId();
        $sourceMarkers = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $sourceRunId)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded)
            ->get();
        // Only the first call records a marker, at its own step. The repeated
        // call takes no step, so no marker shares a step with an activity.
        $this->assertCount(1, $sourceMarkers);
        $this->assertSame(1, $sourceMarkers[0]->payload['sequence']);

        $result = app(WorkflowControlPlane::class)->redrive('redrive-patched-1', $sourceRunId);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(3, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame([
            'result' => 'Hello, Taylor! Redrive completed.',
            'patched' => true,
            'patched_again' => true,
        ], $successor->workflowOutput());
        $this->assertSame(1, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded)
            ->count());
    }

    public function testRedriveReusesAFiredTimerAtItsRecordedTime(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveTimerWorkflow::class, 'redrive-timer-1');
        $workflow->start('Taylor', 5);
        $this->travel(6)
            ->seconds();
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();
        $this->app->call([new RunTimerTask($timerTask->id), 'handle']);
        $this->assertTrue($workflow->refresh()->failed());
        $sourceRunId = $workflow->runId();
        $sourceFired = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $sourceRunId)
            ->where('event_type', HistoryEventType::TimerFired)
            ->firstOrFail();

        // Redrive later than the source, so a successor that waited again or
        // used its own event times would report a different workflow time.
        $this->travel(5)
            ->minutes();
        $result = app(WorkflowControlPlane::class)->redrive('redrive-timer-1', $sourceRunId);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(3, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $successor->id)
            ->where('task_type', TaskType::Timer->value)
            ->count());
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $successor->id)->count());

        $reusedEvents = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->whereIn('event_type', [HistoryEventType::TimerScheduled->value, HistoryEventType::TimerFired->value])
            ->orderBy('sequence')
            ->get();
        $this->assertSame(
            [HistoryEventType::TimerScheduled, HistoryEventType::TimerFired],
            $reusedEvents->pluck('event_type')
                ->all(),
        );
        [$reusedScheduled, $reusedFired] = $reusedEvents->all();
        $this->assertSame(2, $reusedFired->payload['sequence']);
        $this->assertSame($reusedScheduled->payload['timer_id'], $reusedFired->payload['timer_id']);
        $this->assertNotSame($sourceFired->payload['timer_id'], $reusedFired->payload['timer_id']);
        $this->assertSame($sourceRunId, $reusedFired->payload['reused_from_run_id'] ?? null);
        $this->assertReplayClock(
            $sourceFired->recorded_at,
            $reusedFired->payload['reused_recorded_at'] ?? null,
        );

        $expectedTime = $sourceFired->recorded_at?->toIso8601String();
        $output = $successor->workflowOutput();
        $this->assertSame('Hello, Taylor! Redrive completed.', $output['result']);
        $this->assertSame($expectedTime, $output['time_after_timer']);

        $timers = RunTimerView::timersForRun($successor->fresh());
        $this->assertCount(1, $timers);
        $this->assertSame('fired', $timers[0]['status']);

        $export = json_decode(
            json_encode(HistoryExport::forRun($successor->fresh()), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame([
            'time_after_timer' => $expectedTime,
        ], (new WorkflowReplayer())->replayExport($export)
            ->workflow->replayTimes());
        $this->assertSame([
            'time_after_timer' => $expectedTime,
        ], (new WorkflowReplayer())->replay($successor->fresh())
            ->workflow->replayTimes());

        $history = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])
            ->all();
        $fiber = WorkflowFiberRunner::forClass(
            TestRedriveTimerWorkflow::class,
            'redrive-timer-1',
            $successor->id,
            ['Taylor', 5],
            (string) $successor->payload_codec,
            $history,
        )->step();
        $this->assertTrue($fiber->completed);
        $this->assertSame($output, $fiber->result);
    }

    public function testRedriveReusesACompletedChildWorkflowAtItsRecordedTime(): void
    {
        // The successor fails once more, so the chain reuses the child twice.
        config()
            ->set('workflows.v2.testing.redrive_failures_before_success', 2);
        WorkflowStub::fake();
        $controlPlane = app(WorkflowControlPlane::class);

        $workflow = WorkflowStub::make(TestRedriveChildWorkflow::class, 'redrive-child-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $sourceRunId = $workflow->runId();
        $sourceChildEvents = $this->childEvents($sourceRunId);
        $this->assertCount(3, $sourceChildEvents);
        $childRunId = $sourceChildEvents[2]->payload['child_workflow_run_id'];
        $childInstanceId = $sourceChildEvents[2]->payload['child_workflow_instance_id'];
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertRedriveAction($controlPlane, 'redrive-child-1', $sourceRunId, true, null);

        // Redrive later than the source, so a successor that ran the child
        // again or used its own event times would report a different time.
        $this->travel(5)
            ->minutes();
        $first = $controlPlane->redrive('redrive-child-1', $sourceRunId);
        $this->assertTrue($first['accepted'], (string) $first['reason']);
        $this->assertSame(2, $first['resume_step_sequence']);
        $middle = WorkflowRun::query()->findOrFail($first['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $middle->status);

        $this->travel(5)
            ->minutes();
        $second = $controlPlane->redrive('redrive-child-1', $middle->id);
        $this->assertTrue($second['accepted'], (string) $second['reason']);
        $this->assertSame(2, $second['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($second['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $successor->status);

        // The child ran once: one child run, one child activity call, and the
        // only child link is the source's.
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $childInstanceId)->count());
        $childLinks = WorkflowLink::query()
            ->where('link_type', 'child_workflow')
            ->get();
        $this->assertCount(1, $childLinks);
        $this->assertSame($sourceRunId, $childLinks[0]->parent_workflow_run_id);
        $this->assertSame($childRunId, $childLinks[0]->child_workflow_run_id);

        foreach ([[$middle->id, $sourceRunId], [$successor->id, $middle->id]] as [$runId, $immediateSource]) {
            $reused = $this->childEvents($runId);
            $this->assertSame(
                [
                    HistoryEventType::ChildWorkflowScheduled,
                    HistoryEventType::ChildRunStarted,
                    HistoryEventType::ChildRunCompleted,
                ],
                array_map(static fn (WorkflowHistoryEvent $event): HistoryEventType => $event->event_type, $reused),
            );

            foreach ($reused as $index => $event) {
                $original = $sourceChildEvents[$index];
                $this->assertSame($immediateSource, $event->payload['reused_from_run_id'] ?? null);
                $this->assertReplayClock(
                    $original->recorded_at,
                    $event->payload['reused_recorded_at'] ?? null,
                );
                $this->assertSame($childRunId, $event->payload['child_workflow_run_id']);
                $this->assertSame($original->payload['workflow_link_id'], $event->payload['workflow_link_id']);
                $this->assertArrayNotHasKey('task', $event->payload);
            }
        }

        $expectedTime = $sourceChildEvents[2]->recorded_at?->toIso8601String();
        $output = $successor->workflowOutput();
        $this->assertSame('Hello, Taylor! Redrive completed.', $output['result']);
        $this->assertSame($expectedTime, $output['time_after_child']);

        $children = RunDetailView::forRun($successor->fresh())['continuedWorkflows'];
        $this->assertSame(
            [$childRunId],
            array_column(
                array_filter(
                    $children,
                    static fn (array $entry): bool => ($entry['link_type'] ?? null) === 'child_workflow'
                ),
                'child_workflow_run_id',
            ),
        );

        $export = json_decode(
            json_encode(HistoryExport::forRun($successor->fresh()), JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame([
            'time_after_child' => $expectedTime,
        ], (new WorkflowReplayer())->replayExport($export)
            ->workflow->replayTimes());
        $this->assertSame([
            'time_after_child' => $expectedTime,
        ], (new WorkflowReplayer())->replay($successor->fresh())
            ->workflow->replayTimes());

        $history = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $successor->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'recorded_at' => $event->recorded_at?->toIso8601String(),
            ])
            ->all();
        $fiber = WorkflowFiberRunner::forClass(
            TestRedriveChildWorkflow::class,
            'redrive-child-1',
            $successor->id,
            ['Taylor'],
            (string) $successor->payload_codec,
            $history,
        )->step();
        $this->assertTrue($fiber->completed);
        $this->assertSame($output, $fiber->result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedChildSteps(): iterable
    {
        yield 'child never closed' => ['open', 'unsupported_open_child'];
        yield 'child failed' => ['failed', 'unsupported_child_outcome'];
        yield 'completion naming another status' => ['failed_status', 'unsupported_child_outcome'];
        yield 'child in a parallel group' => ['parallel', 'unsupported_child_workflow'];
        yield 'child in a cancellation scope' => ['scoped', 'unsupported_child_workflow'];
        yield 'events naming another child run' => ['foreign_child_run', 'unsupported_child_workflow'];
        yield 'lone reuse time' => ['lone_recorded_at', 'unsupported_child_workflow'];
        yield 'completion before start' => ['reversed_order', 'unsupported_child_workflow'];
        yield 'output in external storage' => ['external_output', 'external_child_result_not_supported'];
        yield 'undecodable output' => ['undecodable_output', 'unavailable_child_result'];
        yield 'no output' => ['missing_output', 'unavailable_child_result'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedChildSteps')]
    public function testRedriveRefusesAnUnsupportedChildStepWithoutMutation(string $mutation, string $reason): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveChildWorkflow::class, 'redrive-child-refused');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        [$scheduled, $started, $completed] = $this->childEvents($source->id);
        $merge = static function (WorkflowHistoryEvent $event, array $values): void {
            $event->forceFill([
                'payload' => array_merge($event->payload, $values),
            ])->save();
        };

        match ($mutation) {
            'open' => $completed->delete(),
            'failed' => $completed->forceFill([
                'event_type' => HistoryEventType::ChildRunFailed,
            ])->save(),
            'failed_status' => $merge($completed, [
                'child_status' => 'failed',
            ]),
            'parallel' => array_map(static fn (WorkflowHistoryEvent $event) => $merge($event, [
                'parallel_group_id' => 'group-1',
            ]), [$scheduled, $started, $completed]),
            'scoped' => array_map(static fn (WorkflowHistoryEvent $event) => $merge($event, [
                'cancellation_scope_id' => 'scope-1',
            ]), [$scheduled, $started, $completed]),
            'foreign_child_run' => $merge($started, [
                'child_workflow_run_id' => 'another-child-run',
            ]),
            'lone_recorded_at' => $merge($completed, [
                'reused_recorded_at' => now()
                    ->toIso8601String(),
            ]),
            'reversed_order' => (static function () use ($started, $completed): void {
                [$startedSequence, $completedSequence] = [$started->sequence, $completed->sequence];
                $started->forceFill([
                    'sequence' => 100000,
                ])->save();
                $completed->forceFill([
                    'sequence' => $startedSequence,
                ])->save();
                $started->forceFill([
                    'sequence' => $completedSequence,
                ])->save();
            })(),
            'external_output' => $merge($completed, [
                'output' => [
                    'codec' => 'avro',
                    'external_storage' => [
                        'key' => 'source-run-only',
                    ],
                ],
            ]),
            'undecodable_output' => $merge($completed, [
                'output' => 'not-an-avro-payload',
            ]),
            'missing_output' => $completed->forceFill([
                'payload' => array_diff_key($completed->payload, [
                    'output' => true,
                ]),
            ])->save(),
        };

        $this->assertRedriveAction(
            app(WorkflowControlPlane::class),
            'redrive-child-refused',
            $source->id,
            false,
            $reason
        );
        $before = $this->engineSnapshot();
        $currentRun = $source->instance->fresh()
            ->current_run_id;

        $result = app(WorkflowControlPlane::class)->redrive('redrive-child-refused', $source->id);

        $this->assertFalse($result['accepted']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame($before, $this->engineSnapshot());
        $this->assertSame($currentRun, $source->instance->fresh()->current_run_id);
    }

    public function testRedriveRefusesARunWhoseChildFailed(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestParentFailingChildWorkflow::class, 'redrive-child-failed');
        $workflow->start();
        $this->assertTrue($workflow->refresh()->failed());
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $before = $this->engineSnapshot();

        $result = app(WorkflowControlPlane::class)->redrive('redrive-child-failed', $source->id);

        $this->assertFalse($result['accepted']);
        $this->assertSame('unsupported_child_outcome', $result['reason']);
        $this->assertSame($before, $this->engineSnapshot());
    }

    public function testRunDetailAndDescribeReportTheRedriveDecision(): void
    {
        WorkflowStub::fake();
        $controlPlane = app(WorkflowControlPlane::class);

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-report-1');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $failedRunId = $workflow->runId();

        $this->assertRedriveAction($controlPlane, 'redrive-report-1', $failedRunId, true, null);

        $result = $controlPlane->redrive('redrive-report-1', $failedRunId);
        $this->assertTrue($result['accepted'], (string) $result['reason']);

        $this->assertRedriveAction($controlPlane, 'redrive-report-1', $failedRunId, false, 'already_redriven');
        $this->assertRedriveAction(
            $controlPlane,
            'redrive-report-1',
            $result['workflow_run_id'],
            false,
            'run_not_failed',
        );

        $ambiguous = WorkflowStub::make(TestThrowAfterGreetingWorkflow::class, 'redrive-report-2');
        $ambiguous->start('Taylor');
        $this->assertTrue($ambiguous->refresh()->failed());

        $this->assertRedriveAction(
            $controlPlane,
            'redrive-report-2',
            $ambiguous->runId(),
            false,
            'workflow_code_failure_requires_corrected_definition',
        );
        $this->assertSame(
            'workflow_code_failure_requires_corrected_definition',
            $controlPlane->redrive('redrive-report-2', $ambiguous->runId())['reason'],
        );
    }

    public function testFailedSuccessorCanBeRedrivenAgainWithoutRepeatingCompletedActivity(): void
    {
        config()->set('workflows.v2.testing.redrive_failures_before_success', 2);
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-chain-1');
        $workflow->start('Taylor');

        $controlPlane = app(WorkflowControlPlane::class);
        $first = $controlPlane->redrive('redrive-chain-1', $workflow->runId());
        $this->assertTrue($first['accepted'], (string) $first['reason']);
        $this->assertSame('failed', WorkflowRun::query()->findOrFail($first['workflow_run_id'])->status->value);

        $second = $controlPlane->redrive('redrive-chain-1', $first['workflow_run_id']);
        $this->assertTrue($second['accepted'], (string) $second['reason']);
        $this->assertSame('completed', WorkflowRun::query()->findOrFail($second['workflow_run_id'])->status->value);
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(3, (int) Cache::get('test:redrive:second-calls'));

        $firstStart = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $first['workflow_run_id'])
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $secondStart = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $second['workflow_run_id'])
            ->where('event_type', HistoryEventType::WorkflowStarted)
            ->firstOrFail();
        $this->assertSame($firstStart->payload['replayed_started_at'], $secondStart->payload['replayed_started_at']);

        $firstCompletion = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $first['workflow_run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $secondCompletion = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $second['workflow_run_id'])
            ->where('event_type', HistoryEventType::ActivityCompleted)
            ->firstOrFail();
        $this->assertSame(
            $firstCompletion->payload['reused_recorded_at'],
            $secondCompletion->payload['reused_recorded_at']
        );
    }

    public function testFailedSourceAndRedriveSuccessorSurviveExportImport(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-import-1');
        $workflow->start('Taylor');
        $sourceRunId = $workflow->runId();

        $redrive = app(WorkflowControlPlane::class)->redrive('redrive-import-1', $sourceRunId);
        $this->assertTrue($redrive['accepted'], (string) $redrive['reason']);
        $successorRunId = $redrive['workflow_run_id'];
        $sourceExport = HistoryExport::forRun(WorkflowRun::query()->findOrFail($sourceRunId));
        $successorExport = HistoryExport::forRun(WorkflowRun::query()->findOrFail($successorRunId));
        $expectedOutput = WorkflowRun::query()->findOrFail($successorRunId)->workflowOutput();
        $this->assertCount(1, $sourceExport['links']['children']);
        $this->assertCount(1, $successorExport['links']['parents']);
        $this->assertSame('redrive', $successorExport['links']['parents'][0]['type']);
        $this->assertSame([], $successorExport['links']['children']);

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

        $this->assertSame(0, DB::table('workflow_links')->count());
        $this->assertSame('imported', EmbeddedV2HistoryImport::import($sourceExport)['status']);
        $this->assertSame(0, DB::table('workflow_links')->count());
        // The successor is not present yet, so its edge is deferred. Seed a
        // conflicting legacy edge to retain the import rollback regression.
        $exportedLink = $sourceExport['links']['children'][0];
        $legacyLink = WorkflowLink::query()->create([
            'id' => $exportedLink['id'],
            'parent_workflow_instance_id' => $exportedLink['parent_workflow_instance_id'],
            'parent_workflow_run_id' => $exportedLink['parent_workflow_run_id'],
            'child_workflow_instance_id' => $exportedLink['child_workflow_instance_id'],
            'child_workflow_run_id' => $exportedLink['child_workflow_run_id'],
            'link_type' => $exportedLink['type'],
            'sequence' => 999,
            'is_primary_parent' => $exportedLink['is_primary_parent'],
        ]);
        $beforeConflict = $legacyLink->fresh()
            ->getAttributes();
        $conflictingImport = EmbeddedV2HistoryImport::import($successorExport);
        $this->assertSame('rejected', $conflictingImport['status']);
        $this->assertSame('target.write_failed', $conflictingImport['eligibility']['errors'][0]['rule']);
        $this->assertNull(WorkflowRun::query()->find($successorRunId));
        $this->assertSame(0, array_sum($conflictingImport['rows']));
        $this->assertSame($beforeConflict, $legacyLink->fresh()->getAttributes());
        DB::table('workflow_links')->update([
            'sequence' => $redrive['resume_step_sequence'],
        ]);
        $successorImport = EmbeddedV2HistoryImport::import($successorExport);
        $this->assertSame('imported', $successorImport['status'], json_encode($successorImport, JSON_THROW_ON_ERROR));
        $this->assertSame(1, DB::table('workflow_links')->count());
        $this->assertSame('already_imported', EmbeddedV2HistoryImport::import($successorExport)['status']);
        $this->assertSame(1, DB::table('workflow_links')->count());

        DB::purge();
        DB::reconnect();

        $reloadedRun = WorkflowRun::query()->findOrFail($successorRunId);
        $this->assertSame($expectedOutput, $reloadedRun->workflowOutput());
        $reloadedInstance = WorkflowInstance::query()->findOrFail('redrive-import-1');
        $this->assertSame($successorRunId, $reloadedInstance->current_run_id);
        $importedLink = WorkflowLink::query()
            ->where('child_workflow_run_id', $successorRunId)
            ->where('link_type', 'redrive')
            ->firstOrFail();
        $this->assertSame($sourceRunId, $importedLink->parent_workflow_run_id);
        $this->assertSame($sourceRunId, RunActivityView::activitiesForRun($reloadedRun)[0]['reused_from_run_id']);
        $this->assertSame(
            $expectedOutput['time_at_start'],
            (new WorkflowReplayer())->replay($reloadedRun)
                ->workflow->replayTimes()['time_at_start']
        );

        WorkflowHistoryEvent::query()->where('workflow_run_id', $sourceRunId)->delete();
        WorkflowRun::query()->whereKey($sourceRunId)->delete();
        DB::purge();
        DB::reconnect();

        $retainedSuccessor = WorkflowRun::query()->findOrFail($successorRunId);
        $this->assertSame(
            $expectedOutput['time_at_start'],
            (new WorkflowReplayer())->replay($retainedSuccessor)
                ->workflow->replayTimes()['time_at_start']
        );
        $this->assertSame($sourceRunId, RunActivityView::activitiesForRun($retainedSuccessor)[0]['reused_from_run_id']);

        $database = config('database.connections.' . config('database.default'));
        $this->assertIsArray($database);
        $coldReplay = new Process([PHP_BINARY, __DIR__ . '/../../Fixtures/V2/redrive_cold_replay.php'], env: [
            'REDRIVE_DB_CONFIG' => json_encode($database, JSON_THROW_ON_ERROR),
            'REDRIVE_RUN_ID' => $successorRunId,
        ]);
        $coldReplay->mustRun();

        $this->assertSame([
            'completed' => true,
            'result' => $expectedOutput,
        ], json_decode($coldReplay->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * Both views show the one existing link row as a diagnostic fallback, and
     * no typed redrive edge.
     */
    private function assertOneDiagnosticRedriveEdge(WorkflowRun $source, WorkflowRun $successor): void
    {
        foreach ([
            'parent' => [RunDetailView::forRun($successor->fresh())['parents'], $source->id],
            'successor' => [RunDetailView::forRun($source->fresh())['continuedWorkflows'], $successor->id],
        ] as $side => [$view, $runId]) {
            $redrive = $this->redriveEdges($view);
            $this->assertCount(1, $redrive, $side);
            $this->assertSame('mutable_open_fallback', $redrive[0]['history_authority'], $side);
            $this->assertTrue($redrive[0]['diagnostic_only'], $side);
            $this->assertSame($runId, $redrive[0]['workflow_run_id'], $side);
        }
    }

    /**
     * @param list<array<string, mixed>> $view
     *
     * @return list<array<string, mixed>>
     */
    private function redriveEdges(array $view): array
    {
        return array_values(array_filter($view, static fn (array $entry): bool => $entry['link_type'] === 'redrive'));
    }

    private function failedSignalSource(string $instanceId): WorkflowRun
    {
        $workflow = WorkflowStub::make(TestRedriveSignalWorkflow::class, $instanceId);
        $workflow->start('Taylor');
        $workflow->signal('approve', 'Grace');
        $this->assertTrue($workflow->refresh()->failed());

        return WorkflowRun::query()->findOrFail($workflow->runId());
    }

    private function drainRetryProbe(string $runId): WorkflowRun
    {
        for ($turn = 0; $turn < 20; ++$turn) {
            $run = WorkflowRun::query()->findOrFail($runId);
            if ($run->status->isTerminal()) {
                return $run;
            }
            $task = WorkflowTask::query()->where('workflow_run_id', $runId)
                ->where('status', TaskStatus::Ready)->orderBy('available_at')->firstOrFail();
            if ($task->available_at->isFuture()) {
                $this->travelTo($task->available_at);
            }
            $job = $task->task_type === TaskType::Activity
                ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
            $this->app->call([$job, 'handle']);
        }
        $this->fail('The ordinary retry fixture did not converge within twenty task deliveries.');
    }

    /**
     * Every engine table and read projection a refused redrive must leave
     * untouched, including the run's message cursor.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function engineSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            \Workflow\V2\Models\WorkflowInstance::class,
            WorkflowRun::class,
            WorkflowCommand::class,
            WorkflowHistoryEvent::class,
            \Workflow\V2\Models\WorkflowTask::class,
            WorkflowLink::class,
            \Workflow\V2\Models\WorkflowMemo::class,
            \Workflow\V2\Models\WorkflowSearchAttribute::class,
            WorkflowSignal::class,
            WorkflowUpdate::class,
            \Workflow\V2\Models\ActivityExecution::class,
            \Workflow\V2\Models\ActivityAttempt::class,
            \Workflow\V2\Models\WorkflowFailure::class,
            \Workflow\V2\Models\WorkflowRunSummary::class,
            \Workflow\V2\Models\WorkflowTimelineEntry::class,
        ] as $model) {
            $snapshot[$model] = $model::query()->orderBy((new $model())->getKeyName())->get()->map(
                static fn (\Illuminate\Database\Eloquent\Model $row): array => $row->getRawOriginal(),
            )->all();
        }

        return $snapshot;
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private function childEvents(string $runId): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [
                HistoryEventType::ChildWorkflowScheduled->value,
                HistoryEventType::ChildRunStarted->value,
                HistoryEventType::ChildRunCompleted->value,
            ])
            ->orderBy('sequence')
            ->get()
            ->all();
    }

    private function sourceEvent(WorkflowRun $source, HistoryEventType $type): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $source->id)
            ->where('event_type', $type)
            ->orderBy('sequence')
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function assertRefusedWithoutMutation(
        string $instanceId,
        WorkflowRun $source,
        string $reason,
        array $options = [],
    ): array {
        $cursor = (int) $source->fresh()
            ->message_cursor_position;
        $events = WorkflowHistoryEvent::query()->count();

        $result = app(WorkflowControlPlane::class)->redrive($instanceId, $source->id, $options);

        $this->assertFalse($result['accepted']);
        $this->assertSame($reason, $result['reason']);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $instanceId)->count());
        $this->assertSame($cursor, (int) $source->fresh()->message_cursor_position);
        $this->assertSame($events, WorkflowHistoryEvent::query()->count());
        $this->assertSame($source->id, $source->instance->fresh()->current_run_id);

        return $result;
    }

    private function assertRedriveAction(
        WorkflowControlPlane $controlPlane,
        string $instanceId,
        string $runId,
        bool $allowed,
        ?string $reason,
    ): void {
        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));
        $this->assertSame($allowed, $detail['can_redrive'] ?? null, "run detail can_redrive for {$runId}");
        $this->assertArrayHasKey('redrive_blocked_reason', $detail);
        $this->assertSame($reason, $detail['redrive_blocked_reason'], "run detail reason for {$runId}");

        $describe = $controlPlane->describe($instanceId, [
            'run_id' => $runId,
        ]);
        $this->assertSame($allowed, $describe['actions']['can_redrive'] ?? null, "describe can_redrive for {$runId}");
        $this->assertArrayHasKey('redrive_blocked_reason', $describe['actions']);
        $this->assertSame($reason, $describe['actions']['redrive_blocked_reason'], "describe reason for {$runId}");
    }
    private function assertReplayClock(?CarbonInterface $expected, mixed $actual, string $message = ''): void
    {
        $this->assertNotNull($expected, $message);
        $this->assertIsString($actual, $message);
        $this->assertSame($expected->format('U.u'), Carbon::parse($actual)->format('U.u'), $message);
    }

}
