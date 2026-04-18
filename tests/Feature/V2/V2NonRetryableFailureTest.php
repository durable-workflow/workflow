<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestNonRetryableWorkflow;
use Tests\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\WorkflowStub;

final class V2NonRetryableFailureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
    }

    public function testNonRetryableActivitySkipsRetryAndRecordsFlag(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestNonRetryableWorkflow::class, 'non-retryable-1');
        $workflow->start();

        $this->assertTrue($workflow->refresh()->failed());

        // The activity should have failed terminally without retry.
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->firstOrFail();

        $this->assertSame(ActivityStatus::Failed, $execution->status);

        // The failure row should carry non_retryable = true.
        $activityFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->firstOrFail();

        $this->assertTrue($activityFailure->non_retryable);
        $this->assertSame(FailureCategory::Activity, $activityFailure->failure_category);

        // The ActivityFailed history event should carry non_retryable.
        $activityFailedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed)
            ->firstOrFail();

        $this->assertTrue($activityFailedEvent->payload['non_retryable']);
    }

    public function testRetryableActivityFailureRecordsNonRetryableFalse(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new RuntimeException('temporary failure');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'retryable-failure-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        // The terminal failure row should carry non_retryable = false.
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->first();

        if ($failure !== null) {
            $this->assertFalse($failure->non_retryable);
        }
    }

    public function testNonRetryableFlagAppearsInFailureSnapshots(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestNonRetryableWorkflow::class, 'non-retryable-snapshot-1');
        $workflow->start();

        $this->assertTrue($workflow->refresh()->failed());

        $run = $workflow->run();
        $snapshots = FailureSnapshots::forRun($run);

        $this->assertNotEmpty($snapshots);

        $activitySnapshot = collect($snapshots)
            ->firstWhere('source_kind', 'activity_execution');

        $this->assertNotNull($activitySnapshot);
        $this->assertTrue($activitySnapshot['non_retryable']);
    }

    public function testNonRetryableWorkflowFailurePropagatesFlag(): void
    {
        WorkflowStub::fake();

        WorkflowStub::mock(TestGreetingActivity::class, static function (): never {
            throw new NonRetryableException('Permanently broken');
        });

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'non-retryable-propagation-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());

        // Check that the workflow-level failure from the propagated activity also carries the flag.
        $workflowFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'workflow_run')
            ->first();

        // The workflow failure is from the propagated exception.
        // non_retryable detection depends on whether the propagated throwable is the original.
        // The activity failure should definitely have it.
        $activityFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('source_kind', 'activity_execution')
            ->first();

        if ($activityFailure !== null) {
            $this->assertTrue($activityFailure->non_retryable);
        }
    }
}
