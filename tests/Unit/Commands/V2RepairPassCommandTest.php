<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\CommandContractBackfillSweep;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\TaskWatchdog;

final class V2RepairPassCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        Cache::forget(CommandContractBackfillSweep::CURSOR_CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        Cache::forget(CommandContractBackfillSweep::CURSOR_CACHE_KEY);

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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
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
            ->expectsOutput(
                'Selected 0 command-contract candidate(s), backfilled 0, and left 0 unavailable on this build.'
            )
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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
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

    public function testItBackfillsLoadablePreviewCommandContractsDuringRepairPass(): void
    {
        Queue::fake();

        $run = $this->createLegacyContractRun('repair-pass-command-contracts');

        $expected = [
            'connection' => null,
            'queue' => null,
            'run_ids' => [],
            'instance_id' => null,
            'respect_throttle' => false,
            'throttled' => false,
            'selected_existing_task_candidates' => 0,
            'selected_missing_task_candidates' => 0,
            'selected_total_candidates' => 0,
            'repaired_existing_tasks' => 0,
            'repaired_missing_tasks' => 0,
            'dispatched_tasks' => 0,
            'selected_command_contract_candidates' => 1,
            'backfilled_command_contracts' => 1,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        /** @var WorkflowHistoryEvent $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->sole();

        $this->assertSame(['approval-stage', 'approvalMatches'], $started->payload['declared_queries'] ?? null);
        $this->assertSame('approval-stage', $started->payload['declared_query_contracts'][0]['name'] ?? null);
        $this->assertSame(['approved-by', 'rejected-by'], $started->payload['declared_signals'] ?? null);
        $this->assertSame('approved-by', $started->payload['declared_signal_contracts'][0]['name'] ?? null);
        $this->assertSame(['mark-approved'], $started->payload['declared_updates'] ?? null);
        $this->assertSame('mark-approved', $started->payload['declared_update_contracts'][0]['name'] ?? null);
        Queue::assertNothingPushed();
    }

    public function testItFailsRepairPassWhenMissingTaskRepairThrows(): void
    {
        Queue::fake();

        WorkflowRunSummary::query()->create([
            'id' => '01JREPAIRPASSMISSINGFAIL001',
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
            'started_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $report = TaskWatchdog::runPass(runIds: ['01JREPAIRPASSMISSINGFAIL001']);

        $this->assertSame([], $report['existing_task_failures']);
        $this->assertCount(1, $report['missing_run_failures']);
        $this->assertSame('01JREPAIRPASSMISSINGFAIL001', $report['missing_run_failures'][0]['run_id']);
        $this->assertStringContainsString(
            'No query results for model',
            $report['missing_run_failures'][0]['message'],
        );

        $this->artisan('workflow:v2:repair-pass', [
            '--run-id' => ['01JREPAIRPASSMISSINGFAIL001'],
            '--json' => true,
        ])->assertFailed();
    }

    public function testItFailsRepairPassWhenCommandContractBackfillThrows(): void
    {
        Queue::fake();
        config()->set('workflows.v2.history_event_model', ThrowingWorkflowHistoryEvent::class);

        $run = $this->createLegacyContractRun('repair-pass-command-contract-failure');

        $report = TaskWatchdog::runPass(runIds: [$run->id]);

        $this->assertSame([], $report['existing_task_failures']);
        $this->assertSame([], $report['missing_run_failures']);
        $this->assertSame(1, $report['selected_command_contract_candidates']);
        $this->assertSame(0, $report['backfilled_command_contracts']);
        $this->assertSame(0, $report['command_contract_backfill_unavailable']);
        $this->assertCount(1, $report['command_contract_failures']);
        $this->assertSame($run->id, $report['command_contract_failures'][0]['run_id']);
        $this->assertSame(
            'Simulated command-contract backfill write failure.',
            $report['command_contract_failures'][0]['message'],
        );

        $this->artisan('workflow:v2:repair-pass', [
            '--run-id' => [$run->id],
            '--json' => true,
        ])->assertFailed();
    }

    public function testItBackfillsLegacySignalLifecyclesBeforeRepairingMissingTasks(): void
    {
        Queue::fake();

        [$run, $command] = $this->createLegacySignalRepairRun('repair-pass-legacy-signal');

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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()
            ->where('workflow_command_id', $command->id)
            ->sole();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => sprintf('signal-application:%s', $signal->id),
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signal->id,
            'workflow_signal_id' => $signal->id,
            'workflow_command_id' => $command->id,
        ], $task->payload);

        /** @var WorkflowHistoryEvent $received */
        $received = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::SignalReceived->value)
            ->sole();

        $this->assertSame($signal->id, $received->payload['signal_id'] ?? null);
        $this->assertSame('legacy-signal-wait', $received->payload['signal_wait_id'] ?? null);
    }

    public function testItBackfillsLegacyUpdateLifecyclesBeforeRepairingMissingTasks(): void
    {
        Queue::fake();

        [$run, $command] = $this->createLegacyUpdateRepairRun('repair-pass-legacy-update');

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
            'selected_command_contract_candidates' => 0,
            'backfilled_command_contracts' => 0,
            'command_contract_backfill_unavailable' => 0,
            'existing_task_failures' => [],
            'missing_run_failures' => [],
            'command_contract_failures' => [],
        ];

        $this->artisan('workflow:v2:repair-pass', [
            '--json' => true,
        ])
            ->expectsOutput(json_encode($expected, JSON_UNESCAPED_SLASHES))
            ->assertSuccessful();

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()
            ->where('workflow_command_id', $command->id)
            ->sole();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();

        $this->assertSame([
            'workflow_wait_kind' => 'update',
            'open_wait_id' => sprintf('update:%s', $update->id),
            'resume_source_kind' => 'workflow_update',
            'resume_source_id' => $update->id,
            'workflow_update_id' => $update->id,
            'workflow_command_id' => $command->id,
        ], $task->payload);

        /** @var WorkflowHistoryEvent $accepted */
        $accepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::UpdateAccepted->value)
            ->sole();

        $this->assertSame($update->id, $accepted->payload['update_id'] ?? null);
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
            'payload_codec' => Serializer::class,
            'arguments' => Serializer::serialize([]),
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

    /**
     * @return array{0: WorkflowRun, 1: WorkflowCommand}
     */
    private function createLegacySignalRepairRun(string $instanceId): array
    {
        $run = $this->createWaitingRun($instanceId);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'signal',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'outcome' => 'signal_received',
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'name-provided',
                'arguments' => ['Taylor'],
            ]),
            'accepted_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::SignalWaitOpened->value,
            'payload' => [
                'signal_name' => 'name-provided',
                'sequence' => 1,
            ],
            'recorded_at' => now()
                ->subMinutes(2),
            'created_at' => now()
                ->subMinutes(2),
            'updated_at' => now()
                ->subMinutes(2),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::SignalReceived->value,
            'payload' => [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'signal_name' => 'name-provided',
                'signal_wait_id' => 'legacy-signal-wait',
            ],
            'workflow_command_id' => $command->id,
            'recorded_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return [$run, $command];
    }

    /**
     * @return array{0: WorkflowRun, 1: WorkflowCommand}
     */
    private function createLegacyUpdateRepairRun(string $instanceId): array
    {
        $run = $this->createWaitingRun($instanceId);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_sequence' => 2,
            'command_type' => 'update',
            'target_scope' => 'instance',
            'status' => 'accepted',
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'payload_codec' => Serializer::class,
            'payload' => Serializer::serialize([
                'name' => 'mark-approved',
                'arguments' => [true, 'api'],
            ]),
            'accepted_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        WorkflowHistoryEvent::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::UpdateAccepted->value,
            'payload' => [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
                'update_name' => 'mark-approved',
                'arguments' => Serializer::serialize([true, 'api']),
            ],
            'workflow_command_id' => $command->id,
            'recorded_at' => now()
                ->subMinute(),
            'created_at' => now()
                ->subMinute(),
            'updated_at' => now()
                ->subMinute(),
        ]);

        RunSummaryProjector::project(
            $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
        );

        return [$run, $command];
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
            'arguments' => Serializer::serialize([]),
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

    private function createLegacyContractRun(string $instanceId): WorkflowRun
    {
        $run = $this->createWaitingRun($instanceId);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'test-command-target-workflow',
            'declared_signals' => ['approved-by', 'rejected-by'],
            'declared_updates' => ['mark-approved'],
        ]);

        return $run->refresh();
    }
}

final class ThrowingWorkflowHistoryEvent extends WorkflowHistoryEvent
{
    public function save(array $options = []): bool
    {
        throw new RuntimeException('Simulated command-contract backfill write failure.');
    }
}
