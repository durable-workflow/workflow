<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestRedriveMarkersWorkflow;
use Tests\Fixtures\V2\TestRedriveTimerWorkflow;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\Fixtures\V2\TestThrowAfterGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\RunActivityView;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunTimerView;
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

    public function testServiceRunRequiresMatchingRecordedWorkerDefinitionFingerprint(): void
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
        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityCompleted, [
            'activity_execution_id' => 'service-activity-1',
            'activity_type' => 'remote.first',
            'sequence' => 1,
            'result' => Serializer::serializeWithCodec('avro', 'first-result'),
            'payload_codec' => 'avro',
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

    public function testWorkflowFailureAfterCompletedActivityHasNoSafeRedriveBoundary(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestThrowAfterGreetingWorkflow::class, 'redrive-ambiguous-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        $result = app(WorkflowControlPlane::class)->redrive('redrive-ambiguous-1', $workflow->runId());

        $this->assertFalse($result['accepted']);
        $this->assertSame('unrecorded_failure_boundary', $result['reason']);
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
            $this->assertSame(
                $original->recorded_at?->toIso8601String(),
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
        $this->assertSame(
            $sourceFired->recorded_at?->toIso8601String(),
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
        ], (new WorkflowReplayer())->replayExport($export)->workflow->replayTimes());
        $this->assertSame([
            'time_after_timer' => $expectedTime,
        ], (new WorkflowReplayer())->replay($successor->fresh())->workflow->replayTimes());

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
            'unrecorded_failure_boundary',
        );
        $this->assertSame(
            'unrecorded_failure_boundary',
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
}
