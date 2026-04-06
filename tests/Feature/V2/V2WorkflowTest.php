<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\Fixtures\V2\TestConfiguredGreetingActivity;
use Tests\Fixtures\V2\TestConfiguredGreetingWorkflow;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\Fixtures\V2\TestParentFailingChildWorkflow;
use Tests\Fixtures\V2\TestParentWaitingOnChildWorkflow;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHandledFailureWorkflow;
use Tests\Fixtures\V2\TestSignalOrderingWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\StartOptions;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Support\RunSummarySortKey;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\WorkflowStub;

final class V2WorkflowTest extends TestCase
{
    public function testWorkflowCompletesWithDistinctInstanceAndRunIds(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $instanceId = $workflow->id();

        $this->assertSame('reserved', $workflow->status());
        $this->assertNull($workflow->runId());

        $result = $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);
        $this->assertNotSame($instanceId, $runId);
        $this->assertTrue($result->accepted());
        $this->assertSame('started_new', $result->outcome());
        $this->assertTrue($result->startedNew());
        $this->assertSame($instanceId, $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => $instanceId,
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $startAccepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'StartAccepted')
            ->first();

        $this->assertNotNull($startAccepted);
        $this->assertSame($result->commandId(), $startAccepted->workflow_command_id);

        $this->assertDatabaseHas('workflow_run_summaries', [
            'id' => $runId,
            'workflow_instance_id' => $instanceId,
            'status' => 'completed',
            'status_bucket' => 'completed',
            'engine_source' => 'v2',
        ]);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
            'command_type' => 'start',
            'source' => 'php',
            'status' => 'accepted',
            'outcome' => 'started_new',
        ]);
    }

    public function testPhpApiCommandsRecordDurableCommandContext(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'command-context-php');
        $result = $workflow->attemptStart('Taylor');

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertSame('php', $command->source);
        $this->assertSame('PHP API', $command->callerLabel());
        $this->assertSame('not_applicable', $command->authStatus());
        $this->assertSame('none', $command->authMethod());
        $this->assertSame('php', $command->commandContext()['caller']['type'] ?? null);
    }

    public function testAttemptStartReturnsRejectedResultForDuplicateStart(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $accepted = $workflow->attemptStart('Taylor');

        $this->assertTrue($accepted->accepted());
        $this->assertSame('started_new', $accepted->outcome());

        $rejected = $workflow->attemptStart('Jordan');

        $this->assertTrue($rejected->rejected());
        $this->assertTrue($rejected->rejectedDuplicate());
        $this->assertSame('rejected_duplicate', $rejected->outcome());
        $this->assertSame('instance_already_started', $rejected->rejectionReason());
        $this->assertSame($workflow->id(), $rejected->instanceId());
        $this->assertSame($workflow->runId(), $rejected->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $rejected->commandId(),
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'start',
            'status' => 'rejected',
            'outcome' => 'rejected_duplicate',
            'rejection_reason' => 'instance_already_started',
        ]);

        $this->assertSame(['StartAccepted', 'WorkflowStarted', 'StartRejected'], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testAttemptStartCanReturnExistingActiveRunWhenRequested(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'order-123');

        $accepted = $workflow->attemptStart('Taylor');

        $this->assertTrue($accepted->accepted());
        $this->assertSame('started_new', $accepted->outcome());

        $reused = $workflow->attemptStart('Jordan', StartOptions::returnExistingActive());

        $this->assertTrue($reused->accepted());
        $this->assertTrue($reused->returnedExistingActive());
        $this->assertSame('returned_existing_active', $reused->outcome());
        $this->assertNull($reused->rejectionReason());
        $this->assertSame('order-123', $reused->instanceId());
        $this->assertSame($accepted->runId(), $reused->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $reused->commandId(),
            'workflow_instance_id' => 'order-123',
            'workflow_run_id' => $accepted->runId(),
            'command_type' => 'start',
            'status' => 'accepted',
            'outcome' => 'returned_existing_active',
        ]);

        $this->assertSame(['StartAccepted', 'WorkflowStarted', 'StartAccepted'], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $accepted->runId())
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testConfiguredTypeMapPersistsWorkflowAliasOnStartedRuns(): void
    {
        $this->configureGreetingTypeMaps();

        $workflow = WorkflowStub::make(TestConfiguredGreetingWorkflow::class);
        $result = $workflow->start('Taylor');

        $runId = $result->runId();

        $this->assertNotNull($runId);
        $this->assertDatabaseHas('workflow_runs', [
            'id' => $runId,
            'workflow_type' => 'config-greeting-workflow',
        ]);
    }

    public function testRunSummaryProjectsStableSortContract(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'sort-contract');
        $result = $workflow->start('Taylor');
        $runId = $result->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertNotNull($summary->sort_timestamp);
        $this->assertSame($run->started_at?->toJSON(), $summary->sort_timestamp?->toJSON());
        $this->assertSame(
            RunSummarySortKey::key($run->started_at, $run->created_at, $run->updated_at, $runId),
            $summary->sort_key,
        );
    }

    public function testWorkflowCanContinueAsNewAcrossRuns(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'continue-instance');
        $started = $workflow->start(0, 2);
        $firstRunId = $started->runId();

        $this->assertNotNull($firstRunId);

        $this->drainReadyTasks();
        $workflow->refresh();

        $finalRunId = $workflow->runId();

        $this->assertNotNull($finalRunId);
        $this->assertTrue($workflow->completed());
        $this->assertNotSame($firstRunId, $finalRunId);
        $this->assertSame('continue-instance', $workflow->id());
        $this->assertSame([
            'count' => 2,
            'workflow_id' => 'continue-instance',
            'run_id' => $finalRunId,
        ], $workflow->output());

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'continue-instance')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(3, $runs);
        $this->assertSame([1, 2, 3], $runs->pluck('run_number')->all());
        $this->assertSame(['completed', 'completed', 'completed'], $runs->pluck('status')->map(
            static fn (RunStatus $status): string => $status->value
        )->all());
        $this->assertSame(['continued', 'continued', 'completed'], $runs->pluck('closed_reason')->all());

        $this->assertDatabaseHas('workflow_instances', [
            'id' => 'continue-instance',
            'current_run_id' => $finalRunId,
            'run_count' => 3,
        ]);

        $this->assertSame(2, WorkflowLink::query()->count());
        $this->assertDatabaseHas('workflow_links', [
            'link_type' => 'continue_as_new',
            'parent_workflow_instance_id' => 'continue-instance',
            'parent_workflow_run_id' => $runs[0]->id,
            'child_workflow_instance_id' => 'continue-instance',
            'child_workflow_run_id' => $runs[1]->id,
            'is_primary_parent' => true,
        ]);
        $this->assertDatabaseHas('workflow_links', [
            'link_type' => 'continue_as_new',
            'parent_workflow_instance_id' => 'continue-instance',
            'parent_workflow_run_id' => $runs[1]->id,
            'child_workflow_instance_id' => 'continue-instance',
            'child_workflow_run_id' => $runs[2]->id,
            'is_primary_parent' => true,
        ]);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowContinuedAsNew',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[0]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertSame([
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowContinuedAsNew',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[1]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertSame([
            'WorkflowStarted',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runs[2]->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanWaitForChildWorkflowAndCompleteWithChildOutput(): void
    {
        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'parent-child-instance');
        $started = $workflow->start('Taylor');
        $parentRunId = $started->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame(1, $link->sequence);
        $this->assertSame('completed', $childRun->status->value);
        $this->assertNotSame('parent-child-instance', $link->child_workflow_instance_id);
        $this->assertNotSame($parentRunId, $childRun->id);
        $this->assertSame([
            'parent_workflow_id' => 'parent-child-instance',
            'parent_run_id' => $parentRunId,
            'child' => [
                'greeting' => 'Hello, Taylor!',
                'workflow_id' => $link->child_workflow_instance_id,
                'run_id' => $childRun->id,
            ],
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $this->assertDatabaseHas('workflow_links', [
            'id' => $link->id,
            'link_type' => 'child_workflow',
            'parent_workflow_instance_id' => 'parent-child-instance',
            'parent_workflow_run_id' => $parentRunId,
            'child_workflow_instance_id' => $link->child_workflow_instance_id,
            'child_workflow_run_id' => $childRun->id,
            'sequence' => 1,
            'is_primary_parent' => true,
        ]);
    }

    public function testWorkflowSummaryProjectsChildWaitAndHealthyRepairNoOp(): void
    {
        $workflow = WorkflowStub::make(TestParentWaitingOnChildWorkflow::class, 'parent-child-waiting');
        $workflow->start(60);
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'child');

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $summary = $workflow->summary();

        $this->assertSame('waiting', $workflow->status());
        $this->assertNotNull($summary);
        $this->assertSame('child', $summary->wait_kind);
        $this->assertSame(
            sprintf('Waiting for child workflow %s', $childRun->workflow_type),
            $summary->wait_reason,
        );
        $this->assertNull($summary->next_task_id);
        $this->assertSame('waiting_for_child', $summary->liveness_state);
        $this->assertSame(
            sprintf('Waiting for child workflow %s.', $childRun->workflow_type),
            $summary->liveness_reason,
        );
        $this->assertSame('waiting', $childRun->status->value);

        $result = WorkflowStub::loadRun($parentRunId)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_not_needed', $result->outcome());
    }

    public function testChildWorkflowFailurePropagatesToParentRun(): void
    {
        $workflow = WorkflowStub::make(TestParentFailingChildWorkflow::class, 'parent-child-failure');
        $workflow->start();
        $parentRunId = $workflow->runId();

        $this->assertNotNull($parentRunId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->failed());

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRunId)
            ->where('link_type', 'child_workflow')
            ->sole();

        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        /** @var WorkflowFailure $parentFailure */
        $parentFailure = WorkflowFailure::query()
            ->where('workflow_run_id', $parentRunId)
            ->where('propagation_kind', 'terminal')
            ->firstOrFail();

        $this->assertSame('failed', $childRun->status->value);
        $this->assertSame('failed', $workflow->status());
        $this->assertNull($workflow->output());
        $this->assertStringContainsString('boom', $parentFailure->message);
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunFailed',
            'WorkflowFailed',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRunId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testMakeCanReuseReservedInstanceWhenDurableTypeMatchesConfiguredAlias(): void
    {
        $this->configureGreetingTypeMaps();

        WorkflowInstance::query()->create([
            'id' => '01J0000000000000000000099',
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()->subMinute(),
        ]);

        $workflow = WorkflowStub::make(TestConfiguredGreetingWorkflow::class, '01J0000000000000000000099');

        $this->assertSame('01J0000000000000000000099', $workflow->id());
        $this->assertNull($workflow->runId());
        $this->assertDatabaseHas('workflow_instances', [
            'id' => '01J0000000000000000000099',
            'workflow_class' => TestConfiguredGreetingWorkflow::class,
            'workflow_type' => 'config-greeting-workflow',
        ]);
    }

    public function testWorkflowExecutorCanResolveConfiguredTypeWhenStoredWorkflowClassHasDrifted(): void
    {
        $this->configureGreetingTypeMaps();

        $instance = WorkflowInstance::query()->create([
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Legacy\\GreetingWorkflow',
            'workflow_type' => 'config-greeting-workflow',
            'status' => RunStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [],
            'available_at' => now()->subSeconds(5),
            'leased_at' => now()->subSeconds(2),
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        $nextTask = app(\Workflow\V2\Support\WorkflowExecutor::class)->run(
            $run->fresh(['instance', 'activityExecutions', 'timers', 'failures', 'tasks']),
            $task->fresh(),
        );

        $this->assertInstanceOf(WorkflowTask::class, $nextTask);
        $this->assertSame(TaskType::Activity, $nextTask->task_type);
        $this->assertSame('waiting', $run->fresh()->status->value);
        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertDatabaseHas('activity_executions', [
            'workflow_run_id' => $run->id,
            'activity_class' => TestConfiguredGreetingActivity::class,
            'activity_type' => 'config-greeting-activity',
        ]);
    }

    public function testRunActivityTaskCanResolveConfiguredTypeWhenStoredActivityClassHasDrifted(): void
    {
        Queue::fake();
        $this->configureGreetingTypeMaps();

        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestConfiguredGreetingWorkflow::class,
            'workflow_type' => 'config-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestConfiguredGreetingWorkflow::class,
            'workflow_type' => 'config-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'Legacy\\GreetingActivity',
            'activity_type' => 'config-greeting-activity',
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'available_at' => now(),
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        (new RunActivityTask($task->id))->handle();

        $this->assertSame('completed', $execution->fresh()->status->value);
        $this->assertSame('Hello, Taylor!', $execution->fresh()->activityResult());
        $this->assertSame('completed', $task->fresh()->status->value);
        $this->assertDatabaseHas('workflow_tasks', [
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
        ]);
    }

    public function testStartStillThrowsForDuplicateStartWhileRecordingRejectedCommand(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class);
        $workflow->start('Taylor');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('Workflow instance [%s] has already started.', $workflow->id()));

        try {
            $workflow->start('Jordan');
        } finally {
            $this->assertSame(2, WorkflowCommand::query()->count());
            $this->assertSame(
                'rejected',
                WorkflowCommand::query()->latest('created_at')->firstOrFail()->status->value
            );
        }
    }

    public function testWorkflowFailureIsProjected(): void
    {
        $workflow = WorkflowStub::make(TestFailingWorkflow::class);
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->failed());

        $failure = WorkflowFailure::query()
            ->where('source_kind', 'activity_execution')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($failure);
        $this->assertSame('boom', $failure->message);
        $this->assertSame('failed', $workflow->status());
    }

    public function testWorkflowCanHandleActivityFailureAndContinue(): void
    {
        $workflow = WorkflowStub::make(TestHandledFailureWorkflow::class);
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $summary = WorkflowRunSummary::query()->findOrFail($workflow->runId());

        $this->assertSame('Hello, Recovered!', $workflow->output());
        $this->assertSame('completed', $summary->status);
        $this->assertSame(1, WorkflowFailure::query()->where('handled', true)->count());
    }

    public function testWorkflowCanWaitForTimerAndResume(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(3);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $summary = $workflow->summary();

        $this->assertSame('timer', $summary?->wait_kind);
        $this->assertNotNull($summary?->wait_deadline_at);
        $this->assertNotNull($summary?->next_task_id);
        $this->assertSame('timer', $summary?->next_task_type);
        $this->assertSame('ready', $summary?->next_task_status);
        $this->assertSame('timer_scheduled', $summary?->liveness_state);
        $this->assertNotNull($summary?->liveness_reason);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('pending', $timer->status->value);
        $this->assertSame(3, $timer->delay_seconds);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'waited' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TimerFired',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanWaitForSignalAndResumeAfterSignalCommand(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'signal-instance');
        $workflow->start();

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'signal');

        $summary = $workflow->summary();

        $this->assertSame('signal', $summary?->wait_kind);
        $this->assertSame('Waiting for signal name-provided', $summary?->wait_reason);
        $this->assertNull($summary?->next_task_id);
        $this->assertSame('waiting_for_signal', $summary?->liveness_state);
        $this->assertSame('Waiting for signal name-provided.', $summary?->liveness_reason);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());

        $result = $workflow->signal('name-provided', 'Taylor');

        $this->assertTrue($result->accepted());
        $this->assertSame('signal', $result->type());
        $this->assertSame('signal_received', $result->outcome());
        $this->assertSame('signal-instance', $result->instanceId());
        $this->assertSame($runId, $result->runId());
        $this->assertSame(2, $result->commandSequence());

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $command = WorkflowCommand::query()->findOrFail($result->commandId());

        $this->assertNotNull($command->applied_at);
        $this->assertSame(2, $command->command_sequence);
        $this->assertSame('name-provided', $command->targetName());
        $this->assertSame([
            'name' => 'Taylor',
            'greeting' => 'Hello, Taylor!',
            'workflow_id' => 'signal-instance',
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'SignalWaitOpened',
            'SignalReceived',
            'SignalApplied',
            'ActivityScheduled',
            'ActivityCompleted',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testSignalCommandsUseDurableCommandSequenceOrder(): void
    {
        Queue::fake();

        $workflow = WorkflowStub::make(TestSignalOrderingWorkflow::class, 'signal-order-instance');
        $started = $workflow->start();
        $runId = $started->runId();

        $this->assertNotNull($runId);
        $this->assertSame(1, $started->commandSequence());

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertSame('waiting', $workflow->status());

        $first = $workflow->signal('message', 'first');
        $second = $workflow->signal('message', 'second');

        $this->assertSame(2, $first->commandSequence());
        $this->assertSame(3, $second->commandSequence());

        $this->drainReadyTasks();
        $workflow->refresh();

        $this->assertTrue($workflow->completed());
        $this->assertSame([
            'messages' => ['first', 'second'],
            'workflow_id' => 'signal-order-instance',
            'run_id' => $runId,
        ], $workflow->output());

        $this->assertSame([1, 2, 3], WorkflowCommand::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('command_sequence')
            ->pluck('command_sequence')
            ->all());

        $this->assertSame([
            $first->commandId(),
            $second->commandId(),
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', 'SignalApplied')
            ->orderBy('sequence')
            ->pluck('workflow_command_id')
            ->all());
    }

    public function testRunSummaryProjectsLeasedWorkflowTaskLiveness(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Running->value,
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(10),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
            'run_count' => 1,
            'started_at' => $run->started_at,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'payload' => [],
            'available_at' => now()
                ->subSeconds(20),
            'leased_at' => now()
                ->subSeconds(10),
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
        );

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('Workflow task leased to worker', $summary->wait_reason);
        $this->assertSame($task->id, $summary->next_task_id);
        $this->assertSame('workflow', $summary->next_task_type);
        $this->assertSame('leased', $summary->next_task_status);
        $this->assertSame('workflow_task_leased', $summary->liveness_state);
        $this->assertSame($task->lease_expires_at?->toJSON(), $summary->next_task_lease_expires_at?->toJSON());
    }

    public function testRunSummaryFlagsRepairNeededWhenResumeSourceIsMissing(): void
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 0,
            'reserved_at' => now()
                ->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(15),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
            'run_count' => 1,
            'started_at' => $run->started_at,
        ])->save();

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures'])
        );

        $this->assertNull($summary->wait_kind);
        $this->assertNull($summary->next_task_id);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Run is non-terminal but has no durable next-resume source.', $summary->liveness_reason);
    }

    public function testRepairRecreatesMissingWorkflowTaskForRepairNeededRun(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-workflow-instance',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_dispatched', $result->outcome());
        $this->assertSame($instance->id, $result->instanceId());
        $this->assertSame($run->id, $result->runId());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame([], $task->payload);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame('redis', $task->connection);
        $this->assertSame('default', $task->queue);

        Queue::assertPushed(RunWorkflowTask::class, static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id);

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_dispatched',
        ]);
    }

    public function testRunSummaryFlagsRepairNeededWhenWorkflowTaskLeaseExpires(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-expired-wf-inst',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()->subSeconds(20),
            'leased_at' => now()->subSeconds(20),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()->subSecond(),
            'last_dispatched_at' => now()->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame($task->id, $summary->next_task_id);
        $this->assertSame('leased', $summary->next_task_status);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertStringContainsString('lease expired', $summary->liveness_reason);
    }

    public function testRepairReusesExpiredWorkflowTaskInsteadOfCreatingDuplicate(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-existing-wf-task',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()->subSeconds(25),
            'leased_at' => now()->subSeconds(25),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()->subSecond(),
            'last_dispatched_at' => now()->subSeconds(25),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_dispatched', $result->outcome());
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);

        Queue::assertPushed(RunWorkflowTask::class, static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id);

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $updatedSummary->wait_kind);
        $this->assertSame('workflow_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
    }

    public function testRepairRecreatesMissingTimerTaskForPendingTimer(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-timer-instance',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => 'pending',
            'delay_seconds' => 30,
            'fire_at' => now()->addSeconds(25),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('timer', $summary->wait_kind);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair_dispatched', $result->outcome());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Timer, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(['timer_id' => $timer->id], $task->payload);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame($timer->fire_at?->toJSON(), $task->available_at?->toJSON());

        Queue::assertPushed(RunTimerTask::class, static fn (RunTimerTask $job): bool => $job->taskId === $task->id);

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('timer', $updatedSummary->wait_kind);
        $this->assertSame('timer_scheduled', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('timer', $updatedSummary->next_task_type);
    }

    public function testRepairReturnsAcceptedNoOpForHealthySignalWait(): void
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'repair-signal');
        $workflow->start();

        $this->waitFor(static fn (): bool => $workflow->refresh()->summary()?->wait_kind === 'signal');

        $runId = $workflow->runId();

        $this->assertNotNull($runId);
        $this->assertSame('waiting_for_signal', $workflow->summary()?->liveness_state);

        Queue::fake();

        $existingTaskIds = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->pluck('id')
            ->all();

        $result = WorkflowStub::loadRun($runId)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_not_needed', $result->outcome());
        $this->assertNull($result->rejectionReason());

        $this->assertSame(
            $existingTaskIds,
            WorkflowTask::query()->where('workflow_run_id', $runId)->pluck('id')->all(),
        );

        Queue::assertNothingPushed();

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $runId,
            'command_type' => 'repair',
            'status' => 'accepted',
            'outcome' => 'repair_not_needed',
        ]);
    }

    public function testTaskWatchdogRedispatchesReadyWorkflowTaskWhenDispatchIsOverdue(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-overdue-wf-task',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSeconds(20),
            'last_dispatched_at' => now()->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        TaskWatchdog::wake();

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);

        Queue::assertPushed(RunWorkflowTask::class, static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('workflow_task_ready', $summary->liveness_state);
    }

    public function testTaskWatchdogReclaimsExpiredActivityTaskLease(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-expired-act-task',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()->subSeconds(25),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()->subSeconds(25),
            'leased_at' => now()->subSeconds(25),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()->subSecond(),
            'last_dispatched_at' => now()->subSeconds(25),
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        TaskWatchdog::wake();

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(RunActivityTask::class, static fn (RunActivityTask $job): bool => $job->taskId === $task->id);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity', $summary->wait_kind);
        $this->assertSame('activity_task_ready', $summary->liveness_state);
        $this->assertSame($task->id, $summary->next_task_id);
    }

    public function testTaskWatchdogReclaimsExpiredTimerTaskLease(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-expired-timer-task',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 30,
            'fire_at' => now()->addSeconds(20),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Timer->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()->subSeconds(10),
            'leased_at' => now()->subSeconds(10),
            'lease_owner' => 'worker-1',
            'lease_expires_at' => now()->subSecond(),
            'last_dispatched_at' => now()->subSeconds(10),
            'payload' => [
                'timer_id' => $timer->id,
            ],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        TaskWatchdog::wake();

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame(1, $task->repair_count);

        Queue::assertPushed(RunTimerTask::class, static fn (RunTimerTask $job): bool => $job->taskId === $task->id);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('timer', $summary->wait_kind);
        $this->assertSame('timer_scheduled', $summary->liveness_state);
        $this->assertSame($task->id, $summary->next_task_id);
    }

    public function testWorkflowCanCompleteImmediateTimerWithoutSchedulingATimerTask(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(0);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $this->assertSame([
            'waited' => true,
            'workflow_id' => $workflow->id(),
            'run_id' => $runId,
        ], $workflow->output());

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('fired', $timer->status->value);
        $this->assertSame(0, $timer->delay_seconds);
        $this->assertNull($workflow->summary()?->wait_kind);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TimerFired',
            'WorkflowCompleted',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testWorkflowCanBeCancelledWhileWaitingOnTimer(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(2);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->cancel();

        $this->assertTrue($result->accepted());
        $this->assertSame('cancel', $result->type());
        $this->assertSame('cancelled', $result->outcome());
        $this->assertSame($workflow->id(), $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $workflow->refresh();

        $this->assertSame('cancelled', $workflow->status());
        $this->assertTrue($workflow->cancelled());
        $this->assertFalse($workflow->running());
        $this->assertNull($workflow->output());

        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertSame('cancelled', $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertSame('cancelled', $summary->closed_reason);
        $this->assertNull($summary->wait_kind);
        $this->assertNotNull($summary->closed_at);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('cancelled', $timer->status->value);

        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'timer')
            ->first();

        $this->assertNotNull($timerTask);
        $this->assertSame('cancelled', $timerTask->status->value);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $workflow->id(),
            'workflow_run_id' => $runId,
            'command_type' => 'cancel',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);

        usleep(2500000);
        $workflow->refresh();

        $this->assertSame('cancelled', $workflow->status());
        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'CancelRequested',
            'WorkflowCancelled',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testRunTargetedCancelUsesRunScopeForCurrentRun(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class, 'run-target-current');
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $selectedRun = WorkflowStub::loadRun($runId);
        $result = $selectedRun->attemptCancel();

        $this->assertTrue($result->accepted());
        $this->assertSame('run', $result->targetScope());
        $this->assertSame('cancelled', $result->outcome());
        $this->assertSame('run-target-current', $result->instanceId());
        $this->assertSame($runId, $result->runId());

        $selectedRun->refresh();

        $this->assertSame($runId, $selectedRun->runId());
        $this->assertSame($runId, $selectedRun->currentRunId());
        $this->assertTrue($selectedRun->currentRunIsSelected());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'run-target-current',
            'workflow_run_id' => $runId,
            'command_type' => 'cancel',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'cancelled',
        ]);
    }

    public function testWorkflowCanBeTerminatedWhileWaitingOnTimer(): void
    {
        $workflow = WorkflowStub::make(TestTimerWorkflow::class);
        $workflow->start(5);

        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->waitFor(static fn (): bool => $workflow->refresh()->status() === 'waiting'
            && $workflow->summary()?->wait_kind === 'timer');

        $result = $workflow->terminate();

        $this->assertTrue($result->accepted());
        $this->assertSame('terminate', $result->type());
        $this->assertSame('terminated', $result->outcome());

        $workflow->refresh();

        $this->assertSame('terminated', $workflow->status());
        $this->assertTrue($workflow->terminated());
        $this->assertFalse($workflow->running());
        $this->assertNull($workflow->output());

        $summary = $workflow->summary();

        $this->assertNotNull($summary);
        $this->assertSame('terminated', $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertSame('terminated', $summary->closed_reason);
        $this->assertNull($summary->wait_kind);

        $timer = WorkflowTimer::query()
            ->where('workflow_run_id', $runId)
            ->where('sequence', 1)
            ->first();

        $this->assertNotNull($timer);
        $this->assertSame('cancelled', $timer->status->value);

        $this->assertSame([
            'StartAccepted',
            'WorkflowStarted',
            'TimerScheduled',
            'TerminateRequested',
            'WorkflowTerminated',
        ], WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType->value)
            ->all());
    }

    public function testRunTargetedCancelRejectsHistoricalSelectionWithDurableOutcome(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'historical-instance',
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'run_count' => 2,
            'reserved_at' => now()->subMinutes(10),
            'started_at' => now()->subMinutes(10),
        ]);

        /** @var WorkflowRun $historicalRun */
        $historicalRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'arguments' => Serializer::serialize([1]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(9),
            'last_progress_at' => now()->subMinutes(9),
        ]);

        /** @var WorkflowRun $currentRun */
        $currentRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => TestTimerWorkflow::class,
            'workflow_type' => 'test-timer-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize([30]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $currentRun->id,
            'run_count' => 2,
        ])->save();

        RunSummaryProjector::project(
            $historicalRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        RunSummaryProjector::project(
            $currentRun->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $selectedRun = WorkflowStub::loadRun($historicalRun->id);
        $result = $selectedRun->attemptCancel();

        $this->assertTrue($result->rejected());
        $this->assertTrue($result->rejectedNotCurrent());
        $this->assertSame('run', $result->targetScope());
        $this->assertSame('rejected_not_current', $result->outcome());
        $this->assertSame('selected_run_not_current', $result->rejectionReason());
        $this->assertSame($instance->id, $result->instanceId());
        $this->assertSame($historicalRun->id, $result->runId());

        $selectedRun->refresh();

        $this->assertSame($historicalRun->id, $selectedRun->runId());
        $this->assertSame($currentRun->id, $selectedRun->currentRunId());
        $this->assertFalse($selectedRun->currentRunIsSelected());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $historicalRun->id,
            'command_type' => 'cancel',
            'target_scope' => 'run',
            'status' => 'rejected',
            'outcome' => 'rejected_not_current',
            'rejection_reason' => 'selected_run_not_current',
        ]);
    }

    public function testAttemptCancelRejectsReservedInstanceThatHasNotStarted(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'reserved-instance');

        $result = $workflow->attemptCancel();

        $this->assertTrue($result->rejected());
        $this->assertSame('cancel', $result->type());
        $this->assertSame('rejected_not_started', $result->outcome());
        $this->assertSame('instance_not_started', $result->rejectionReason());
        $this->assertSame('reserved-instance', $result->instanceId());
        $this->assertNull($result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'reserved-instance',
            'workflow_run_id' => null,
            'command_type' => 'cancel',
            'status' => 'rejected',
            'outcome' => 'rejected_not_started',
            'rejection_reason' => 'instance_not_started',
        ]);
    }

    public function testAttemptSignalRejectsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'closed-signal-instance');
        $workflow->start('Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $result = $workflow->attemptSignal('name-provided', 'Jordan');

        $this->assertTrue($result->rejected());
        $this->assertSame('signal', $result->type());
        $this->assertSame('rejected_not_active', $result->outcome());
        $this->assertSame('run_not_active', $result->rejectionReason());
        $this->assertSame('closed-signal-instance', $result->instanceId());
        $this->assertSame($workflow->runId(), $result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'closed-signal-instance',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_not_active',
            'rejection_reason' => 'run_not_active',
        ]);
    }

    public function testAttemptTerminateRejectsClosedRun(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'completed-instance');
        $workflow->start('Taylor');

        $this->waitFor(static fn (): bool => $workflow->refresh()->completed());

        $result = $workflow->attemptTerminate();

        $this->assertTrue($result->rejected());
        $this->assertSame('terminate', $result->type());
        $this->assertSame('rejected_not_active', $result->outcome());
        $this->assertSame('run_not_active', $result->rejectionReason());
        $this->assertSame('completed-instance', $result->instanceId());
        $this->assertSame($workflow->runId(), $result->runId());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'completed-instance',
            'workflow_run_id' => $workflow->runId(),
            'command_type' => 'terminate',
            'status' => 'rejected',
            'outcome' => 'rejected_not_active',
            'rejection_reason' => 'run_not_active',
        ]);
    }

    public function testRepairRecreatesMissingActivityTaskForPendingActivityExecution(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-activity-instance',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('activity', $summary->wait_kind);

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_dispatched', $result->outcome());

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->sole();

        $this->assertSame(TaskType::Activity, $task->task_type);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(['activity_execution_id' => $execution->id], $task->payload);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame('redis', $task->connection);
        $this->assertSame('activities', $task->queue);

        Queue::assertPushed(RunActivityTask::class, static fn (RunActivityTask $job): bool => $job->taskId === $task->id);

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity', $updatedSummary->wait_kind);
        $this->assertSame('activity_task_ready', $updatedSummary->liveness_state);
        $this->assertSame($task->id, $updatedSummary->next_task_id);
        $this->assertSame('activity', $updatedSummary->next_task_type);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_dispatched',
        ]);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'workflow_command_id' => $result->commandId(),
            'workflow_task_id' => $task->id,
            'event_type' => 'RepairRequested',
        ]);
    }

    public function testRepairDoesNotRecreateMissingTaskForRunningActivityExecution(): void
    {
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'repair-running-activity-01',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Running->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'activities',
            'started_at' => now()->subSeconds(20),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('activity', $summary->wait_kind);
        $this->assertSame('activity_running_without_task', $summary->liveness_state);
        $this->assertSame(
            sprintf(
                'Activity %s is already running without an open activity task. Repair is deferred to avoid duplicating in-flight work.',
                $execution->id,
            ),
            $summary->liveness_reason,
        );

        $result = WorkflowStub::loadRun($run->id)->attemptRepair();

        $this->assertTrue($result->accepted());
        $this->assertSame('repair', $result->type());
        $this->assertSame('repair_not_needed', $result->outcome());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        Queue::assertNothingPushed();

        $updatedSummary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('activity', $updatedSummary->wait_kind);
        $this->assertSame('activity_running_without_task', $updatedSummary->liveness_state);
        $this->assertNull($updatedSummary->next_task_id);

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => 'repair',
            'target_scope' => 'run',
            'status' => 'accepted',
            'outcome' => 'repair_not_needed',
        ]);

        $this->assertDatabaseHas('workflow_history_events', [
            'workflow_run_id' => $run->id,
            'workflow_command_id' => $result->commandId(),
            'workflow_task_id' => null,
            'event_type' => 'RepairRequested',
        ]);
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(100000);
        }

        $this->fail('Timed out waiting for workflow to settle.');
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

    private function configureGreetingTypeMaps(): void
    {
        config()->set('workflows.v2.types.workflows', [
            'config-greeting-workflow' => TestConfiguredGreetingWorkflow::class,
        ]);

        config()->set('workflows.v2.types.activities', [
            'config-greeting-activity' => TestConfiguredGreetingActivity::class,
        ]);
    }
}
