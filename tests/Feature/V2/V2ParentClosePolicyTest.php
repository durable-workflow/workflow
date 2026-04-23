<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestParentWithClosePolicyContinuingChildWorkflow;
use Tests\Fixtures\V2\TestParentWithClosePolicyWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunLineageView;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

final class V2ParentClosePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();
    }

    public function testChildLinkRecordsParentClosePolicyInHistoryAndRow(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'policy-recorded');
        $workflow->start('request_cancel');

        $this->drainReadyTasks();

        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'policy-recorded')
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame('request_cancel', $link->parent_close_policy);

        // History event also records the policy.
        $parentRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'policy-recorded')
            ->first();

        $scheduledEvent = $parentRun->historyEvents
            ->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildWorkflowScheduled
            );

        $this->assertNotNull($scheduledEvent);
        $this->assertSame('request_cancel', $scheduledEvent->payload['parent_close_policy']);
    }

    public function testDefaultPolicyIsAbandon(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'policy-default');
        $workflow->start('abandon');

        $this->drainReadyTasks();

        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'policy-default')
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $this->assertSame('abandon', $link->parent_close_policy);
    }

    public function testRequestCancelPolicyCancelsChildWhenParentIsTerminated(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'cancel-on-terminate');
        $workflow->start('request_cancel');

        $this->drainReadyTasks();

        // Parent should be waiting on the child.
        $this->assertSame('waiting', $workflow->refresh()->status());

        // Find the child instance.
        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'cancel-on-terminate')
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $childInstanceId = $link->child_workflow_instance_id;

        // Child should be running/waiting.
        $childStub = WorkflowStub::load($childInstanceId);
        $this->assertContains($childStub->status(), ['waiting', 'running', 'pending']);

        // Terminate the parent — policy should cancel the child.
        $result = $workflow->attemptTerminate('test termination');
        $this->assertTrue($result->accepted());

        // Parent is now terminated.
        $this->assertSame('terminated', $workflow->refresh()->status());

        // Child should now be cancelled by the parent-close policy.
        $childStub->refresh();
        $this->assertSame('cancelled', $childStub->status());

        // Child should have a cancel command recorded.
        $childRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childInstanceId)
            ->first();

        $cancelEvent = $childRun->historyEvents()
            ->where('event_type', HistoryEventType::CancelRequested->value)
            ->first();

        $this->assertNotNull($cancelEvent, 'Child should have a CancelRequested history event.');
    }

    public function testTerminatePolicyTerminatesChildWhenParentIsCancelled(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'terminate-on-cancel');
        $workflow->start('terminate');

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'terminate-on-cancel')
            ->where('link_type', 'child_workflow')
            ->first();

        $childInstanceId = $link->child_workflow_instance_id;
        $childStub = WorkflowStub::load($childInstanceId);
        $this->assertContains($childStub->status(), ['waiting', 'running', 'pending']);

        // Cancel the parent — terminate policy should terminate the child.
        $result = $workflow->attemptCancel('test cancellation');
        $this->assertTrue($result->accepted());

        $this->assertSame('cancelled', $workflow->refresh()->status());

        $childStub->refresh();
        $this->assertSame('terminated', $childStub->status());

        $childRun = WorkflowRun::query()
            ->where('workflow_instance_id', $childInstanceId)
            ->first();

        $terminateEvent = $childRun->historyEvents()
            ->where('event_type', HistoryEventType::TerminateRequested->value)
            ->first();

        $this->assertNotNull($terminateEvent, 'Child should have a TerminateRequested history event.');
    }

    public function testAbandonPolicyLeavesChildRunningWhenParentIsTerminated(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'abandon-on-terminate');
        $workflow->start('abandon');

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'abandon-on-terminate')
            ->where('link_type', 'child_workflow')
            ->first();

        $childInstanceId = $link->child_workflow_instance_id;
        $childStub = WorkflowStub::load($childInstanceId);
        $childStatusBefore = $childStub->status();
        $this->assertContains($childStatusBefore, ['waiting', 'running', 'pending']);

        // Terminate the parent — abandon means child keeps running.
        $result = $workflow->attemptTerminate('test');
        $this->assertTrue($result->accepted());

        $this->assertSame('terminated', $workflow->refresh()->status());

        // Child should still be in the same non-terminal state.
        $childStub->refresh();
        $this->assertContains($childStub->status(), ['waiting', 'running', 'pending']);
    }

    public function testEnforcementRecordsAppliedHistoryEventOnParentRun(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'applied-event');
        $workflow->start('request_cancel');

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());

        // Terminate the parent — policy should apply and record history event.
        $result = $workflow->attemptTerminate('test termination');
        $this->assertTrue($result->accepted());
        $this->assertSame('terminated', $workflow->refresh()->status());

        $parentRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'applied-event')
            ->first();

        $appliedEvent = $parentRun->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->first();

        $this->assertNotNull($appliedEvent, 'Parent run should have a ParentClosePolicyApplied history event.');
        $this->assertSame('request_cancel', $appliedEvent->payload['policy']);
        $this->assertArrayHasKey('child_instance_id', $appliedEvent->payload);
        $this->assertArrayHasKey('child_run_id', $appliedEvent->payload);
        $this->assertArrayHasKey('reason', $appliedEvent->payload);
    }

    public function testContinueAsNewDoesNotTriggerParentClosePolicy(): void
    {
        $workflow = WorkflowStub::make(
            TestParentWithClosePolicyContinuingChildWorkflow::class,
            'continue-no-trigger',
        );
        // Child will continue-as-new once (count 0 → 1), then complete on count 1 (max=1).
        $workflow->start('request_cancel', 1);

        $this->drainReadyTasks();

        // The child did continue-as-new, which should NOT have triggered the parent-close
        // policy. The parent should complete normally with the child's final result.
        $this->assertTrue($workflow->refresh()->completed());

        // Verify no ParentClosePolicyApplied event was recorded — the child's
        // continue-as-new was not treated as a close event.
        $parentRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'continue-no-trigger')
            ->first();

        $appliedEvents = $parentRun->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->get();

        $this->assertCount(0, $appliedEvents, 'Continue-as-new should not trigger parent-close policy.');
    }

    public function testParentClosePolicySurvivesContinueAsNewOnChild(): void
    {
        // Use a child that continues-as-new multiple times before completing.
        // Set max high enough that the child will be mid-continuation when we terminate.
        $workflow = WorkflowStub::make(
            TestParentWithClosePolicyContinuingChildWorkflow::class,
            'policy-survives-continue',
        );
        // Child: count=0, max=5 — will continue-as-new at counts 0,1,2,3,4 and complete at 5.
        $workflow->start('request_cancel', 5);

        // Drain a few tasks so the child starts and does at least one continue-as-new.
        $this->drainReadyTasks(maxIterations: 6);

        // Parent should be waiting on the child.
        $this->assertSame('waiting', $workflow->refresh()->status());

        // Find the current child link — should be pointing to a continued run.
        $links = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'policy-survives-continue')
            ->where('link_type', 'child_workflow')
            ->get();

        // At least 2 links: original + at least one continue-as-new.
        $this->assertGreaterThanOrEqual(2, $links->count());

        // The latest child link should still carry the parent_close_policy.
        $latestLink = $links->last();
        $this->assertSame('request_cancel', $latestLink->parent_close_policy);

        // Now terminate the parent — the policy should apply to the current child run.
        $childInstanceId = $latestLink->child_workflow_instance_id;
        $childStub = WorkflowStub::load($childInstanceId);

        // Child should be in a non-terminal state.
        $childStatus = $childStub->status();
        $this->assertContains($childStatus, ['waiting', 'running', 'pending']);

        $result = $workflow->attemptTerminate('test termination after continue-as-new');
        $this->assertTrue($result->accepted());
        $this->assertSame('terminated', $workflow->refresh()->status());

        // The request_cancel policy should have cancelled the child.
        $childStub->refresh();
        $this->assertSame('cancelled', $childStub->status());

        // Confirm the ParentClosePolicyApplied event was recorded.
        $parentRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'policy-survives-continue')
            ->first();

        $appliedEvent = $parentRun->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->first();

        $this->assertNotNull($appliedEvent, 'Parent-close policy should be enforced on the continued child run.');
        $this->assertSame('request_cancel', $appliedEvent->payload['policy']);
    }

    public function testRequestCancelPolicyCancelsChildWhenParentTimesOut(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'cancel-on-timeout');
        $workflow->start('request_cancel');

        $this->drainReadyTasks();

        // Parent should be waiting on the child.
        $this->assertSame('waiting', $workflow->refresh()->status());

        // Find the child instance and verify it's running.
        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'cancel-on-timeout')
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $childInstanceId = $link->child_workflow_instance_id;
        $childStub = WorkflowStub::load($childInstanceId);
        $this->assertContains($childStub->status(), ['waiting', 'running', 'pending']);

        // Set the parent run's deadline in the past to simulate a timeout.
        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'cancel-on-timeout')
            ->firstOrFail();

        $parentRun->forceFill([
            'run_timeout_seconds' => 60,
            'run_deadline_at' => Carbon::now()->subSeconds(10),
        ])->save();

        // Create a workflow task to trigger the timeout check.
        $deadlineTask = WorkflowTask::query()->create([
            'workflow_run_id' => $parentRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now(),
            'payload' => [
                'deadline_expired' => true,
            ],
            'leased_at' => now(),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        // Execute the task — the executor should detect the expired deadline.
        app(WorkflowExecutor::class)->run($parentRun->fresh(), $deadlineTask->fresh());

        // Parent should now be failed with timed_out reason.
        $parentRun->refresh();
        $this->assertSame(RunStatus::Failed, $parentRun->status);
        $this->assertSame('timed_out', $parentRun->closed_reason);

        // The request_cancel policy should have cancelled the child.
        $childStub->refresh();
        $this->assertSame('cancelled', $childStub->status());

        // ParentClosePolicyApplied history event should be recorded on the parent run.
        $appliedEvent = $parentRun->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->first();

        $this->assertNotNull($appliedEvent, 'Parent-close policy should be enforced when parent times out.');
        $this->assertSame('request_cancel', $appliedEvent->payload['policy']);
        $this->assertSame($childInstanceId, $appliedEvent->payload['child_instance_id']);
    }

    public function testTerminatePolicyTerminatesChildWhenParentTimesOut(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'terminate-on-timeout');
        $workflow->start('terminate');

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());

        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'terminate-on-timeout')
            ->where('link_type', 'child_workflow')
            ->first();

        $this->assertNotNull($link);
        $childInstanceId = $link->child_workflow_instance_id;
        $childStub = WorkflowStub::load($childInstanceId);
        $this->assertContains($childStub->status(), ['waiting', 'running', 'pending']);

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'terminate-on-timeout')
            ->firstOrFail();

        $parentRun->forceFill([
            'run_timeout_seconds' => 60,
            'run_deadline_at' => Carbon::now()->subSeconds(10),
        ])->save();

        $deadlineTask = WorkflowTask::query()->create([
            'workflow_run_id' => $parentRun->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now(),
            'payload' => [
                'deadline_expired' => true,
            ],
            'leased_at' => now(),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        app(WorkflowExecutor::class)->run($parentRun->fresh(), $deadlineTask->fresh());

        $parentRun->refresh();
        $this->assertSame(RunStatus::Failed, $parentRun->status);
        $this->assertSame('timed_out', $parentRun->closed_reason);

        // Terminate policy should have terminated the child.
        $childStub->refresh();
        $this->assertSame('terminated', $childStub->status());

        $appliedEvent = $parentRun->historyEvents()
            ->where('event_type', HistoryEventType::ParentClosePolicyApplied->value)
            ->first();

        $this->assertNotNull($appliedEvent, 'Parent-close policy should be enforced when parent times out.');
        $this->assertSame('terminate', $appliedEvent->payload['policy']);
    }

    public function testRunLineageViewSurfacesAppliedRequestCancelOutcomeOnChildEntry(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'lineage-applied-cancel');
        $workflow->start('request_cancel');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $link = WorkflowLink::query()
            ->where('parent_workflow_instance_id', 'lineage-applied-cancel')
            ->where('link_type', 'child_workflow')
            ->sole();

        $childInstanceId = $link->child_workflow_instance_id;
        $childRunId = $link->child_workflow_run_id;

        $result = $workflow->attemptTerminate('terminate parent for lineage assertion');
        $this->assertTrue($result->accepted());

        $parentRun = WorkflowRun::query()
            ->with(['historyEvents', 'childLinks.childRun.summary', 'instance.runs.summary'])
            ->where('workflow_instance_id', 'lineage-applied-cancel')
            ->sole();

        $children = RunLineageView::continuedWorkflowsForRun($parentRun);

        $childEntry = collect($children)
            ->first(static fn (array $entry): bool => ($entry['child_workflow_run_id'] ?? null) === $childRunId);

        $this->assertNotNull($childEntry, 'Child lineage entry should be present.');
        $this->assertSame($childInstanceId, $childEntry['child_workflow_id']);
        $this->assertSame('request_cancel', $childEntry['parent_close_policy']);
        $this->assertSame('applied', $childEntry['parent_close_policy_outcome']);
        $this->assertNotNull($childEntry['parent_close_policy_reason']);
        $this->assertStringContainsString(
            'parent-close policy: request_cancel',
            $childEntry['parent_close_policy_reason']
        );
        $this->assertNull($childEntry['parent_close_policy_error']);
    }

    public function testRunLineageViewExposesAbandonPolicyForOpenChildOnRunningParent(): void
    {
        $workflow = WorkflowStub::make(TestParentWithClosePolicyWorkflow::class, 'lineage-abandon-open');
        $workflow->start('abandon');

        $this->drainReadyTasks();
        $this->assertSame('waiting', $workflow->refresh()->status());

        $parentRun = WorkflowRun::query()
            ->with(['historyEvents', 'childLinks.childRun.summary', 'instance.runs.summary'])
            ->where('workflow_instance_id', 'lineage-abandon-open')
            ->sole();

        $children = RunLineageView::continuedWorkflowsForRun($parentRun);

        $this->assertCount(1, $children);
        $this->assertSame('abandon', $children[0]['parent_close_policy']);
        $this->assertNull($children[0]['parent_close_policy_outcome']);
        $this->assertNull($children[0]['parent_close_policy_reason']);
        $this->assertNull($children[0]['parent_close_policy_error']);
    }

    public function testRunLineageViewLeavesPolicyFieldsNullForContinueAsNewLineage(): void
    {
        $workflow = WorkflowStub::make(
            TestParentWithClosePolicyContinuingChildWorkflow::class,
            'lineage-continue-policy-null',
        );
        $workflow->start('request_cancel', 1);

        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed());

        $parentRun = WorkflowRun::query()
            ->with(['historyEvents', 'childLinks.childRun.summary', 'instance.runs.summary'])
            ->where('workflow_instance_id', 'lineage-continue-policy-null')
            ->sole();

        $parents = RunLineageView::parentsForRun($parentRun);

        foreach ($parents as $entry) {
            $this->assertArrayHasKey('parent_close_policy', $entry);
            $this->assertNull($entry['parent_close_policy']);
            $this->assertNull($entry['parent_close_policy_outcome']);
        }
    }

    private function drainReadyTasks(int $maxIterations = 0): void
    {
        $deadline = microtime(true) + 10;
        $iterations = 0;

        while (microtime(true) < $deadline) {
            if ($maxIterations > 0 && $iterations >= $maxIterations) {
                return;
            }

            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
            $iterations++;
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
