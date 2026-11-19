<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
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
use Workflow\V2\Support\WorkflowTaskJobTimeout;
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
        $this->assertNull($this->summary($runId)->task_attention);
        $summaryBelow = $this->consumerFields($this->summary($runId)->getAttributes());
        $detailBelow = $this->repairFields(RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId)));

        $this->loseLease($runId);

        // At the threshold only the additive task_attention field changes:
        // repair-blocked reasons, repair attention and can_repair do not.
        $summary = $this->summary($runId);
        // MySQL's JSON column may reorder keys.
        $this->assertEquals([
            'reason' => WorkflowTaskLeaseLoss::CODE,
            'consecutive' => 3,
            'threshold' => 3,
            'workflow_task_id' => $task->id,
        ], array_diff_key($summary->task_attention, [
            'last_lease_lost_at' => true,
        ]));
        $this->assertSame($summaryBelow, $this->consumerFields($summary->getAttributes()));

        $detail = RunDetailView::forRun(WorkflowRun::query()->findOrFail($runId));
        $this->assertSame($summary->task_attention, $detail['task_attention']);
        $this->assertSame($detailBelow, $this->repairFields($detail));

        $metrics = OperatorMetrics::snapshot();
        $this->assertSame(1, $metrics['runs']['task_attention']);
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
        $this->assertNull($this->summary($runId)->task_attention);
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

    public function testWorkflowTaskJobsCarryTheirOwnTimeoutBelowLeaseAndRetryAfter(): void
    {
        config([
            'workflows.v2.workflow_task_lease_seconds' => 300,
            'queue.connections.redis.retry_after' => 600,
        ]);
        $this->assertSame(270, WorkflowTaskJobTimeout::seconds('redis'));

        // Never at or past retry_after, so the queue never hands a running
        // job to another worker.
        config([
            'queue.connections.redis.retry_after' => 90,
        ]);
        $this->assertSame(80, WorkflowTaskJobTimeout::seconds('redis'));

        config([
            'queue.connections.redis.retry_after' => 600,
            'workflows.v2.workflow_task_job_timeout_seconds' => 120,
        ]);
        $this->assertSame(120, WorkflowTaskJobTimeout::seconds('redis'));
        config([
            'workflows.v2.workflow_task_job_timeout_seconds' => 900,
        ]);
        $this->assertSame(270, WorkflowTaskJobTimeout::seconds('redis'));

        // The dispatched job carries it; Laravel's worker uses it in place of
        // the worker's --timeout.
        config([
            'workflows.v2.workflow_task_job_timeout_seconds' => null,
        ]);
        Queue::fake();
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lost-lease-job-timeout');
        $workflow->start('Taylor');
        Queue::assertPushed(RunWorkflowTask::class, static fn (RunWorkflowTask $job): bool => $job->timeout === 270);
    }

    public function testWorkerStartWarnsOnlyWorkflowQueueWorkersCappedByRetryAfter(): void
    {
        config([
            'queue.default' => 'redis',
            'workflows.v2.connection' => null,
            'workflows.v2.queue' => null,
            'queue.connections.redis.queue' => 'default',
            'queue.connections.redis.retry_after' => 90,
            'workflows.v2.workflow_task_lease_seconds' => 300,
        ]);
        WorkerTimeoutAdvisory::reset();
        Log::spy();
        $options = new WorkerOptions(timeout: 60);

        // A worker that serves only other queues is never warned.
        Event::dispatch(new WorkerStarting('redis', 'activities', $options));
        Log::shouldNotHaveReceived('warning');

        Event::dispatch(new WorkerStarting('redis', 'high,default', $options));
        Event::dispatch(new WorkerStarting('redis', 'default', $options));

        if (extension_loaded('pcntl')) {
            Log::shouldHaveReceived('warning')
                ->withArgs(static fn (string $message, array $context): bool =>
                    $context['code'] === 'workflow_task_timeout_capped_by_retry_after'
                    && $context['retry_after_seconds'] === 90
                    && $context['workflow_task_timeout_seconds'] === 80
                    && str_contains($message, 'retry_after above 280 s'))
                ->once();
        } else {
            Log::shouldNotHaveReceived('warning');
        }

        $this->assertNull(WorkerTimeoutAdvisory::message(true, 270, 270, 600, 'redis'));
        $this->assertNull(WorkerTimeoutAdvisory::message(false, 80, 270, 90, 'redis'));
        $this->assertNotNull(WorkerTimeoutAdvisory::message(true, 80, 270, 90, 'redis'));
        WorkerTimeoutAdvisory::reset();
    }

    public function testFailingRunsRequiresAThresholdOfAtLeastTwo(): void
    {
        config([
            'workflows.v2.workflow_task_lost_leases.fail_run' => true,
            'workflows.v2.workflow_task_lost_leases.attention_threshold' => 1,
        ]);

        try {
            WorkflowTaskLeaseLoss::validateConfiguration();
            $this->fail('A failing threshold of one must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at least 2', $exception->getMessage());
        }

        config([
            'workflows.v2.workflow_task_lost_leases.attention_threshold' => 2,
        ]);
        WorkflowTaskLeaseLoss::validateConfiguration();
        config([
            'workflows.v2.workflow_task_lost_leases.fail_run' => false,
            'workflows.v2.workflow_task_lost_leases.attention_threshold' => 1,
        ]);
        WorkflowTaskLeaseLoss::validateConfiguration();
        $this->addToAssertionCount(2);
    }

    public function testASchemaRollbackDegradesToUntracked(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'lost-lease-schema-rollback');
        $workflow->start('Taylor');
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertTrue(WorkflowTaskLeaseLoss::tracked());

        // The process still believes the columns exist.
        Schema::table('workflow_tasks', static function (Blueprint $table): void {
            $table->dropColumn(['last_lease_lost_at', 'lost_lease_count']);
        });

        try {
            DB::transaction(function () use ($run): void {
                $this->assertNull(WorkflowTaskLeaseLoss::taskAttention($run));
                // The caller's transaction is still usable.
                $this->assertSame(1, WorkflowRun::query()->whereKey($run->id)->count());
            });
            $this->assertFalse(WorkflowTaskLeaseLoss::tracked());
            $this->assertSame(0, OperatorMetrics::snapshot()['tasks']['lease_lost_repeatedly']);
        } finally {
            Schema::table('workflow_tasks', static function (Blueprint $table): void {
                $table->unsignedInteger('lost_lease_count')
                    ->default(0);
                $table->timestamp('last_lease_lost_at', 6)
                    ->nullable();
            });
        }
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

    /**
     * The fields an existing consumer reads, without the additive field and
     * the timestamps a repair pass refreshes.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function consumerFields(array $fields): array
    {
        return array_diff_key($fields, array_flip([
            'task_attention',
            'updated_at',
            'next_task_at',
            'next_task_lease_expires_at',
            'wait_started_at',
            'wait_deadline_at',
            'tasks',
            'timeline',
            'waits',
            'liveness_reason',
            'last_progress_at',
            'sort_timestamp',
            'sort_key',
        ]));
    }

    /**
     * The describe fields a repair decision reads.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function repairFields(array $detail): array
    {
        return array_intersect_key($detail, array_flip([
            'status',
            'wait_kind',
            'liveness_state',
            'can_repair',
            'repair_blocked_reason',
            'repair_attention',
            'repair_blocked',
            'task_problem',
            'can_cancel',
            'can_terminate',
            'can_signal',
            'can_update',
        ]));
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
