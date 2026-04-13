<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestManyActivitiesWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\StructuralLimits;
use Workflow\V2\WorkflowStub;

final class V2StructuralLimitTest extends TestCase
{
    public function testCommandBatchSizeLimitFailsRunWithTypedFailure(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        // Set a very low command batch limit to trigger enforcement.
        config(['workflows.v2.structural_limits.command_batch_size' => 2]);

        $workflow = WorkflowStub::make(TestManyActivitiesWorkflow::class, 'limit-batch-1');
        $workflow->start(5); // Tries to dispatch 5 parallel activities, exceeding limit of 2.

        $workflow->refresh();

        // The run should have failed due to structural limit.
        $this->assertTrue($workflow->failed(), 'Workflow should have failed.');

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'limit-batch-1')
            ->firstOrFail();

        $this->assertSame(RunStatus::Failed->value, $run->status->value ?? $run->status);

        // Verify failure row has structural_limit category.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(
            FailureCategory::StructuralLimit->value,
            $failure->failure_category?->value ?? $failure->failure_category,
        );

        // Verify history event carries structural limit metadata.
        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->firstOrFail();

        $this->assertSame('command_batch_size', $failedEvent->payload['structural_limit_kind'] ?? null);
        $this->assertArrayHasKey('structural_limit_value', $failedEvent->payload);
        $this->assertArrayHasKey('structural_limit_configured', $failedEvent->payload);
        $this->assertSame(FailureCategory::StructuralLimit->value, $failedEvent->payload['failure_category']);
    }

    public function testCommandBatchSizeLimitPassesUnderLimit(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        // Set batch limit high enough to allow 3 parallel activities.
        config(['workflows.v2.structural_limits.command_batch_size' => 10]);

        $workflow = WorkflowStub::make(TestManyActivitiesWorkflow::class, 'limit-batch-ok-1');
        $workflow->start(3);

        $workflow->refresh();

        $this->assertTrue($workflow->completed(), 'Workflow should have completed.');
    }

    public function testDisabledLimitsDoNotBlock(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        // Disable all limits.
        config(['workflows.v2.structural_limits.command_batch_size' => 0]);
        config(['workflows.v2.structural_limits.pending_activity_count' => 0]);

        $workflow = WorkflowStub::make(TestManyActivitiesWorkflow::class, 'limit-disabled-1');
        $workflow->start(5);

        $workflow->refresh();

        $this->assertTrue($workflow->completed(), 'Workflow should have completed with limits disabled.');
    }

    public function testStructuralLimitsSnapshotAppearsInHealthCheck(): void
    {
        $snapshot = StructuralLimits::snapshot();

        $this->assertIsArray($snapshot);
        $this->assertArrayHasKey('pending_activity_count', $snapshot);
        $this->assertArrayHasKey('payload_size_bytes', $snapshot);
        $this->assertArrayHasKey('command_batch_size', $snapshot);
    }

    public function testFailureSnapshotsIncludeStructuralLimitMetadata(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        config(['workflows.v2.structural_limits.command_batch_size' => 1]);

        $workflow = WorkflowStub::make(TestManyActivitiesWorkflow::class, 'limit-snapshot-1');
        $workflow->start(3);

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'limit-snapshot-1')
            ->firstOrFail();

        $snapshots = FailureSnapshots::forRun($run);

        $this->assertNotEmpty($snapshots, 'Failure snapshots should not be empty.');

        $firstSnapshot = $snapshots[0];
        $this->assertSame(FailureCategory::StructuralLimit->value, $firstSnapshot['failure_category']);
        $this->assertSame('command_batch_size', $firstSnapshot['structural_limit_kind']);
    }
}
