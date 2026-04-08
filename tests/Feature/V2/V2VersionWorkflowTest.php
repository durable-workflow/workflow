<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestVersionMinSupportedWorkflow;
use Tests\Fixtures\V2\TestVersionWorkflow;
use Tests\TestCase;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
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
