<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestParentContinuesWithOpenChildWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ChildCallStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowChildSupervision;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

/**
 * #868: a parent that continues as new while a child it started is still open
 * hands effective supervision of that child to the continued run. The chain's
 * final run enforces the child's original parent-close policy when it closes.
 */
final class V2ContinueAsNewOpenChildSupervisionTest extends TestCase
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

    public function testRequestCancelPolicyIsEnforcedWhenTheContinuedRunCompletes(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-cancel');
        $workflow->start('request_cancel');

        $this->drainReadyTasks();

        $runs = $this->runsFor('can-open-child-cancel');
        $this->assertCount(2, $runs);
        [$firstRun, $finalRun] = [$runs[0], $runs[1]];

        $this->assertSame('continued', $firstRun->closed_reason);
        $this->assertSame(RunStatus::Completed, $finalRun->status);
        $this->assertSame('completed', $finalRun->closed_reason);

        $link = $this->childLink($firstRun);
        $childStub = WorkflowStub::load($link->child_workflow_instance_id);
        $this->assertSame('cancelled', $childStub->refresh()->status());

        // Supervision moved to the continued run, keeping the original identities.
        $supervision = WorkflowChildSupervision::query()
            ->where('supervising_workflow_run_id', $finalRun->id)
            ->sole();
        $this->assertSame($link->id, $supervision->workflow_link_id);
        $this->assertSame($firstRun->id, $supervision->parent_workflow_run_id);
        $this->assertSame($firstRun->id, $supervision->transferred_from_workflow_run_id);
        $this->assertSame($link->sequence, $supervision->sequence);
        $this->assertSame($link->child_workflow_instance_id, $supervision->child_workflow_instance_id);
        $this->assertSame($link->child_workflow_run_id, $supervision->child_workflow_run_id);
        $this->assertSame('request_cancel', $supervision->parent_close_policy);

        // The original child link keeps its parent run, sequence and policy.
        $link->refresh();
        $this->assertSame($firstRun->id, $link->parent_workflow_run_id);
        $this->assertSame('request_cancel', $link->parent_close_policy);

        // The final run records the enforcement; the closed predecessor gets nothing.
        $applied = $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied);
        $this->assertCount(1, $applied);
        $this->assertSame('request_cancel', $applied[0]->payload['policy']);
        $this->assertSame($link->child_workflow_instance_id, $applied[0]->payload['child_instance_id']);
        $this->assertSame($link->child_workflow_run_id, $applied[0]->payload['child_run_id']);
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);

        /** @var WorkflowChildCall $childCall */
        $childCall = WorkflowChildCall::query()
            ->where('parent_workflow_run_id', $firstRun->id)
            ->where('sequence', $link->sequence)
            ->sole();
        $this->assertSame(ChildCallStatus::Cancelled, $childCall->status);

        $this->assertColdReplayIsDeterministic($firstRun, $finalRun);
    }

    public function testTerminatePolicyIsEnforcedWhenTheContinuedRunCompletes(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-terminate');
        $workflow->start('terminate');

        $this->drainReadyTasks();

        [$firstRun, $finalRun] = $this->runsFor('can-open-child-terminate');
        $link = $this->childLink($firstRun);

        $this->assertSame(RunStatus::Completed, $finalRun->status);
        $this->assertSame('terminated', WorkflowStub::load($link->child_workflow_instance_id)->status());

        $applied = $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied);
        $this->assertCount(1, $applied);
        $this->assertSame('terminate', $applied[0]->payload['policy']);
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);
        $this->assertColdReplayIsDeterministic($firstRun, $finalRun);
    }

    public function testCooperativeCancellationPolicyIsRequestedByTheFinalRun(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-cooperative');
        $workflow->start('request_cancellation');

        $this->drainReadyTasks();

        [$firstRun, $finalRun] = $this->runsFor('can-open-child-cooperative');
        $link = $this->childLink($firstRun);

        $this->assertSame(RunStatus::Completed, $finalRun->status);
        $this->assertCount(0, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyFailed));
        $this->assertCount(1, $this->policyEvents($finalRun, HistoryEventType::ParentCloseCancellationRequested));

        $applied = $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied);
        $this->assertCount(1, $applied);
        $this->assertSame('request_cancellation', $applied[0]->payload['policy']);
        $this->assertIsString($applied[0]->payload['request_id'] ?? null);
        $this->assertSame($finalRun->id, $applied[0]->payload['cancellation']['root_workflow_run_id'] ?? null);

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame($applied[0]->payload['request_id'], $childRun->cancellation_request_command_id);
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);
        $this->assertColdReplayIsDeterministic($firstRun, $finalRun);
    }

    public function testAbandonPolicyStaysAbandonAcrossContinueAsNew(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-abandon');
        $workflow->start('abandon');

        $this->drainReadyTasks();

        [$firstRun, $finalRun] = $this->runsFor('can-open-child-abandon');
        $link = $this->childLink($firstRun);

        $this->assertSame(RunStatus::Completed, $finalRun->status);
        $this->assertSame('waiting', WorkflowStub::load($link->child_workflow_instance_id)->status());

        $supervision = WorkflowChildSupervision::query()
            ->where('supervising_workflow_run_id', $finalRun->id)
            ->sole();
        $this->assertSame('abandon', $supervision->parent_close_policy);

        $this->assertCount(0, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied));
        $this->assertCount(0, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyFailed));
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);
    }

    public function testSupervisionFollowsEveryContinuationToTheFinalRun(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-two-hops');
        $workflow->start('request_cancel', 0, 2);

        $this->drainReadyTasks();

        $runs = $this->runsFor('can-open-child-two-hops');
        $this->assertCount(3, $runs);
        [$firstRun, $middleRun, $finalRun] = $runs;
        $link = $this->childLink($firstRun);

        $this->assertSame('continued', $middleRun->closed_reason);
        $this->assertSame(RunStatus::Completed, $finalRun->status);
        $this->assertSame('cancelled', WorkflowStub::load($link->child_workflow_instance_id)->status());

        $this->assertSame(
            [$middleRun->id, $finalRun->id],
            WorkflowChildSupervision::query()
                ->where('workflow_link_id', $link->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('supervising_workflow_run_id')
                ->all(),
        );
        /** @var WorkflowChildSupervision $finalSupervision */
        $finalSupervision = WorkflowChildSupervision::query()
            ->where('supervising_workflow_run_id', $finalRun->id)
            ->sole();
        $this->assertSame($middleRun->id, $finalSupervision->transferred_from_workflow_run_id);
        $this->assertSame($firstRun->id, $finalSupervision->parent_workflow_run_id);

        $this->assertCount(1, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied));
        $this->assertCount(0, $this->policyEvents($middleRun, HistoryEventType::ParentClosePolicyApplied));
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($middleRun);
        $this->assertColdReplayIsDeterministic($firstRun, $middleRun, $finalRun);
    }

    public function testChildCompletingUnderTheContinuedRunAppendsNothingAndIsNotReclosed(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-finishes');
        $workflow->start('terminate', 0, 1, true);

        $this->drainReadyTasks();

        [$firstRun, $finalRun] = $this->runsFor('can-open-child-finishes');
        $this->assertSame(RunStatus::Waiting, $finalRun->status);
        $link = $this->childLink($firstRun);

        $childStub = WorkflowStub::load($link->child_workflow_instance_id);
        $childStub->signal('finish');
        $this->drainReadyTasks();

        $this->assertSame('completed', $childStub->refresh()->status());
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $firstRun->id)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count());
        $this->assertSame(RunStatus::Waiting, $finalRun->refresh()->status);

        $workflow->signal('close');
        $this->drainReadyTasks();

        $this->assertSame(RunStatus::Completed, $finalRun->refresh()->status);
        $this->assertSame('completed', $childStub->refresh()->status());
        $this->assertCount(0, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied));
        $this->assertCount(0, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyFailed));
        $this->assertColdReplayIsDeterministic($firstRun, $finalRun);
    }

    public function testTerminatingTheContinuedRunEnforcesTheTransferredPolicy(): void
    {
        $workflow = WorkflowStub::make(TestParentContinuesWithOpenChildWorkflow::class, 'can-open-child-operator');
        $workflow->start('request_cancel', 0, 1, true);

        $this->drainReadyTasks();

        [$firstRun, $finalRun] = $this->runsFor('can-open-child-operator');
        $link = $this->childLink($firstRun);

        $result = $workflow->attemptTerminate('operator stop');
        $this->assertTrue($result->accepted());

        $this->assertSame(RunStatus::Terminated, $finalRun->refresh()->status);
        $this->assertSame('cancelled', WorkflowStub::load($link->child_workflow_instance_id)->status());
        $this->assertCount(1, $this->policyEvents($finalRun, HistoryEventType::ParentClosePolicyApplied));
        $this->assertPredecessorHistoryEndsWithContinuedAsNew($firstRun);
    }

    /**
     * @return list<WorkflowRun>
     */
    private function runsFor(string $instanceId): array
    {
        return array_values(WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->orderBy('run_number')
            ->get()
            ->all());
    }

    private function childLink(WorkflowRun $run): WorkflowLink
    {
        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->sole();

        return $link;
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private function policyEvents(WorkflowRun $run, HistoryEventType $type): array
    {
        return array_values(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', $type->value)
            ->orderBy('sequence')
            ->get()
            ->all());
    }

    private function assertPredecessorHistoryEndsWithContinuedAsNew(WorkflowRun $run): void
    {
        /** @var WorkflowHistoryEvent $last */
        $last = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderByDesc('sequence')
            ->firstOrFail();

        $this->assertSame(HistoryEventType::WorkflowContinuedAsNew, $last->event_type);
    }

    private function assertColdReplayIsDeterministic(WorkflowRun ...$runs): void
    {
        $replayer = new WorkflowReplayer();

        foreach ($runs as $index => $run) {
            $state = $replayer->replay(WorkflowRun::query()->findOrFail($run->id));

            if ($index < count($runs) - 1) {
                $this->assertInstanceOf(ContinueAsNewCall::class, $state->current);
            }
        }
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
