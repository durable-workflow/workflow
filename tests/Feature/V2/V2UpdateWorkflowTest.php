<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestUpdateWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\WorkflowStub;

final class V2UpdateWorkflowTest extends TestCase
{
    public function testAttemptUpdateAppliesDurableStateAndReturnsTypedResult(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $update = $workflow->attemptUpdate('approve', true, 'api');

        $this->assertTrue($update->accepted());
        $this->assertTrue($update->completed());
        $this->assertSame('update_completed', $update->outcome());
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], $update->result());

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], $workflow->currentState());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $timeline = HistoryTimeline::forRun($run);
        $updateEntries = array_values(array_filter(
            $timeline,
            static fn (array $entry): bool => str_starts_with($entry['type'], 'Update'),
        ));

        $this->assertSame(
            ['UpdateAccepted', 'UpdateApplied', 'UpdateCompleted'],
            array_column($updateEntries, 'type'),
        );
        $this->assertSame('command', $updateEntries[0]['kind']);
        $this->assertSame('update', $updateEntries[1]['kind']);
        $this->assertSame('approve', $updateEntries[0]['update_name']);
        $this->assertSame('approve', $updateEntries[1]['update_name']);
        $this->assertSame('approve', $updateEntries[2]['update_name']);

        $detail = RunDetailView::forRun($run->fresh());

        $this->assertTrue($detail['can_update']);
        $this->assertTrue($detail['can_signal']);
        $this->assertCount(2, $detail['commands']);
        $this->assertSame('update', $detail['commands'][1]['type']);
        $this->assertSame('approve', $detail['commands'][1]['target_name']);
        $this->assertTrue($detail['commands'][1]['result_available']);
        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api'],
        ], unserialize($detail['commands'][1]['result']));

        $workflow->signal('name-provided', 'Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:api', 'signal:Taylor'],
            'workflow_id' => 'order-update',
            'run_id' => $workflow->runId(),
        ], $workflow->output());
    }

    public function testCallingAnnotatedUpdateMethodReturnsTheRawUpdateResult(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-dynamic');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->approve(true, 'console');

        $this->assertSame([
            'approved' => true,
            'events' => ['started', 'approved:yes:console'],
        ], $result);

        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => true,
            'events' => ['started', 'approved:yes:console'],
        ], $workflow->currentState());
    }

    public function testUpdateFailuresAreRecordedWithoutClosingTheRun(): void
    {
        $workflow = WorkflowStub::make(TestUpdateWorkflow::class, 'order-update-failure');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting');

        $result = $workflow->attemptUpdate('explode', 'boom');

        $this->assertTrue($result->accepted());
        $this->assertTrue($result->failed());
        $this->assertSame('update_failed', $result->outcome());
        $this->assertSame('boom', $result->failureMessage());
        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertSame([
            'stage' => 'waiting-for-name',
            'approved' => false,
            'events' => ['started'],
        ], $workflow->currentState());

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()
            ->where('source_id', $result->commandId())
            ->firstOrFail();

        $this->assertSame('workflow_command', $failure->source_kind);
        $this->assertSame('update', $failure->propagation_kind);
        $this->assertSame('boom', $failure->message);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'UpdateAccepted',
            'UpdateCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAttemptUpdateRejectsHistoricalSelectedRuns(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'order-update-historical',
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'run_count' => 2,
            'started_at' => now()->subMinutes(5),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinutes(4),
            'last_progress_at' => now()->subMinutes(4),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestUpdateWorkflow::class,
            'workflow_type' => 'test-update-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
        ])->save();

        $result = WorkflowStub::loadRun($historicalRun->id)->attemptUpdate('approve', true);

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedNotCurrent());
        $this->assertSame('selected_run_not_current', $result->rejectionReason());
        $this->assertSame($historicalRun->id, $result->runId());
    }

    private function waitFor(callable $condition): void
    {
        $startedAt = microtime(true);

        while ((microtime(true) - $startedAt) < 5) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Condition was not met within 5 seconds.');
    }
}
