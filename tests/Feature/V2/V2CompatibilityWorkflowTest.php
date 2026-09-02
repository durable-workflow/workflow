<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestContinueAsNewWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestParentChildWorkflow;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkerCompatibilityHeartbeat;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2CompatibilityWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.namespace', null);
        WorkerCompatibilityFleet::clear();
    }

    public function testStartFailsClosedWhenOnlyIncompatibleLiveWorkersExist(): void
    {
        Queue::fake();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-start-blocked');

        try {
            $workflow->start('Taylor');
            $this->fail('Expected start to fail closed when no compatible live worker exists.');
        } catch (WorkflowExecutionUnavailableException $exception) {
            $this->assertSame('start', $exception->operation());
            $this->assertSame('compat-start-blocked', $exception->targetName());
            $this->assertSame('compatibility_blocked', $exception->blockedReason());
            $this->assertSame(
                'Workflow instance [compat-start-blocked] cannot start. Start blocked under fail validation mode. '
                . 'No active worker heartbeat for queue [default] advertises compatibility [build-a]. '
                . 'Active workers there advertise [build-b].',
                $exception->getMessage(),
            );
        }

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()
            ->where('workflow_instance_id', 'compat-start-blocked')
            ->sole();

        $this->assertSame('rejected_compatibility_blocked', $command->outcome?->value);
        $this->assertSame('compatibility_blocked', $command->rejection_reason);
        $this->assertSame('compatibility_blocked', $command->commandReason());
        $this->assertSame(
            'Workflow instance [compat-start-blocked] cannot start. Start blocked under fail validation mode. '
            . 'No active worker heartbeat for queue [default] advertises compatibility [build-a]. '
            . 'Active workers there advertise [build-b].',
            $command->commandMessage(),
        );
        $this->assertNull($workflow->runId());
        $this->assertSame(0, WorkflowRun::query()->count());
    }

    public function testAttemptSignalWithStartRejectsWhenOnlyIncompatibleLiveWorkersExist(): void
    {
        Queue::fake();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-build-b');

        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'compat-signal-with-start-blocked');
        $result = $workflow->attemptSignalWithStart('name-provided', ['Taylor']);

        $this->assertTrue($result->rejected());
        $this->assertSame('rejected_compatibility_blocked', $result->outcome());
        $this->assertSame('compatibility_blocked', $result->rejectionReason());
        $this->assertSame('compatibility_blocked', $result->reason());
        $this->assertSame(
            'Workflow instance [compat-signal-with-start-blocked] cannot start via signal-with-start [name-provided]. '
            . 'Start blocked under fail validation mode. '
            . 'No active worker heartbeat for queue [default] advertises compatibility [build-a]. '
            . 'Active workers there advertise [build-b].',
            $result->message(),
        );
        $this->assertNull($result->startCommandId());
        $this->assertNull($workflow->runId());
        $this->assertSame(0, WorkflowRun::query()->count());

        $this->assertDatabaseHas('workflow_commands', [
            'id' => $result->commandId(),
            'workflow_instance_id' => 'compat-signal-with-start-blocked',
            'workflow_run_id' => null,
            'command_type' => 'signal',
            'status' => 'rejected',
            'outcome' => 'rejected_compatibility_blocked',
            'rejection_reason' => 'compatibility_blocked',
        ]);
    }

    public function testStartAndContinueAsNewPreserveCompatibilityMarkerAcrossRunsAndTasks(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-2026-04');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-2026-04']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestContinueAsNewWorkflow::class, 'compat-continue');
        $workflow->start(0, 1);

        $this->drainReadyTasks();

        $runs = WorkflowRun::query()
            ->where('workflow_instance_id', 'compat-continue')
            ->orderBy('run_number')
            ->get();

        $this->assertCount(2, $runs);
        $this->assertSame(['build-2026-04', 'build-2026-04'], $runs->pluck('compatibility')->all());
        $this->assertSame(['build-2026-04'], WorkflowTask::query()
            ->whereIn('workflow_run_id', $runs->pluck('id'))
            ->distinct()
            ->pluck('compatibility')
            ->all());
    }

    public function testChildRunsAndTasksInheritParentCompatibilityMarker(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-2026-04');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-2026-04']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestParentChildWorkflow::class, 'compat-child');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('link_type', 'child_workflow')
            ->firstOrFail();

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($link->parent_workflow_run_id);
        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);

        $this->assertSame('build-2026-04', $parentRun->compatibility);
        $this->assertSame($parentRun->compatibility, $childRun->compatibility);
        $this->assertTrue(WorkflowTask::query()
            ->where('workflow_run_id', $childRun->id)
            ->where('compatibility', $parentRun->compatibility)
            ->exists());
    }

    public function testIncompatibleWorkerDoesNotClaimWorkflowTask(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-claim');
        $workflow->start('Taylor');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $workflow->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->firstOrFail();

        config()
            ->set('workflows.v2.compatibility.supported', ['build-b']);

        $job = new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        $task->refresh();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertNull($task->leased_at);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
    }

    public function testIncompatibleWorkerBackfillsNullTaskCompatibilityFromRunMarkerBeforeClaim(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-null-task-claim',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => null,
        ]);

        $job = new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);

        $task->refresh();
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );
        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        Queue::assertNothingPushed();
        $this->assertSame('build-a', $task->compatibility);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(0, $task->attempt_count);
        $this->assertSame('build-a', $summary->compatibility);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('build-a', $detail['tasks'][0]['compatibility']);
        $this->assertFalse($detail['tasks'][0]['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['tasks'][0]['compatibility_reason'],
        );
    }

    public function testTaskWatchdogRedispatchesUnsupportedCompatibilityMarkerWithoutLocalSupport(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $lastDispatchedAt = now()
            ->subSeconds(30);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-watchdog',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => $lastDispatchedAt,
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        Queue::assertPushed(RunWorkflowTask::class);
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame('build-a', $task->compatibility);
        $this->assertFalse($task->last_dispatched_at?->equalTo($lastDispatchedAt) ?? true);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
    }

    public function testTaskWatchdogBackfillsNullTaskCompatibilityFromRunMarkerBeforeRedispatch(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-null-task-watchdog',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => null,
            'last_dispatched_at' => now()
                ->subSeconds(30),
        ]);

        $this->wakeTaskWatchdog();

        $task->refresh();
        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        Queue::assertPushed(RunWorkflowTask::class);
        $this->assertSame('build-a', $task->compatibility);
        $this->assertSame(1, $task->repair_count);
        $this->assertSame('build-a', $summary->compatibility);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('Workflow task waiting for a compatible worker', $summary->wait_reason);
    }

    public function testTaskWatchdogRecoversMissingTaskWithoutLocalSupport(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        Queue::fake();

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-missing-task',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subMinute(),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertNull($summary->next_task_id);

        $this->wakeTaskWatchdog();

        Queue::assertPushed(RunWorkflowTask::class);
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());

        $summary = $summary->fresh();
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->first();

        $this->assertNotNull($summary);
        $this->assertNotNull($task);
        $this->assertSame(TaskStatus::Ready, $task?->status);
        $this->assertSame(1, $task?->repair_count);
        $this->assertSame('build-a', $task?->compatibility);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertNotNull($summary->next_task_id);
    }

    public function testCompatibleFleetHeartbeatKeepsUnsupportedLocalTaskReady(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        config()
            ->set('workflows.v2.compatibility.namespace', 'sample-app');

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-build-a');

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-fleet-heartbeat',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_ready', $summary->liveness_state);
        $this->assertSame('Workflow task ready', $summary->wait_reason);
        $this->assertSame(sprintf('Workflow task %s is ready to run.', $task->id), $summary->liveness_reason);
        $expectedHeartbeat = WorkerCompatibilityHeartbeat::query()
            ->where('worker_id', 'worker-build-a');
        $this->assertCount(1, $expectedHeartbeat->get());
        $this->assertSame('sample-app', $expectedHeartbeat->sole()->namespace);
        $this->assertSame('sample-app', $detail['compatibility_namespace']);
        $this->assertFalse($detail['compatibility_supported']);
        $this->assertTrue($detail['compatibility_supported_in_fleet']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['compatibility_reason'],
        );
        $this->assertNull($detail['compatibility_fleet_reason']);
        $this->assertCount(1, $detail['compatibility_fleet']);
        $this->assertSame('worker-build-a', $detail['compatibility_fleet'][0]['worker_id']);
        $this->assertSame('sample-app', $detail['compatibility_fleet'][0]['namespace']);
        $this->assertSame('redis', $detail['compatibility_fleet'][0]['connection']);
        $this->assertSame('default', $detail['compatibility_fleet'][0]['queue']);
        $this->assertSame(['build-a'], $detail['compatibility_fleet'][0]['supported']);
        $this->assertTrue($detail['compatibility_fleet'][0]['supports_required']);
        $this->assertNotNull($detail['compatibility_fleet'][0]['recorded_at']);
        $this->assertNotNull($detail['compatibility_fleet'][0]['expires_at']);
        $this->assertSame('database', $detail['compatibility_fleet'][0]['source']);
        $this->assertSame('workflow_task_ready', $detail['liveness_state']);
        $this->assertSame('build-a', $detail['tasks'][0]['compatibility']);
        $this->assertFalse($detail['tasks'][0]['compatibility_supported']);
        $this->assertTrue($detail['tasks'][0]['compatibility_supported_in_fleet']);
        $this->assertNull($detail['tasks'][0]['compatibility_fleet_reason']);
        $this->assertSame('Workflow task ready to resume the selected run.', $detail['tasks'][0]['summary']);
    }

    public function testConfiguredCompatibilityNamespaceIgnoresOtherAppHeartbeats(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        config()
            ->set('workflows.v2.compatibility.namespace', 'other-app');

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-build-a');

        config()
            ->set('workflows.v2.compatibility.namespace', 'sample-app');

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-foreign-fleet-heartbeat',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('sample-app', $detail['compatibility_namespace']);
        $this->assertFalse($detail['compatibility_supported_in_fleet']);
        $this->assertSame([], $detail['compatibility_fleet']);
        $this->assertSame(
            'No active worker heartbeat for namespace [sample-app] connection [redis] queue [default] advertises compatibility [build-a].',
            $detail['compatibility_fleet_reason'],
        );
        $this->assertSame(
            sprintf(
                'Workflow task %s is ready but waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b]. No active worker heartbeat for namespace [sample-app] connection [redis] queue [default] advertises compatibility [build-a].',
                $task->id,
            ),
            $detail['liveness_reason'],
        );
        $this->assertFalse($detail['tasks'][0]['compatibility_supported_in_fleet']);
        $this->assertSame(
            'No active worker heartbeat for namespace [sample-app] connection [redis] queue [default] advertises compatibility [build-a].',
            $detail['tasks'][0]['compatibility_fleet_reason'],
        );
    }

    public function testLegacyCacheHeartbeatKeepsUnsupportedLocalTaskReadyDuringMixedUpgrade(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $this->seedLegacyFleetHeartbeat('worker-legacy-build-a', ['build-a'], 'redis', ['default']);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-legacy-fleet-heartbeat',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_ready', $summary->liveness_state);
        $this->assertSame('Workflow task ready', $summary->wait_reason);
        $this->assertCount(0, WorkerCompatibilityHeartbeat::query()->get());
        $this->assertFalse($detail['compatibility_supported']);
        $this->assertTrue($detail['compatibility_supported_in_fleet']);
        $this->assertNull($detail['compatibility_fleet_reason']);
        $this->assertCount(1, $detail['compatibility_fleet']);
        $this->assertSame('worker-legacy-build-a', $detail['compatibility_fleet'][0]['worker_id']);
        $this->assertSame('redis', $detail['compatibility_fleet'][0]['connection']);
        $this->assertSame('default', $detail['compatibility_fleet'][0]['queue']);
        $this->assertSame(['build-a'], $detail['compatibility_fleet'][0]['supported']);
        $this->assertTrue($detail['compatibility_fleet'][0]['supports_required']);
        $this->assertNull($detail['compatibility_fleet'][0]['host']);
        $this->assertNull($detail['compatibility_fleet'][0]['process_id']);
        $this->assertSame('cache', $detail['compatibility_fleet'][0]['source']);
        $this->assertNotNull($detail['compatibility_fleet'][0]['recorded_at']);
        $this->assertNotNull($detail['compatibility_fleet'][0]['expires_at']);
        $this->assertTrue($detail['tasks'][0]['compatibility_supported_in_fleet']);
        $this->assertNull($detail['tasks'][0]['compatibility_fleet_reason']);
        $this->assertSame('Workflow task ready to resume the selected run.', $detail['tasks'][0]['summary']);
    }

    public function testConfiguredCompatibilityNamespaceStillCountsLegacyCacheHeartbeatDuringMixedUpgrade(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);
        config()
            ->set('workflows.v2.compatibility.namespace', 'sample-app');

        $this->seedLegacyFleetHeartbeat('worker-legacy-build-a', ['build-a'], 'redis', ['default']);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-legacy-namespaced-fleet',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_ready', $summary->liveness_state);
        $this->assertSame('sample-app', $detail['compatibility_namespace']);
        $this->assertFalse($detail['compatibility_supported']);
        $this->assertTrue($detail['compatibility_supported_in_fleet']);
        $this->assertNull($detail['compatibility_fleet_reason']);
        $this->assertCount(1, $detail['compatibility_fleet']);
        $this->assertSame('worker-legacy-build-a', $detail['compatibility_fleet'][0]['worker_id']);
        $this->assertNull($detail['compatibility_fleet'][0]['namespace']);
        $this->assertSame('redis', $detail['compatibility_fleet'][0]['connection']);
        $this->assertSame('default', $detail['compatibility_fleet'][0]['queue']);
        $this->assertSame(['build-a'], $detail['compatibility_fleet'][0]['supported']);
        $this->assertTrue($detail['compatibility_fleet'][0]['supports_required']);
        $this->assertSame('cache', $detail['compatibility_fleet'][0]['source']);
        $this->assertTrue($detail['tasks'][0]['compatibility_supported_in_fleet']);
        $this->assertNull($detail['tasks'][0]['compatibility_fleet_reason']);
        $this->assertSame('Workflow task ready to resume the selected run.', $detail['tasks'][0]['summary']);
    }

    public function testDurableDatabaseHeartbeatWinsOverLegacyCacheSnapshotForSameWorkerScope(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        WorkerCompatibilityFleet::record(['build-a'], 'redis', 'default', 'worker-build-a');
        $this->seedLegacyFleetHeartbeat('worker-build-a', ['build-legacy'], 'redis', ['default']);

        $fleet = WorkerCompatibilityFleet::details('build-a', 'redis', 'default');

        $this->assertCount(1, $fleet);
        $this->assertSame('worker-build-a', $fleet[0]['worker_id']);
        $this->assertSame(['build-a'], $fleet[0]['supported']);
        $this->assertTrue($fleet[0]['supports_required']);
        $this->assertSame('database', $fleet[0]['source']);
    }

    public function testTaskWatchdogHeartbeatPersistsDurableWorkerSnapshot(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-watchdog']);
        config()
            ->set('workflows.v2.compatibility.namespace', 'watchdog-app');

        $this->wakeTaskWatchdog();

        /** @var WorkerCompatibilityHeartbeat $heartbeat */
        $heartbeat = WorkerCompatibilityHeartbeat::query()->sole();

        $this->assertSame('redis', $heartbeat->connection);
        $this->assertSame('default', $heartbeat->queue);
        $this->assertSame('watchdog-app', $heartbeat->namespace);
        $this->assertSame(['build-watchdog'], $heartbeat->supported);
        $this->assertNotNull($heartbeat->recorded_at);
        $this->assertNotNull($heartbeat->expires_at);
    }

    public function testRunDetailViewIncludesCompatibilityMetadata(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-b']);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'compat-detail');
        $workflow->start('Taylor');

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()
            ->with(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            ->findOrFail($workflow->runId());

        /** @var WorkflowTask $task */
        $task = $run->tasks->firstOrFail();

        RunSummaryProjector::project($run);

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('build-a', $detail['compatibility']);
        $this->assertFalse($detail['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['compatibility_reason'],
        );
        $this->assertSame([], $detail['compatibility_fleet']);
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertSame('Workflow task waiting for a compatible worker', $detail['wait_reason']);
        $this->assertSame(
            sprintf(
                'Workflow task %s is ready but waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b]. No active worker heartbeat for queue [default] advertises compatibility [build-a].',
                $task->id,
            ),
            $detail['liveness_reason'],
        );
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('waiting_for_compatible_worker', $detail['repair_blocked_reason']);
        $this->assertTrue($detail['repair_attention']);
        $this->assertSame([
            'code' => 'waiting_for_compatible_worker',
            'label' => 'Compat Blocked',
            'description' => 'Repair is blocked because the task is waiting for a compatible worker.',
            'tone' => 'warning',
            'badge_visible' => true,
        ], $detail['repair_blocked']);
        $this->assertSame('waiting_for_compatible_worker', $run->fresh()->summary?->repair_blocked_reason);
        $this->assertTrue((bool) $run->fresh()->summary?->repair_attention);
        $this->assertSame('build-a', $detail['tasks'][0]['compatibility']);
        $this->assertFalse($detail['tasks'][0]['compatibility_supported']);
        $this->assertSame(
            'Requires compatibility [build-a]; this worker supports [build-b].',
            $detail['tasks'][0]['compatibility_reason'],
        );
        $this->assertSame('Workflow task is waiting for a compatible worker.', $detail['tasks'][0]['summary']);
    }

    public function testIncompatibleOverdueReadyTaskDoesNotSurfaceAsRepairNeeded(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-overdue',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subSeconds(30),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('Workflow task waiting for a compatible worker', $summary->wait_reason);
        $this->assertSame(
            sprintf(
                'Workflow task %s is ready but dispatch is overdue and is waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b]. No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a].',
                $task->id,
            ),
            $summary->liveness_reason,
        );
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('waiting_for_compatible_worker', $detail['repair_blocked_reason']);
        $this->assertSame('waiting_for_compatible_worker', $summary->repair_blocked_reason);
        $this->assertSame(
            'Workflow task is waiting for a compatible worker; dispatch is overdue.',
            $detail['tasks'][0]['summary'],
        );
    }

    public function testIncompatibleExpiredLeaseDoesNotSurfaceAsRepairNeeded(): void
    {
        config()->set('workflows.v2.compatibility.supported', ['build-b']);

        $instance = WorkflowInstance::query()->create([
            'id' => 'compat-expired-lease',
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'reserved_at' => now()
                ->subMinutes(2),
            'started_at' => now()
                ->subMinutes(2),
            'run_count' => 1,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'compatibility' => 'build-a',
            'payload_codec' => config('workflows.serializer'),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(2),
            'last_progress_at' => now()
                ->subMinutes(2),
            'last_history_sequence' => 0,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => now()
                ->subMinute(),
            'leased_at' => now()
                ->subMinutes(2),
            'lease_owner' => 'worker-build-a',
            'lease_expires_at' => now()
                ->subMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'last_dispatched_at' => now()
                ->subMinutes(2),
        ]);

        $summary = RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        $detail = RunDetailView::forRun($run->fresh([
            'summary',
            'commands',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
            'parentLinks.parentRun.summary',
            'childLinks.childRun.summary',
            'instance.currentRun.summary',
        ]));

        $this->assertSame('workflow_task_waiting_for_compatible_worker', $summary->liveness_state);
        $this->assertSame('Workflow task waiting for a compatible worker', $summary->wait_reason);
        $this->assertSame(
            sprintf(
                'Workflow task %s lease expired and is waiting for a compatible worker. Requires compatibility [build-a]; this worker supports [build-b]. No active worker heartbeat for connection [redis] queue [default] advertises compatibility [build-a].',
                $task->id,
            ),
            $summary->liveness_reason,
        );
        $this->assertSame('workflow_task_waiting_for_compatible_worker', $detail['liveness_state']);
        $this->assertFalse($detail['can_repair']);
        $this->assertSame('waiting_for_compatible_worker', $detail['repair_blocked_reason']);
        $this->assertSame('waiting_for_compatible_worker', $summary->repair_blocked_reason);
        $this->assertSame(
            'Workflow task lease expired and is waiting for a compatible worker.',
            $detail['tasks'][0]['summary'],
        );
    }

    public function testRolloutLifecyclePreservesLongRunningRunsAcrossAdditiveRolloutDrainAndRollback(): void
    {
        config()->set('workflows.v2.compatibility.namespace', 'sample-app');

        // Phase 0 — steady state. Fleet advertises only build-old.
        config()
            ->set('workflows.v2.compatibility.current', 'build-old');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-old']);

        Queue::fake();

        $oldWorkflow = WorkflowStub::make(TestGreetingWorkflow::class, 'rollout-old-run');
        $oldWorkflow->start('Old');

        /** @var WorkflowRun $oldRun */
        $oldRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'rollout-old-run')
            ->sole();
        /** @var WorkflowTask $oldTask */
        $oldTask = WorkflowTask::query()
            ->where('workflow_run_id', $oldRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->sole();

        $this->assertSame('build-old', $oldRun->compatibility);
        $this->assertSame('build-old', $oldTask->compatibility);

        // Phase 1 — additive rollout. Starter flips to build-new; fleet
        // advertises both markers. The in-flight build-old run is unchanged
        // and still routable; newly-started runs stamp build-new.
        config()
            ->set('workflows.v2.compatibility.current', 'build-new');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-old', 'build-new']);

        WorkerCompatibilityFleet::record(['build-old', 'build-new'], 'redis', 'default', 'worker-rollout');

        $newWorkflow = WorkflowStub::make(TestGreetingWorkflow::class, 'rollout-new-run');
        $newWorkflow->start('New');

        /** @var WorkflowRun $newRun */
        $newRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'rollout-new-run')
            ->sole();

        $this->assertSame('build-new', $newRun->compatibility);
        $this->assertSame('build-old', $oldRun->fresh()->compatibility);

        $oldDetails = WorkerCompatibilityFleet::detailsForNamespace(
            'sample-app',
            'build-old',
            'redis',
            'default',
        );
        $this->assertCount(1, $oldDetails);
        $this->assertTrue($oldDetails[0]['supports_required']);

        $newDetails = WorkerCompatibilityFleet::detailsForNamespace(
            'sample-app',
            'build-new',
            'redis',
            'default',
        );
        $this->assertCount(1, $newDetails);
        $this->assertTrue($newDetails[0]['supports_required']);

        $this->assertSame(TaskStatus::Ready, $oldTask->fresh()?->status);
        $bridge = app(WorkflowTaskBridge::class);
        $rolloutClaim = $bridge->claimStatus($oldTask->id, 'worker-rollout');
        $this->assertTrue(
            $rolloutClaim['claimed'],
            'Mixed-fleet worker advertising both markers must claim the old-marker task.',
        );
        $this->assertSame('worker-rollout', $rolloutClaim['lease_owner']);
        $this->assertSame('build-old', $rolloutClaim['compatibility']);

        $oldTask->refresh();
        $oldTask->forceFill([
            'status' => TaskStatus::Ready,
            'leased_at' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'attempt_count' => 0,
            'last_claim_failed_at' => null,
            'last_claim_error' => null,
        ])->save();

        // Phase 2 — drain complete. The old fleet has been retired; only
        // build-new workers heartbeat and only build-new is supported. The
        // still-pinned build-old run is observable as "no compatible worker"
        // via supports_required=false, and a claim attempt from a build-new-only
        // worker leaves the task Ready without burning an attempt.
        WorkerCompatibilityFleet::clear();
        config()
            ->set('workflows.v2.compatibility.supported', ['build-new']);
        WorkerCompatibilityFleet::record(['build-new'], 'redis', 'default', 'worker-new-only');

        $drainedOldDetails = WorkerCompatibilityFleet::detailsForNamespace(
            'sample-app',
            'build-old',
            'redis',
            'default',
        );
        $this->assertCount(1, $drainedOldDetails);
        $this->assertFalse(
            $drainedOldDetails[0]['supports_required'],
            'Removing the old marker from every live heartbeat must surface as supports_required=false.',
        );

        $drainClaim = $bridge->claimStatus($oldTask->id, 'worker-new-only');
        $this->assertFalse(
            $drainClaim['claimed'],
            'A build-new-only worker must reject the build-old task at claim.',
        );
        $this->assertSame('compatibility_blocked', $drainClaim['reason']);
        $drainedTask = $oldTask->fresh();
        $this->assertSame(TaskStatus::Ready, $drainedTask?->status);
        $this->assertNull($drainedTask?->leased_at);

        // Phase 3 — rollback. Re-advertise build-old on the live fleet and
        // flip the starter back to build-old. The in-flight build-old task is
        // routable again, and newly-started runs stamp build-old (the
        // inverse of the rollout). No in-flight run is silently retargeted.
        WorkerCompatibilityFleet::record(['build-old', 'build-new'], 'redis', 'default', 'worker-rolled-back');
        config()
            ->set('workflows.v2.compatibility.current', 'build-old');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-old', 'build-new']);

        $rolledBackOldDetails = WorkerCompatibilityFleet::detailsForNamespace(
            'sample-app',
            'build-old',
            'redis',
            'default',
        );
        $this->assertNotEmpty($rolledBackOldDetails);
        $this->assertTrue(
            collect($rolledBackOldDetails)
                ->contains(static fn (array $snapshot): bool => $snapshot['supports_required'] === true),
            'A rollback that re-advertises the old marker must restore supports_required=true on the pinned run.',
        );

        $rollbackClaim = $bridge->claimStatus($oldTask->id, 'worker-rolled-back');
        $this->assertTrue(
            $rollbackClaim['claimed'],
            'A rollback that re-advertises the old marker must let the pinned task be claimed again.',
        );
        $this->assertSame('worker-rolled-back', $rollbackClaim['lease_owner']);
        $rolledBackTask = $oldTask->fresh();
        $this->assertSame(TaskStatus::Leased, $rolledBackTask?->status);

        $postRollbackWorkflow = WorkflowStub::make(TestGreetingWorkflow::class, 'rollout-post-rollback-run');
        $postRollbackWorkflow->start('Rollback');

        /** @var WorkflowRun $postRollbackRun */
        $postRollbackRun = WorkflowRun::query()
            ->where('workflow_instance_id', 'rollout-post-rollback-run')
            ->sole();
        $this->assertSame('build-old', $postRollbackRun->compatibility);

        // The build-new run started before the rollback keeps its original
        // marker — compatibility stamping is once-per-run at Start and is
        // not retroactively rewritten by a starter flip.
        $this->assertSame('build-new', $newRun->fresh()->compatibility);
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

    private function wakeTaskWatchdog(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        TaskWatchdog::runPass(connection: 'redis', queue: 'default', respectThrottle: false);
    }

    /**
     * @param  list<string>  $supported
     * @param  list<string>  $queues
     */
    private function seedLegacyFleetHeartbeat(
        string $workerId,
        array $supported,
        ?string $connection = null,
        array $queues = [],
    ): void {
        $fleet = Cache::get('workflow:v2:compatibility:fleet');

        if (! is_array($fleet)) {
            $fleet = [];
        }

        $fleet[$workerId] = [
            'supported' => $supported,
            'connection' => $connection,
            'queues' => $queues,
            'recorded_at' => now()
                ->subSeconds(5)
                ->getTimestamp(),
            'expires_at' => now()
                ->addSeconds(30)
                ->getTimestamp(),
        ];

        Cache::forever('workflow:v2:compatibility:fleet', $fleet);
    }
}
