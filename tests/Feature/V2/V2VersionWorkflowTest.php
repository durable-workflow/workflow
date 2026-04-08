<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestVersionAfterSignalWorkflow;
use Tests\Fixtures\V2\TestVersionMinSupportedWorkflow;
use Tests\Fixtures\V2\TestVersionWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\WorkflowStub;

final class V2VersionWorkflowTest extends TestCase
{
    public function testVersionMarkersAreRecordedAndReplayedInQueriesAndTimeline(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestVersionWorkflow::class, 'version-new');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame('waiting-for-finish', $workflow->currentStage());
        $this->assertSame(2, $workflow->currentVersion());
        $this->assertSame('v3_result', $workflow->currentResult());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->with('summary')->findOrFail($workflow->runId());

        $detail = RunDetailView::forRun($run);
        $versionMarker = collect($detail['timeline'])->firstWhere('type', HistoryEventType::VersionMarkerRecorded->value);

        $this->assertIsArray($versionMarker);
        $this->assertSame('version', $versionMarker['kind']);
        $this->assertSame('version_marker', $versionMarker['source_kind']);
        $this->assertSame('step-1', $versionMarker['source_id']);
        $this->assertSame('step-1', $versionMarker['version_change_id']);
        $this->assertSame(2, $versionMarker['version']);
        $this->assertSame(WorkflowStub::DEFAULT_VERSION, $versionMarker['version_min_supported']);
        $this->assertSame(2, $versionMarker['version_max_supported']);
        $this->assertSame('Recorded version marker step-1 = 2.', $versionMarker['summary']);

        $workflow->signal('finish', 'done');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'version' => 2,
            'result' => 'v3_result',
            'finish' => 'done',
            'workflow_id' => 'version-new',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testVersionMarkersReuseRecordedHistoryValue(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestVersionWorkflow::class, 'version-history');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::VersionMarkerRecorded->value,
            'payload' => [
                'sequence' => 1,
                'change_id' => 'step-1',
                'version' => 1,
                'min_supported' => WorkflowStub::DEFAULT_VERSION,
                'max_supported' => 2,
            ],
            'recorded_at' => now(),
        ]);

        $run->forceFill([
            'last_history_sequence' => 3,
        ])->save();

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame(1, $workflow->currentVersion());
        $this->assertSame('v2_result', $workflow->currentResult());

        $workflow->signal('finish', 'done');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('v2_result', $workflow->output()['result']);
    }

    public function testVersionMarkersFailRunWhenRecordedVersionIsNotSupported(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestVersionMinSupportedWorkflow::class, 'version-unsupported');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::VersionMarkerRecorded->value,
            'payload' => [
                'sequence' => 1,
                'change_id' => 'step-1',
                'version' => WorkflowStub::DEFAULT_VERSION,
                'min_supported' => WorkflowStub::DEFAULT_VERSION,
                'max_supported' => 2,
            ],
            'recorded_at' => now(),
        ]);

        $run->forceFill([
            'last_history_sequence' => 3,
        ])->save();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(VersionNotSupportedException::class, $failure->exception_class);
        $this->assertStringContainsString("Version -1 for change ID 'step-1' is not supported", $failure->message);
    }

    public function testVersionMarkersValidateRecordedChangeIdentity(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestVersionWorkflow::class, 'version-mismatch');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        WorkflowHistoryEvent::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::VersionMarkerRecorded->value,
            'payload' => [
                'sequence' => 1,
                'change_id' => 'wrong-step',
                'version' => 1,
                'min_supported' => WorkflowStub::DEFAULT_VERSION,
                'max_supported' => 2,
            ],
            'recorded_at' => now(),
        ]);

        $run->forceFill([
            'last_history_sequence' => 3,
        ])->save();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->failed());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(\LogicException::class, $failure->exception_class);
        $this->assertStringContainsString('expected change ID [step-1] but history recorded [wrong-step]', $failure->message);
    }

    public function testVersionMarkersRecordAfterEarlierSignalsOnCurrentCompatibility(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-b');
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $workflow = WorkflowStub::make(TestVersionAfterSignalWorkflow::class, 'version-after-signal-current');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $workflow->signal('go', 'proceed');
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame([
            'gate' => 'proceed',
            'version' => 1,
            'result' => 'v2_result',
            'workflow_id' => 'version-after-signal-current',
            'run_id' => $workflow->runId(),
        ], $workflow->output());

        /** @var WorkflowHistoryEvent $versionMarker */
        $versionMarker = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::VersionMarkerRecorded->value)
            ->firstOrFail();

        $this->assertSame(2, $versionMarker->payload['sequence'] ?? null);
        $this->assertSame(1, $versionMarker->payload['version'] ?? null);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(3, $execution->sequence);
    }

    public function testOlderCompatibilityFallsBackToDefaultVersionWithoutRecordingMarkerAfterEarlierSignal(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-b');
        config()->set('workflows.v2.compatibility.supported', ['build-a', 'build-b']);
        Queue::fake();

        $run = $this->createLegacyReadyRun(
            instanceId: 'version-after-signal-legacy',
            workflowClass: TestVersionAfterSignalWorkflow::class,
            workflowType: 'test-version-after-signal-workflow',
            compatibility: 'build-a',
            historyEvents: [
                [
                    'event_type' => HistoryEventType::SignalWaitOpened->value,
                    'payload' => [
                        'sequence' => 1,
                        'signal_name' => 'go',
                        'signal_wait_id' => 'legacy-go-signal',
                    ],
                ],
                [
                    'event_type' => HistoryEventType::SignalApplied->value,
                    'payload' => [
                        'sequence' => 1,
                        'signal_name' => 'go',
                        'signal_wait_id' => 'legacy-go-signal',
                        'value' => Serializer::serialize('proceed'),
                    ],
                ],
            ],
        );

        $this->drainReadyTasks();

        $workflow = WorkflowStub::loadRun($run->id);

        $this->assertTrue($workflow->completed());
        $this->assertSame([
            'gate' => 'proceed',
            'version' => WorkflowStub::DEFAULT_VERSION,
            'result' => 'v1_result',
            'workflow_id' => 'version-after-signal-legacy',
            'run_id' => $run->id,
        ], $workflow->output());

        $this->assertNull(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded->value)
            ->first());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(2, $execution->sequence);
    }

    public function testOlderCompatibilityUsesDefaultVersionOnFirstWorkflowTaskWithoutRecordingMarker(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-b');
        config()->set('workflows.v2.compatibility.supported', ['build-a', 'build-b']);
        Queue::fake();

        $run = $this->createLegacyReadyRun(
            instanceId: 'version-legacy-min-supported',
            workflowClass: TestVersionMinSupportedWorkflow::class,
            workflowType: 'test-version-min-supported-workflow',
            compatibility: 'build-a',
        );

        $this->drainReadyTasks();

        $this->assertTrue(WorkflowStub::loadRun($run->id)->failed());
        $this->assertNull(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::VersionMarkerRecorded->value)
            ->first());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(VersionNotSupportedException::class, $failure->exception_class);
        $this->assertStringContainsString("Version -1 for change ID 'step-1' is not supported", $failure->message);
    }

    /**
     * @param list<array{event_type: string, payload?: array<string, mixed>}> $historyEvents
     */
    private function createLegacyReadyRun(
        string $instanceId,
        string $workflowClass,
        string $workflowType,
        string $compatibility,
        array $historyEvents = [],
    ): WorkflowRun {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'reserved_at' => now()->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'status' => RunStatus::Waiting->value,
            'compatibility' => $compatibility,
            'arguments' => Serializer::serialize([]),
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $sequence = 1;

        foreach ([
            [
                'event_type' => HistoryEventType::StartAccepted->value,
                'payload' => [],
            ],
            [
                'event_type' => HistoryEventType::WorkflowStarted->value,
                'payload' => [
                    'workflow_type' => $workflowType,
                    'compatibility' => $compatibility,
                ],
            ],
            ...$historyEvents,
        ] as $event) {
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $sequence++,
                'event_type' => $event['event_type'],
                'payload' => $event['payload'] ?? [],
                'recorded_at' => now()->subSeconds(max(0, 61 - $sequence)),
            ]);
        }

        $run->forceFill([
            'last_history_sequence' => $sequence - 1,
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => null,
        ]);

        return $run;
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
