<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHistoryBudgetWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\WorkflowExecutor;
use Workflow\V2\WorkflowStub;

final class V2WorkflowTimeoutTest extends TestCase
{
    public function testStartWithExecutionTimeoutSnapsOnInstanceAndRun(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-exec-1');
        $workflow->start('Taylor', StartOptions::rejectDuplicate()->withExecutionTimeout(3600));

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-exec-1');
        $this->assertSame(3600, (int) $instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNull($run->run_timeout_seconds);
        $this->assertNull($run->run_deadline_at);
    }

    public function testStartWithRunTimeoutSnapsOnRun(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-run-1');
        $workflow->start('Taylor', StartOptions::rejectDuplicate()->withRunTimeout(1800));

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-run-1');
        $this->assertNull($instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertSame(1800, (int) $run->run_timeout_seconds);
        $this->assertNotNull($run->run_deadline_at);
        $this->assertNull($run->execution_deadline_at);
    }

    public function testStartWithBothTimeoutsSnapsBoth(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-both-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(7200)
                ->withRunTimeout(3600),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-both-1');
        $this->assertSame(7200, (int) $instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertSame(3600, (int) $run->run_timeout_seconds);
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNotNull($run->run_deadline_at);
    }

    public function testTimeoutFieldsAppearInWorkflowStartedHistory(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-history-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(3600)
                ->withRunTimeout(1800),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $startedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->firstOrFail();

        $payload = $startedEvent->payload;

        $this->assertSame(3600, $payload['execution_timeout_seconds']);
        $this->assertSame(1800, $payload['run_timeout_seconds']);
        $this->assertArrayHasKey('execution_deadline_at', $payload);
        $this->assertArrayHasKey('run_deadline_at', $payload);
    }

    public function testNoTimeoutFieldsWhenNotConfigured(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-none-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->completed());

        $instance = WorkflowInstance::query()->findOrFail('timeout-none-1');
        $this->assertNull($instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $workflow->runId())->firstOrFail();
        $this->assertNull($run->run_timeout_seconds);
        $this->assertNull($run->execution_deadline_at);
        $this->assertNull($run->run_deadline_at);
    }

    public function testStartOptionsValidatesTimeoutMinimum(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least 1 second');

        StartOptions::rejectDuplicate()->withExecutionTimeout(0);
    }

    public function testStartOptionsValidatesRunTimeoutMinimum(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('at least 1 second');

        StartOptions::rejectDuplicate()->withRunTimeout(-1);
    }

    public function testTimeoutBuildersChainingPreservesValues(): void
    {
        $options = StartOptions::rejectDuplicate()
            ->withBusinessKey('bk-1')
            ->withExecutionTimeout(3600)
            ->withRunTimeout(1800)
            ->withLabels([
                'env' => 'prod',
            ])
            ->withSearchAttributes([
                'status' => 'active',
            ]);

        $this->assertSame(3600, $options->executionTimeoutSeconds);
        $this->assertSame(1800, $options->runTimeoutSeconds);
        $this->assertSame('bk-1', $options->businessKey);
        $this->assertSame([
            'env' => 'prod',
        ], $options->labels);
        $this->assertSame([
            'status' => 'active',
        ], $options->searchAttributes);
    }

    public function testContinueAsNewCarriesForwardTimeouts(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestHistoryBudgetWorkflow::class, 'timeout-can-1');

        config()
            ->set('workflows.v2.history_budget.continue_as_new_event_threshold', 1);

        $workflow->start(
            0,
            1,
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(7200)
                ->withRunTimeout(3600),
        );

        $workflow->refresh();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'timeout-can-1')
            ->orderBy('run_number')
            ->get();

        $this->assertGreaterThanOrEqual(2, $runs->count());

        $firstRun = $runs->first();
        $lastRun = $runs->last();

        $this->assertSame(3600, (int) $firstRun->run_timeout_seconds);
        $this->assertNotNull($firstRun->execution_deadline_at);
        $this->assertNotNull($firstRun->run_deadline_at);

        $this->assertSame(3600, (int) $lastRun->run_timeout_seconds);
        $this->assertNotNull($lastRun->execution_deadline_at);
        $this->assertNotNull($lastRun->run_deadline_at);

        // Execution deadline should be identical across runs (same instance-level timeout)
        $this->assertEquals(
            $firstRun->execution_deadline_at->toIso8601String(),
            $lastRun->execution_deadline_at->toIso8601String(),
        );

        // Run deadline should be different (reset for each new run)
        $this->assertNotEquals(
            $firstRun->run_deadline_at->toIso8601String(),
            $lastRun->run_deadline_at->toIso8601String(),
        );
    }

    public function testControlPlaneDescribeIncludesTimeoutFields(): void
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-describe-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()
                ->withExecutionTimeout(3600)
                ->withRunTimeout(1800),
        );

        /** @var \Workflow\V2\Contracts\WorkflowControlPlane $controlPlane */
        $controlPlane = app(\Workflow\V2\Contracts\WorkflowControlPlane::class);
        $description = $controlPlane->describe('timeout-describe-1');

        $this->assertTrue($description['found']);
        $this->assertSame(3600, $description['execution_timeout_seconds']);
        $this->assertSame(1800, $description['run']['run_timeout_seconds']);
        $this->assertNotNull($description['run']['execution_deadline_at']);
        $this->assertNotNull($description['run']['run_deadline_at']);
    }

    public function testControlPlaneStartWithTimeouts(): void
    {
        WorkflowStub::fake();

        /** @var \Workflow\V2\Contracts\WorkflowControlPlane $controlPlane */
        $controlPlane = app(\Workflow\V2\Contracts\WorkflowControlPlane::class);

        $result = $controlPlane->start('tests.greeting-workflow', 'timeout-cp-1', [
            'execution_timeout_seconds' => 7200,
            'run_timeout_seconds' => 3600,
        ]);

        $this->assertTrue($result['started']);

        $instance = WorkflowInstance::query()->findOrFail('timeout-cp-1');
        $this->assertSame(7200, (int) $instance->execution_timeout_seconds);

        $run = WorkflowRun::query()->where('id', $result['workflow_run_id'])->firstOrFail();
        $this->assertSame(3600, (int) $run->run_timeout_seconds);
        $this->assertNotNull($run->execution_deadline_at);
        $this->assertNotNull($run->run_deadline_at);
    }

    public function testRunDeadlineEnforcedOnWorkflowTaskExecution(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $instance = WorkflowInstance::query()->create([
            'id' => 'timeout-enforce-run-1',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        $runDeadlineAt = $startedAt->copy()->addSeconds(60);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'run_timeout_seconds' => 60,
            'run_deadline_at' => $runDeadlineAt,
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $startedAt,
            'payload' => [],
            'leased_at' => $startedAt,
            'lease_expires_at' => $startedAt->copy()->addMinutes(5),
        ]);

        // Advance past run deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(120));

        $nextTask = app(WorkflowExecutor::class)->run($run->fresh(), $task->fresh());

        $this->assertNull($nextTask);

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);
        $this->assertNotNull($run->closed_at);

        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);

        $failure = WorkflowFailure::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $this->assertSame(FailureCategory::Timeout->value, $failure->failure_category->value);
        $this->assertSame('timeout', $failure->propagation_kind);
        $this->assertStringContainsString('run deadline expired', $failure->message);

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimedOut->value)
            ->firstOrFail();

        $this->assertSame('run_timeout', $timedOutEvent->payload['timeout_kind']);
        $this->assertSame('timeout', $timedOutEvent->payload['failure_category']);

        Carbon::setTestNow();
    }

    public function testExecutionDeadlineEnforcedOnWorkflowTaskExecution(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $instance = WorkflowInstance::query()->create([
            'id' => 'timeout-enforce-exec-1',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'execution_timeout_seconds' => 300,
            'run_count' => 1,
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        $executionDeadlineAt = $startedAt->copy()->addSeconds(300);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'execution_deadline_at' => $executionDeadlineAt,
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $startedAt,
            'payload' => [],
            'leased_at' => $startedAt,
            'lease_expires_at' => $startedAt->copy()->addMinutes(5),
        ]);

        // Advance past execution deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(600));

        $nextTask = app(WorkflowExecutor::class)->run($run->fresh(), $task->fresh());

        $this->assertNull($nextTask);

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);

        $failure = WorkflowFailure::query()->where('workflow_run_id', $run->id)->firstOrFail();
        $this->assertSame(FailureCategory::Timeout->value, $failure->failure_category->value);
        $this->assertStringContainsString('execution deadline expired', $failure->message);

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimedOut->value)
            ->firstOrFail();

        $this->assertSame('execution_timeout', $timedOutEvent->payload['timeout_kind']);

        Carbon::setTestNow();
    }

    public function testTimeoutCancelsOpenActivitiesAndTimers(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $instance = WorkflowInstance::query()->create([
            'id' => 'timeout-cancel-1',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'run_timeout_seconds' => 30,
            'run_deadline_at' => $startedAt->copy()->addSeconds(30),
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        // Create an open activity execution.
        $activityExecution = \Workflow\V2\Models\ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'sequence' => 1,
            'status' => \Workflow\V2\Enums\ActivityStatus::Running->value,
            'attempt_count' => 1,
            'started_at' => $startedAt,
        ]);

        // Create an open activity task.
        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $startedAt,
            'payload' => ['activity_execution_id' => $activityExecution->id],
            'leased_at' => $startedAt,
            'lease_expires_at' => $startedAt->copy()->addMinutes(5),
        ]);

        // Create an open timer.
        $timer = \Workflow\V2\Models\WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => \Workflow\V2\Enums\TimerStatus::Pending->value,
            'fire_at' => $startedAt->copy()->addMinutes(10),
            'delay_seconds' => 600,
        ]);

        // Create the workflow task that the executor claims.
        $workflowTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $startedAt,
            'payload' => [],
            'leased_at' => $startedAt,
            'lease_expires_at' => $startedAt->copy()->addMinutes(5),
        ]);

        // Advance past deadline.
        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        app(WorkflowExecutor::class)->run($run->fresh(), $workflowTask->fresh());

        // Activity should be cancelled.
        $activityExecution->refresh();
        $this->assertSame(\Workflow\V2\Enums\ActivityStatus::Cancelled->value, $activityExecution->status->value);

        // Activity task should be cancelled.
        $activityTask->refresh();
        $this->assertSame(TaskStatus::Cancelled->value, $activityTask->status->value);

        // Timer should be cancelled.
        $timer->refresh();
        $this->assertSame(\Workflow\V2\Enums\TimerStatus::Cancelled->value, $timer->status->value);

        // ActivityCancelled history event should exist.
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::ActivityCancelled->value,
        ]);

        // TimerCancelled history event should exist.
        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::TimerCancelled->value,
        ]);

        Carbon::setTestNow();
    }

    public function testTimeoutFailureCategoryAppearInFailureSnapshots(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        $instance = WorkflowInstance::query()->create([
            'id' => 'timeout-snapshots-1',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => null,
            'queue' => null,
            'run_timeout_seconds' => 30,
            'run_deadline_at' => $startedAt->copy()->addSeconds(30),
            'started_at' => $startedAt,
            'last_progress_at' => $startedAt,
        ]);

        $instance->forceFill(['current_run_id' => $run->id])->save();

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $startedAt,
            'payload' => [],
            'leased_at' => $startedAt,
            'lease_expires_at' => $startedAt->copy()->addMinutes(5),
        ]);

        Carbon::setTestNow($startedAt->copy()->addSeconds(60));

        app(WorkflowExecutor::class)->run($run->fresh(), $task->fresh());

        $run->refresh();
        $run->load(['historyEvents', 'failures']);

        $snapshots = FailureSnapshots::forRun($run);

        $this->assertNotEmpty($snapshots);
        $timeoutSnapshot = $snapshots[0];
        $this->assertSame('timeout', $timeoutSnapshot['failure_category']);
        $this->assertSame('timeout', $timeoutSnapshot['propagation_kind']);
        $this->assertSame('workflow_run', $timeoutSnapshot['source_kind']);

        Carbon::setTestNow();
    }

    public function testNoDeadlineEnforcedWhenDeadlineNotExpired(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'timeout-no-enforce-1');
        $workflow->start(
            'Taylor',
            StartOptions::rejectDuplicate()->withRunTimeout(3600),
        );

        $this->assertTrue($workflow->refresh()->completed());

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'timeout-no-enforce-1')
            ->firstOrFail();

        $this->assertSame('completed', $run->closed_reason);
        $this->assertDatabaseMissing('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'event_type' => HistoryEventType::WorkflowTimedOut->value,
        ]);

        Carbon::setTestNow();
    }
}
