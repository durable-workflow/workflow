<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowTaskLeaseLostException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\WorkerTimeoutAdvisory;
use Workflow\V2\Support\WorkflowTaskLeaseLoss;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * A workflow task that keeps losing its lease, as when the queue worker kills
 * it at its job timeout, is counted, diagnosed and surfaced instead of being
 * retried forever without a signal (#1231).
 */
final class V2WorkflowTaskLostLeaseTest extends TestCase
{
    public function testRepeatedLostLeasesNeedAttentionWithoutChangingTheRun(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lost-lease-attention');
        $workflow->start('Taylor');
        $runId = $workflow->runId();
        $historyBefore = $this->historyTypes($runId);

        $this->loseLease($runId);
        $this->loseLease($runId);

        $task = $this->workflowTask($runId);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(2, $task->lost_lease_count);
        $this->assertNotNull($task->last_lease_lost_at);
        $this->assertNotSame(WorkflowTaskLeaseLoss::CODE, $this->summary($runId)->repair_blocked_reason);
        $this->assertFalse((bool) $this->summary($runId)->repair_attention);

        $this->loseLease($runId);

        $summary = $this->summary($runId);
        $this->assertSame(WorkflowTaskLeaseLoss::CODE, $summary->repair_blocked_reason);
        $this->assertTrue((bool) $summary->repair_attention);

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));
        $this->assertSame(WorkflowTaskLeaseLoss::CODE, $detail['repair_blocked_reason']);
        $this->assertTrue($detail['repair_attention']);
        $this->assertSame('Task Timing Out', $detail['repair_blocked']['label']);

        $metrics = OperatorMetrics::snapshot();
        $this->assertSame(1, $metrics['runs']['workflow_task_lease_lost']);
        $this->assertSame(1, $metrics['tasks']['lease_lost_repeatedly']);
        $this->assertSame(3, $metrics['tasks']['max_lost_lease_count']);

        // Counting and reporting lost leases writes no history.
        $this->assertSame($historyBefore, $this->historyTypes($runId));

        // The next claim reports the typed diagnostic and still runs the task.
        Log::spy();
        $this->app->call([new RunWorkflowTask($this->workflowTask($runId)->id), 'handle']);

        Log::shouldHaveReceived('warning')
            ->withArgs(
                static fn (string $message, array $context): bool => $context['code'] === WorkflowTaskLeaseLoss::CODE
                && $context['workflow_run_id'] === $runId
                && $context['lost_leases'] === 3
                && $context['needs_attention'] === true
            )
            ->once();
        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::ActivityScheduled->value,
        ], $this->historyTypes($runId));

        // The completed task's replacement starts with no lost leases.
        $summary = $this->summary($runId);
        $this->assertNotSame(WorkflowTaskLeaseLoss::CODE, $summary->repair_blocked_reason);
        $this->assertFalse((bool) $summary->repair_attention);
    }

    public function testFailingTheRunAfterLostLeasesIsOptIn(): void
    {
        Queue::fake();
        config([
            'workflows.v2.workflow_task_lost_leases.fail_run' => true,
        ]);
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lost-lease-fail-run');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->loseLease($runId);
        $this->loseLease($runId);
        $this->loseLease($runId);
        $this->app->call([new RunWorkflowTask($this->workflowTask($runId)->id), 'handle']);

        $this->assertTrue($workflow->refresh()->failed());
        $failure = WorkflowFailure::query()
            ->where('workflow_run_id', $runId)
            ->sole();
        $this->assertSame(WorkflowTaskLeaseLostException::class, $failure->exception_class);
        $this->assertSame(
            FailureCategory::TaskFailure->value,
            $failure->failure_category?->value ?? $failure->failure_category,
        );
        $this->assertSame([
            HistoryEventType::StartAccepted->value,
            HistoryEventType::WorkflowStarted->value,
            HistoryEventType::WorkflowFailed->value,
        ], $this->historyTypes($runId));
    }

    public function testFailingTheRunWaitsForTheAttentionThreshold(): void
    {
        Queue::fake();
        config([
            'workflows.v2.workflow_task_lost_leases.fail_run' => true,
            'workflows.v2.workflow_task_lost_leases.attention_threshold' => 3,
        ]);
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lost-lease-below-threshold');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->loseLease($runId);
        $this->loseLease($runId);
        $this->app->call([new RunWorkflowTask($this->workflowTask($runId)->id), 'handle']);

        $this->assertFalse($workflow->refresh()->failed());
        $this->assertContains(HistoryEventType::ActivityScheduled->value, $this->historyTypes($runId));
    }

    public function testWorkerStartWarnsWhenTheJobTimeoutIsBelowTheTaskLease(): void
    {
        WorkerTimeoutAdvisory::reset();
        Log::spy();
        $options = new WorkerOptions(timeout: 60);

        Event::dispatch(new WorkerStarting('redis', 'default', $options));
        Event::dispatch(new WorkerStarting('redis', 'default', $options));

        if (extension_loaded('pcntl')) {
            Log::shouldHaveReceived('warning')
                ->withArgs(static fn (string $message, array $context): bool =>
                    $context['code'] === 'worker_timeout_below_workflow_task_lease'
                    && $context['worker_timeout_seconds'] === 60
                    && str_contains($message, '--timeout=0'))
                ->once();
        } else {
            Log::shouldNotHaveReceived('warning');
        }

        $this->assertNull(WorkerTimeoutAdvisory::message(0, true, 300));
        $this->assertNull(WorkerTimeoutAdvisory::message(300, true, 300));
        $this->assertNull(WorkerTimeoutAdvisory::message(60, false, 300));
        $this->assertNotNull(WorkerTimeoutAdvisory::message(60, true, 300));
        WorkerTimeoutAdvisory::reset();
    }

    protected function startQueueWorkersAutomatically(): bool
    {
        return false;
    }

    /**
     * Claim the run's workflow task as a worker would, let its lease expire
     * without completing, and run task repair.
     */
    private function loseLease(string $runId): void
    {
        $task = $this->workflowTask($runId);
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'leased_at' => Carbon::now()->subMinutes(10),
            'lease_owner' => $task->id,
            'lease_expires_at' => Carbon::now()->subMinute(),
            'attempt_count' => $task->attempt_count + 1,
        ])->save();

        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::runPass(connection: 'redis', queue: 'default', respectThrottle: false);

        $this->assertSame(TaskStatus::Ready, $this->workflowTask($runId)->status);
    }

    private function workflowTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->sole();
    }

    private function summary(string $runId): WorkflowRunSummary
    {
        return WorkflowRunSummary::query()->findOrFail($runId);
    }

    /**
     * @return list<string>
     */
    private function historyTypes(string $runId): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
            ->all();
    }
}
