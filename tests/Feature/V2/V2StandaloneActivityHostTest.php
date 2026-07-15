<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivityTaskClaimer;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\StandaloneActivityStartService;
use Workflow\V2\TaskWatchdog;

/**
 * Contract tests for the standalone-activity host run.
 *
 * Pins:
 *  - {@see StandaloneActivityStartService::start()} writes a host
 *    {@see WorkflowInstance}, a {@see WorkflowRun} with the marker
 *    {@see StandaloneActivityHostType::WORKFLOW_TYPE}, an
 *    {@see ActivityExecution}, and a ready activity {@see WorkflowTask}
 *    in one transaction.
 *  - The host run starts in {@see RunStatus::Running} (the activity is the
 *    work — there is no workflow-task to claim before it).
 *  - {@see ActivityOutcomeRecorder::record()} schedules a retry task and
 *    keeps the host run Running across a transient failure.
 *  - On terminal success, the host run is closed Completed and the run's
 *    `output` column carries the activity's serialized result so a
 *    job-style consumer can read the result without spelunking history.
 *  - On terminal failure (attempts exhausted), the host run is closed
 *    Failed.
 *  - On terminal timeout exhaustion, the host run is closed Failed/timed_out
 *    with a WorkflowFailed event rather than a workflow-task resume row.
 *  - The same Activity definition is reusable inside a workflow without
 *    rewriting — the bridge produces an identical execution row shape for
 *    a host run and a normal child workflow run.
 */
final class V2StandaloneActivityHostTest extends TestCase
{
    public function testStartCreatesHostRunAndReadyActivityTask(): void
    {
        $service = $this->app->make(StandaloneActivityStartService::class);

        $arguments = Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => 'standalone-host-1',
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => $arguments,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [0],
            ],
            'start_to_close_timeout_seconds' => 60,
        ]);

        $this->assertTrue($start['started']);
        $this->assertSame('standalone-host-1', $start['activity_id']);
        $this->assertSame(StandaloneActivityHostType::WORKFLOW_TYPE, $start['workflow_type']);
        $this->assertSame('standalone-activities', $start['task_queue']);
        $this->assertSame(RunStatus::Running->value, $start['status']);

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->findOrFail('standalone-host-1');
        $this->assertSame(StandaloneActivityHostType::WORKFLOW_TYPE, $instance->workflow_type);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertTrue(StandaloneActivityHostType::isHostRun($run));
        $this->assertSame(RunStatus::Running, $run->status);
        $this->assertSame('standalone-activities', $run->queue);
        $this->assertSame($instance->id, $run->workflow_instance_id);
        $this->assertSame($run->id, $instance->fresh()->current_run_id);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->findOrFail($start['activity_execution_id']);
        $this->assertSame('tests.greeting-activity', $execution->activity_type);
        $this->assertSame(TestGreetingActivity::class, $execution->activity_class);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame($run->id, $execution->workflow_run_id);
        $this->assertSame($arguments, $execution->arguments);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();
        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame('standalone-activities', $task->queue);
        $this->assertSame($execution->id, $task->payload['activity_execution_id']);

        $this->assertGreaterThan(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->count(),
            'host run should record WorkflowStarted so it surfaces on lineage and listing APIs',
        );
    }

    public function testStartRecordsCommandAndStartAcceptedAuditWithCallerContext(): void
    {
        $service = $this->app->make(StandaloneActivityStartService::class);
        $arguments = Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Audited']);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => 'standalone-audit-1',
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => $arguments,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'command_context' => CommandContext::controlPlane()
                ->withPrincipal('service', 'workflow-server', 'Workflow Server')
                ->withIntake('standalone_activity', 'request-123'),
        ]);

        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()
            ->where('workflow_run_id', $start['workflow_run_id'])
            ->where('command_type', CommandType::Start->value)
            ->firstOrFail();

        $this->assertSame(CommandStatus::Accepted, $command->status);
        $this->assertSame(CommandOutcome::StartedNew, $command->outcome);
        $this->assertSame('control_plane', $command->source);
        $this->assertSame($arguments, $command->payload);
        $this->assertSame('workflow-server', $command->context['principal']['id']);
        $this->assertSame('standalone_activity', $command->context['intake']['mode']);
        $this->assertSame('request-123', $command->context['intake']['group_id']);

        /** @var WorkflowHistoryEvent $accepted */
        $accepted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $start['workflow_run_id'])
            ->where('event_type', HistoryEventType::StartAccepted->value)
            ->firstOrFail();

        $this->assertSame($command->id, $accepted->workflow_command_id);
        $this->assertSame($command->id, $accepted->payload['workflow_command_id']);
        $this->assertSame(CommandOutcome::StartedNew->value, $accepted->payload['outcome']);
        $this->assertSame('workflow-server', $accepted->payload['command']['principal_id']);
        $this->assertSame('standalone_activity', $accepted->payload['command']['context']['intake']['mode']);
    }

    public function testStartAdvancesRunNumberFromResolvedTerminalRunWhenInstanceCountIsStale(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => 'standalone-stale-count-1',
            'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'namespace' => 'default',
            'reserved_at' => $startedAt,
            'started_at' => $startedAt,
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $firstRun */
        $firstRun = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'namespace' => 'default',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'compatibility' => 'test',
            'payload_codec' => CodecRegistry::defaultCodec(),
            'started_at' => $startedAt,
            'closed_at' => $startedAt->copy()
                ->addSecond(),
            'last_progress_at' => $startedAt->copy()
                ->addSecond(),
        ]);

        WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 2,
            'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'namespace' => 'default',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'compatibility' => 'test',
            'payload_codec' => CodecRegistry::defaultCodec(),
            'started_at' => $startedAt->copy()
                ->addSeconds(2),
            'closed_at' => $startedAt->copy()
                ->addSeconds(3),
            'last_progress_at' => $startedAt->copy()
                ->addSeconds(3),
        ]);

        $instance->forceFill([
            'current_run_id' => $firstRun->id,
            'run_count' => 1,
        ])->save();

        $service = $this->app->make(StandaloneActivityStartService::class);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => $instance->id,
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'payload_codec' => CodecRegistry::defaultCodec(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(3, (int) $run->run_number);

        /** @var WorkflowInstance $freshInstance */
        $freshInstance = WorkflowInstance::query()->findOrFail($instance->id);
        $this->assertSame($run->id, $freshInstance->current_run_id);
        $this->assertSame(3, (int) $freshInstance->run_count);
    }

    public function testStartDispatchesReadyActivityTaskInDefaultQueueMode(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'queue');
        Queue::fake();

        $service = $this->app->make(StandaloneActivityStartService::class);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => 'standalone-dispatch-1',
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Queue']),
            'payload_codec' => CodecRegistry::defaultCodec(),
        ]);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start['workflow_run_id'])
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $this->assertSame(TaskStatus::Ready, $task->status);
        $this->assertSame('standalone-activities', $task->queue);
        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunActivityTask::class,
            static fn (RunActivityTask $job): bool => $job->taskId === $task->id
        );
    }

    public function testStartGeneratesIdentifierWhenActivityIdIsOmitted(): void
    {
        $service = $this->app->make(StandaloneActivityStartService::class);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => null,
            'activity_type' => 'tests.greeting-activity',
            'task_queue' => 'standalone-activities',
            'payload_codec' => CodecRegistry::defaultCodec(),
        ]);

        $this->assertNotSame('', $start['activity_id']);
        $this->assertSame(StandaloneActivityHostType::WORKFLOW_TYPE, $start['workflow_type']);
        $this->assertNotNull(WorkflowInstance::query()->find($start['activity_id']));
    }

    public function testFailureSchedulesRetryAndKeepsHostRunRunning(): void
    {
        $service = $this->app->make(StandaloneActivityStartService::class);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => 'standalone-retry-1',
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Retry']),
            'payload_codec' => CodecRegistry::defaultCodec(),
            'retry_policy' => [
                'max_attempts' => 3,
                'backoff_seconds' => [0],
            ],
        ]);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start['workflow_run_id'])
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $claimResult = ActivityTaskClaimer::claimDetailed($task->id);
        $this->assertNotNull($claimResult['claim'], 'first attempt should be claimable');

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $claimResult['claim']->attemptId(),
            result: null,
            throwable: new RuntimeException('transient'),
            codec: CodecRegistry::defaultCodec(),
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task'], 'a retry task should be scheduled');
        $this->assertSame(TaskType::Activity, $outcome['next_task']->task_type);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(
            RunStatus::Running,
            $run->status,
            'host run must stay Running across a retry — the activity is not done yet',
        );
        $this->assertNull($run->closed_at);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->findOrFail($start['activity_execution_id']);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
    }

    public function testFinalSuccessClosesHostRunAndPublishesActivityResult(): void
    {
        $service = $this->app->make(StandaloneActivityStartService::class);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => 'standalone-success-1',
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Taylor']),
            'payload_codec' => CodecRegistry::defaultCodec(),
            'retry_policy' => [
                'max_attempts' => 1,
                'backoff_seconds' => [0],
            ],
        ]);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start['workflow_run_id'])
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $claimResult = ActivityTaskClaimer::claimDetailed($task->id);
        $this->assertNotNull($claimResult['claim']);

        $resultBlob = Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), 'Hello, Taylor!');

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $claimResult['claim']->attemptId(),
            result: $resultBlob,
            throwable: null,
            codec: CodecRegistry::defaultCodec(),
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull(
            $outcome['next_task'],
            'standalone host runs do not schedule a workflow-task resume row on activity success',
        );

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame('completed', $run->closed_reason);
        $this->assertNotNull($run->closed_at);
        $this->assertSame($resultBlob, $run->output);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->findOrFail($start['activity_execution_id']);
        $this->assertSame(ActivityStatus::Completed, $execution->status);
        $this->assertSame($resultBlob, $execution->result);
    }

    public function testFinalFailureClosesHostRunAsFailed(): void
    {
        $service = $this->app->make(StandaloneActivityStartService::class);

        $start = $service->start([
            'namespace' => 'default',
            'activity_id' => 'standalone-fail-1',
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['boom']),
            'payload_codec' => CodecRegistry::defaultCodec(),
            'retry_policy' => [
                'max_attempts' => 1,
                'backoff_seconds' => [0],
            ],
        ]);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $start['workflow_run_id'])
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();

        $claimResult = ActivityTaskClaimer::claimDetailed($task->id);
        $this->assertNotNull($claimResult['claim']);

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $claimResult['claim']->attemptId(),
            result: null,
            throwable: new RuntimeException('terminal failure'),
            codec: CodecRegistry::defaultCodec(),
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull(
            $outcome['next_task'],
            'standalone host runs do not schedule a workflow-task resume row on activity failure',
        );

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('failed', $run->closed_reason);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->findOrFail($start['activity_execution_id']);
        $this->assertSame(ActivityStatus::Failed, $execution->status);
    }

    public function testScheduleToStartTimeoutExhaustionClosesHostRunAsFailed(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-schedule-1', [
                'schedule_to_start_timeout_seconds' => 30,
            ]);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $result = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                $result,
                'schedule_to_start',
                'schedule-to-start deadline expired',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testWatchdogTimeoutSweepClosesHostRunWithoutWorkflowDispatch(): void
    {
        Queue::fake();
        config()
            ->set('workflows.v2.task_repair.redispatch_after_seconds', 300);

        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-watchdog-1', [
                'schedule_to_start_timeout_seconds' => 30,
            ]);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $report = TaskWatchdog::runPass();

            $this->assertSame(1, $report['activity_timeout_candidates']);
            $this->assertSame(1, $report['activity_timeouts_enforced']);
            $this->assertSame(
                0,
                $report['dispatched_tasks'],
                'a terminal standalone timeout must close the host instead of dispatching workflow code',
            );

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                [
                    'enforced' => true,
                    'reason' => null,
                    'next_task' => null,
                ],
                'schedule_to_start',
                'schedule-to-start deadline expired',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testScheduleToCloseTimeoutExhaustionClosesHostRunAsFailed(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-schedule-close-1', [
                'schedule_to_close_timeout_seconds' => 30,
            ]);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $result = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                $result,
                'schedule_to_close',
                'schedule-to-close deadline expired',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testScheduleToCloseTimeoutWithAttemptsRemainingClosesHostRunWithoutRetry(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-schedule-close-no-retry-1', [
                'schedule_to_close_timeout_seconds' => 30,
                'retry_policy' => [
                    'max_attempts' => 3,
                    'backoff_seconds' => [0],
                ],
            ]);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $result = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                $result,
                'schedule_to_close',
                'schedule-to-close deadline expired',
            );

            $this->assertSame(
                1,
                WorkflowTask::query()
                    ->where('workflow_run_id', $start['workflow_run_id'])
                    ->where('task_type', TaskType::Activity->value)
                    ->count(),
                'schedule-to-close is terminal for the execution and must not create an activity retry task',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testExhaustedRetryTimeoutClosesHostRunWithoutWorkflowDispatch(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-exhausted-retry-1', [
                'start_to_close_timeout_seconds' => 30,
                'retry_policy' => [
                    'max_attempts' => 2,
                    'backoff_seconds' => [0],
                ],
            ]);

            $firstTask = $this->standaloneActivityTask($start['workflow_run_id']);
            $firstClaim = ActivityTaskClaimer::claimDetailed($firstTask->id);
            $this->assertNotNull($firstClaim['claim']);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $retryResult = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);
            $this->assertTrue($retryResult['enforced']);
            $retryTask = $retryResult['next_task'];
            $this->assertInstanceOf(WorkflowTask::class, $retryTask);
            $this->assertSame(TaskType::Activity, $retryTask->task_type);

            /** @var WorkflowRun $runAfterRetry */
            $runAfterRetry = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
            $this->assertSame(RunStatus::Running, $runAfterRetry->status);

            $secondClaim = ActivityTaskClaimer::claimDetailed($retryTask->id);
            $this->assertNotNull($secondClaim['claim']);

            Carbon::setTestNow($startedAt->copy()->addSeconds(120));

            $terminalResult = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                $terminalResult,
                'start_to_close',
                'start-to-close deadline expired',
                expectAttemptClosed: true,
            );

            $this->assertSame(
                2,
                WorkflowTask::query()
                    ->where('workflow_run_id', $start['workflow_run_id'])
                    ->where('task_type', TaskType::Activity->value)
                    ->count(),
                'the exhausted retry timeout must close the existing retry task without scheduling more activity work',
            );
            $this->assertSame(
                0,
                WorkflowTask::query()
                    ->where('workflow_run_id', $start['workflow_run_id'])
                    ->where('task_type', TaskType::Activity->value)
                    ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                    ->count(),
                'the exhausted retry timeout must leave no open activity task behind',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testStartToCloseTimeoutExhaustionClosesHostRunAsFailed(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-start-1', [
                'start_to_close_timeout_seconds' => 30,
            ]);

            $task = $this->standaloneActivityTask($start['workflow_run_id']);
            $claimResult = ActivityTaskClaimer::claimDetailed($task->id);
            $this->assertNotNull($claimResult['claim']);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $result = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                $result,
                'start_to_close',
                'start-to-close deadline expired',
                expectAttemptClosed: true,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testHeartbeatTimeoutExhaustionClosesHostRunAsFailed(): void
    {
        $startedAt = Carbon::parse('2026-01-15 10:00:00');
        Carbon::setTestNow($startedAt);

        try {
            $start = $this->startStandaloneTimeoutActivity('standalone-timeout-heartbeat-1', [
                'start_to_close_timeout_seconds' => 300,
                'heartbeat_timeout_seconds' => 30,
            ]);

            $task = $this->standaloneActivityTask($start['workflow_run_id']);
            $claimResult = ActivityTaskClaimer::claimDetailed($task->id);
            $this->assertNotNull($claimResult['claim']);

            Carbon::setTestNow($startedAt->copy()->addSeconds(60));

            $result = ActivityTimeoutEnforcer::enforce($start['activity_execution_id']);

            $this->assertStandaloneTimeoutClosedHost(
                $start,
                $result,
                'heartbeat',
                'heartbeat deadline expired',
                expectAttemptClosed: true,
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testStartRejectsActivityIdAlreadyReservedForADifferentWorkflowType(): void
    {
        WorkflowInstance::query()->create([
            'id' => 'collides-with-workflow',
            'workflow_class' => 'some-existing-workflow-type',
            'workflow_type' => 'some-existing-workflow-type',
            'run_count' => 0,
            'reserved_at' => now(),
        ]);

        $service = $this->app->make(StandaloneActivityStartService::class);

        $this->expectException(\InvalidArgumentException::class);

        $service->start([
            'namespace' => 'default',
            'activity_id' => 'collides-with-workflow',
            'activity_type' => 'tests.greeting-activity',
            'task_queue' => 'standalone-activities',
            'payload_codec' => CodecRegistry::defaultCodec(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function startStandaloneTimeoutActivity(string $activityId, array $overrides): array
    {
        $service = $this->app->make(StandaloneActivityStartService::class);

        return $service->start(array_merge([
            'namespace' => 'default',
            'activity_id' => $activityId,
            'activity_type' => 'tests.greeting-activity',
            'activity_class' => TestGreetingActivity::class,
            'task_queue' => 'standalone-activities',
            'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), ['Timeout']),
            'payload_codec' => CodecRegistry::defaultCodec(),
            'retry_policy' => [
                'max_attempts' => 1,
                'backoff_seconds' => [0],
            ],
        ], $overrides));
    }

    private function standaloneActivityTask(string $workflowRunId): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $workflowRunId)
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $start
     * @param array{enforced: bool, reason: string|null, next_task: WorkflowTask|null} $result
     */
    private function assertStandaloneTimeoutClosedHost(
        array $start,
        array $result,
        string $timeoutKind,
        string $messageFragment,
        bool $expectAttemptClosed = false,
    ): void {
        $this->assertTrue($result['enforced']);
        $this->assertNull(
            $result['next_task'],
            'standalone timeout exhaustion must not create a workflow-task resume row',
        );

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->assertTrue(StandaloneActivityHostType::isHostRun($run));
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);
        $this->assertNotNull($run->closed_at);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->findOrFail($start['activity_execution_id']);
        $this->assertSame(ActivityStatus::Failed, $execution->status);
        $this->assertNotNull($execution->closed_at);

        $this->assertSame(
            0,
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', TaskType::Workflow->value)
                ->count(),
            'standalone host runs have no workflow code to resume after terminal activity timeout',
        );

        $activityTask = $this->standaloneActivityTask($run->id);
        $this->assertSame(TaskStatus::Cancelled, $activityTask->status);

        /** @var WorkflowRunSummary $summary */
        $summary = WorkflowRunSummary::query()->findOrFail($run->id);
        $this->assertSame(RunStatus::Failed->value, $summary->status);
        $this->assertSame('failed', $summary->status_bucket);
        $this->assertSame('timed_out', $summary->closed_reason);
        $this->assertNull($summary->wait_kind);
        $this->assertNull($summary->next_task_id);
        $this->assertSame(1, $summary->exception_count);

        if ($expectAttemptClosed) {
            $attempt = ActivityAttempt::query()
                ->where('activity_execution_id', $execution->id)
                ->whereKey($execution->current_attempt_id)
                ->firstOrFail();

            $this->assertSame(ActivityAttemptStatus::Failed, $attempt->status);
            $this->assertNotNull($attempt->closed_at);
        }

        $timedOutEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::ActivityTimedOut->value)
            ->firstOrFail();
        $this->assertSame($timeoutKind, $timedOutEvent->payload['timeout_kind']);
        $this->assertSame($execution->id, $timedOutEvent->payload['activity_execution_id']);
        $this->assertStringContainsString($messageFragment, $timedOutEvent->payload['message']);

        $failedEvent = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->firstOrFail();
        $this->assertSame('activity_execution', $failedEvent->payload['source_kind']);
        $this->assertSame($execution->id, $failedEvent->payload['source_id']);
        $this->assertSame($timedOutEvent->payload['failure_id'], $failedEvent->payload['failure_id']);
        $this->assertStringContainsString($messageFragment, $failedEvent->payload['message']);
    }
}
