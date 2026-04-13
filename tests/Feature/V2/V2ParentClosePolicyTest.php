<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestLongRunningChildWorkflow;
use Tests\Fixtures\V2\TestParentWithClosePolicyWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ParentClosePolicy;
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
use Workflow\V2\WorkflowStub;

final class V2ParentClosePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
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
            ->first(fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::ChildWorkflowScheduled);

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
