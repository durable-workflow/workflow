<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestLargeMemoWorkflow;
use Tests\Fixtures\V2\TestLargePayloadChildWorkflow;
use Tests\Fixtures\V2\TestLargePayloadWorkflow;
use Tests\Fixtures\V2\TestLargeSearchAttributeWorkflow;
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

    public function testPayloadSizeLimitFailsActivityScheduling(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        // Set a very low payload limit (64 bytes) to trigger enforcement.
        config(['workflows.v2.structural_limits.payload_size_bytes' => 64]);

        // Build a payload that exceeds 64 bytes when serialized.
        $largePayload = str_repeat('x', 200);

        $workflow = WorkflowStub::make(TestLargePayloadWorkflow::class, 'limit-payload-act-1');
        $workflow->start($largePayload);

        $workflow->refresh();

        $this->assertTrue($workflow->failed(), 'Workflow should have failed due to payload size limit.');

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'limit-payload-act-1')
            ->firstOrFail();

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(
            FailureCategory::StructuralLimit->value,
            $failure->failure_category?->value ?? $failure->failure_category,
        );

        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->firstOrFail();

        $this->assertSame('payload_size', $failedEvent->payload['structural_limit_kind'] ?? null);
    }

    public function testPayloadSizeLimitPassesUnderLimit(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        config(['workflows.v2.structural_limits.payload_size_bytes' => 1048576]);

        $workflow = WorkflowStub::make(TestLargePayloadWorkflow::class, 'limit-payload-ok-1');
        $workflow->start('short');

        $workflow->refresh();

        $this->assertTrue($workflow->completed(), 'Workflow should have completed under payload limit.');
    }

    public function testPayloadSizeLimitFailsChildWorkflowScheduling(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        config(['workflows.v2.structural_limits.payload_size_bytes' => 64]);

        $largePayload = str_repeat('y', 200);

        $workflow = WorkflowStub::make(TestLargePayloadChildWorkflow::class, 'limit-payload-child-1');
        $workflow->start($largePayload);

        $workflow->refresh();

        $this->assertTrue($workflow->failed(), 'Workflow should have failed due to child payload size limit.');

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'limit-payload-child-1')
            ->firstOrFail();

        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->firstOrFail();

        $this->assertSame('payload_size', $failedEvent->payload['structural_limit_kind'] ?? null);
    }

    public function testMemoSizeLimitFailsUpsert(): void
    {
        WorkflowStub::fake();

        // Set a very low memo limit (32 bytes) to trigger enforcement.
        config(['workflows.v2.structural_limits.memo_size_bytes' => 32]);

        $largeEntries = [
            'description' => str_repeat('a', 200),
        ];

        $workflow = WorkflowStub::make(TestLargeMemoWorkflow::class, 'limit-memo-1');
        $workflow->start($largeEntries);

        $workflow->refresh();

        $this->assertTrue($workflow->failed(), 'Workflow should have failed due to memo size limit.');

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'limit-memo-1')
            ->firstOrFail();

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(
            FailureCategory::StructuralLimit->value,
            $failure->failure_category?->value ?? $failure->failure_category,
        );

        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->firstOrFail();

        $this->assertSame('memo_size', $failedEvent->payload['structural_limit_kind'] ?? null);
    }

    public function testMemoSizeLimitPassesUnderLimit(): void
    {
        WorkflowStub::fake();

        config(['workflows.v2.structural_limits.memo_size_bytes' => 1048576]);

        $workflow = WorkflowStub::make(TestLargeMemoWorkflow::class, 'limit-memo-ok-1');
        $workflow->start(['status' => 'ok']);

        $workflow->refresh();

        $this->assertTrue($workflow->completed(), 'Workflow should have completed under memo limit.');
    }

    public function testSearchAttributeSizeLimitFailsUpsert(): void
    {
        WorkflowStub::fake();

        // Set a very low search attribute limit (32 bytes) to trigger enforcement.
        config(['workflows.v2.structural_limits.search_attribute_size_bytes' => 32]);

        // Build enough attributes to exceed 32 bytes when JSON-encoded.
        // Each value stays under the 191-char per-value limit.
        $attributes = [];
        for ($i = 0; $i < 20; $i++) {
            $attributes["attr_{$i}"] = str_repeat('v', 100);
        }

        $workflow = WorkflowStub::make(TestLargeSearchAttributeWorkflow::class, 'limit-sa-1');
        $workflow->start($attributes);

        $workflow->refresh();

        $this->assertTrue($workflow->failed(), 'Workflow should have failed due to search attribute size limit.');

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'limit-sa-1')
            ->firstOrFail();

        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame(
            FailureCategory::StructuralLimit->value,
            $failure->failure_category?->value ?? $failure->failure_category,
        );

        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->firstOrFail();

        $this->assertSame('search_attribute_size', $failedEvent->payload['structural_limit_kind'] ?? null);
    }

    public function testSearchAttributeSizeLimitPassesUnderLimit(): void
    {
        WorkflowStub::fake();

        config(['workflows.v2.structural_limits.search_attribute_size_bytes' => 1048576]);

        $workflow = WorkflowStub::make(TestLargeSearchAttributeWorkflow::class, 'limit-sa-ok-1');
        $workflow->start(['status' => 'ok']);

        $workflow->refresh();

        $this->assertTrue($workflow->completed(), 'Workflow should have completed under search attribute limit.');
    }

    public function testDisabledPayloadLimitDoesNotBlock(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello');

        config(['workflows.v2.structural_limits.payload_size_bytes' => 0]);

        $workflow = WorkflowStub::make(TestLargePayloadWorkflow::class, 'limit-payload-disabled-1');
        $workflow->start(str_repeat('x', 5000));

        $workflow->refresh();

        $this->assertTrue($workflow->completed(), 'Workflow should have completed with payload limit disabled.');
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
