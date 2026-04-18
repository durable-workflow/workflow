<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;

final class V2RepairPassCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        parent::tearDown();
    }

    public function testItIgnoresTheLoopThrottleByDefaultAndRepairsMissingSignalTasks(): void
    {
        Queue::fake();
        Cache::put(TaskWatchdog::LOOP_THROTTLE_KEY, true, 60);

        [$run, $signal] = $this->createRepairNeededSignalRun('repair-pass-missing-signal');

        $expected = [
            'connection' => null,
            'queue' => null,
            'run_ids' => [],
            'instance_id' => null,
            'respect_throttle' => false,
            'throttled' => false,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 1,
            'selected_total_candidates' => 1,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 1,
            'dispatched_tasks' => 1,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'deadline_expired_candidates' => 0,
            'deadline_expired_tasks_created' => 0,
            'deadline_expired_failures' => [],
            'activity_timeout_candidates' => 0,
            'activity_timeouts_enforced' => 0,
            'activity_timeout_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame(1, $task->repair_count);
        $this->assertSame([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => sprintf('signal-application:%s', $signal->id),
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signal->id,
            'workflow_signal_id' => $signal->id,
            'workflow_command_id' => $signal->workflow_command_id,
        ], $task->payload);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow_task_ready', $summary->liveness_state);
        $this->assertSame($task->id, $summary->next_task_id);
    }

    public function testRespectThrottleOptionSkipsRepairWhenTheLoopThrottleIsHeld(): void
    {
        Queue::fake();
        Cache::put(TaskWatchdog::LOOP_THROTTLE_KEY, true, 60);

        $task = $this->createOverdueWorkflowTask('repair-pass-throttled');

        $expected = [
            'connection' => null,
            'queue' => null,
            'run_ids' => [],
            'instance_id' => null,
            'respect_throttle' => true,
            'throttled' => true,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 0,
            'selected_total_candidates' => 0,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'deadline_expired_candidates' => 0,
            'deadline_expired_tasks_created' => 0,
            'deadline_expired_failures' => [],
            'activity_timeout_candidates' => 0,
            'activity_timeouts_enforced' => 0,
            'activity_timeout_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--respect-throttle' => true,
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $task->refresh();

        $this->assertSame(0, $task->repair_count);
        $this->assertNull($task->last_dispatch_attempt_at);
        Queue::assertNothingPushed();
    }

    public function testItReportsExistingTaskRecoveryCountsInHumanOutput(): void
    {
        Queue::fake();
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        $task = $this->createOverdueWorkflowTask('repair-pass-existing-task');

        $this->artisan('workflow:v2:repair-pass')
            ->expectsOutput('Workflow v2 repair pass completed.')
            ->expectsOutput('Selected 1 existing task candidate(s) and 0 missing-task run candidate(s).')
            ->expectsOutput('Repaired 1 existing task(s), 0 missing task(s), and dispatched 1 task(s).')
            ->assertSuccessful();

        $task->refresh();

        $this->assertSame(1, $task->repair_count);
        $this->assertNotNull($task->last_dispatched_at);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );
    }

    public function testRunIdScopeRepairsOnlyTheSelectedMissingTaskRun(): void
    {
        Queue::fake();
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        [$selectedRun] = $this->createRepairNeededSignalRun('repair-pass-run-scope-selected');
        [$otherRun] = $this->createRepairNeededSignalRun('repair-pass-run-scope-other');

        $expected = [
            'connection' => null,
            'queue' => null,
            'run_ids' => [$selectedRun->id],
            'instance_id' => null,
            'respect_throttle' => false,
            'throttled' => false,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 1,
            'selected_total_candidates' => 1,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 1,
            'dispatched_tasks' => 1,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'deadline_expired_candidates' => 0,
            'deadline_expired_tasks_created' => 0,
            'deadline_expired_failures' => [],
            'activity_timeout_candidates' => 0,
            'activity_timeouts_enforced' => 0,
            'activity_timeout_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--run-id' => [$selectedRun->id],
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        /** @var WorkflowTask $selectedTask */
        $selectedTask = WorkflowTask::query()
            ->where('workflow_run_id', $selectedRun->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame(0, WorkflowTask::query() ->where('workflow_run_id', $otherRun->id) ->count());

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $selectedTask->id
        );
        Queue::assertPushed(RunWorkflowTask::class, 1);
    }

    public function testInstanceIdScopeRepairsOnlyTheSelectedInstanceTasks(): void
    {
        Queue::fake();
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);

        $selectedTask = $this->createOverdueWorkflowTask('repair-pass-instance-scope-selected');
        $otherTask = $this->createOverdueWorkflowTask('repair-pass-instance-scope-other');
        $otherLastDispatchedAt = $otherTask->last_dispatched_at?->toJSON();

        $expected = [
            'connection' => null,
            'queue' => null,
            'run_ids' => [],
            'instance_id' => 'repair-pass-instance-scope-selected',
            'respect_throttle' => false,
            'throttled' => false,
            'selected_existing_task_candidates' => 1,
            'selected_missing_task_candidates' => 0,
            'selected_total_candidates' => 1,
            'repaired_existing_tasks' => 1,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 1,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'deadline_expired_candidates' => 0,
            'deadline_expired_tasks_created' => 0,
            'deadline_expired_failures' => [],
            'activity_timeout_candidates' => 0,
            'activity_timeouts_enforced' => 0,
            'activity_timeout_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--instance-id' => 'repair-pass-instance-scope-selected',
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        $selectedTask->refresh();
        $otherTask->refresh();

        $this->assertSame(1, $selectedTask->repair_count);
        $this->assertNotNull($selectedTask->last_dispatched_at);
        $this->assertSame(0, $otherTask->repair_count);
        $this->assertSame($otherLastDispatchedAt, $otherTask->last_dispatched_at?->toJSON());

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $selectedTask->id
        );
        Queue::assertPushed(RunWorkflowTask::class, 1);
    }

    public function testItFailsRepairPassWhenMissingTaskRepairThrows(): void
    {
        Queue::fake();

        WorkflowRunSummary::query()->create([
            'id' => '01JREPAIRPASSMISSINGFAIL01',
            'workflow_instance_id' => 'repair-pass-missing-failure',
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'liveness_state' => 'repair_needed',
            'liveness_reason' => 'Repair candidate is missing its durable run row.',
            'started_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        $report = TaskWatchdog::runPass(runIds: ['01JREPAIRPASSMISSINGFAIL01']);

        $this->assertSame([], $report['existing_task_failures']);
        $this->assertCount(1, $report['missing_run_failures']);
        $this->assertSame('01JREPAIRPASSMISSINGFAIL01', $report['missing_run_failures'][0]['run_id']);
        $this->assertStringContainsString(
            'No query results for model',
            $report['missing_run_failures'][0]['message'],
        );

        $this->artisan('workflow:v2:repair-pass', [
            '--run-id' => ['01JREPAIRPASSMISSINGFAIL01'],
            '--json' => true,
        ])->assertFailed();
    }

    /**
     * @return array{0: WorkflowRun, 1: WorkflowSignal}
     */
    private function createRepairNeededSignalRun(string $instanceId): array
    {
        $run = $this->createWaitingRun($instanceId);

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_command_id' => (string) Str::ulid(),
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'resolved_workflow_run_id' => $run->id,
            'signal_name' => 'name-provided',
            'status' => SignalStatus::Received->value,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), []),
            'received_at' => now()
                ->subMinute(),
        ]);

        WorkflowRunSummary::query()->create([
            'id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'status' => RunStatus::Waiting->value,
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => $run->started_at,
            'wait_kind' => 'signal',
            'wait_reason' => 'Waiting to apply signal name-provided',
            'open_wait_id' => sprintf('signal-application:%s', $signal->id),
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signal->id,
            'liveness_state' => 'repair_needed',
            'liveness_reason' => 'Accepted signal name-provided is open without an open workflow task.',
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        return [$run, $signal];
    }

    private function createOverdueWorkflowTask(string $instanceId): WorkflowTask
    {
        $run = $this->createWaitingRun($instanceId);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(20),
            'last_dispatched_at' => now()
                ->subSeconds(20),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        return $task;
    }

    private function createWaitingRun(string $instanceId): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinutes(5),
            'started_at' => now()
                ->subMinutes(5),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), []),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()
                ->subMinutes(5),
            'last_progress_at' => now()
                ->subMinutes(4),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }

}
