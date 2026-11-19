<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestTimeoutCleanupWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * Deadline-triggered cleanup end to end (#863, contract vectors 1-3, 11).
 */
final class V2TimeoutCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('queue.default', 'redis');
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'cleanup completes' => ['complete', 0, 'completed'];
        yield 'cleanup rethrows the interruption' => ['rethrow', 0, 'completed'];
        yield 'cleanup throws' => ['fail', 0, 'failed'];
        yield 'cleanup outlives the hard deadline' => ['expire', 11, 'expired'];
    }

    #[DataProvider('outcomes')]
    public function testAnIdleRunIsWokenAtItsWorkDeadlineAndEndsTimedOut(
        string $mode,
        int $extraSeconds,
        string $outcome,
    ): void {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-' . $mode);
        $workflow->start($mode, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($runId)->status);

        // Past the work deadline, before the hard deadline: the watchdog wakes it.
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);

        if ($extraSeconds > 0) {
            $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());
            $this->travel($extraSeconds)
                ->seconds();
            TaskWatchdog::runPass(runIds: [$runId]);
            $this->runReadyWorkflowTaskIfAny($runId);
        }

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);

        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $started = $events->firstWhere('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted);
        $this->assertNotNull($started);
        $this->assertSame('execution', $started->payload['timeout_kind']);
        $terminal = $events->last();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame($outcome, $terminal->payload['timeout_cleanup']['outcome']);
        $this->assertSame('execution_timeout', $terminal->payload['timeout_kind']);
        $this->assertSame('timeout', $terminal->payload['failure_category']);
        if ($outcome === 'failed') {
            $this->assertIsString($terminal->payload['timeout_cleanup']['failure_id'] ?? null);
        }
        // Describe, the export and the failure list read this message.
        $expected = [
            'completed' => 'Timeout cleanup completed.',
            'failed' => 'Timeout cleanup failed.',
            'expired' => 'Timeout cleanup did not finish before the hard deadline.',
        ][$outcome];
        $this->assertStringEndsWith($expected, $terminal->payload['message']);
        $this->assertStringEndsWith($expected, \Workflow\V2\Models\WorkflowFailure::query()
            ->findOrFail($terminal->payload['failure_id'])->message);
    }

    public function testTerminatingDuringCleanupPreemptsIt(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-preempted');
        $workflow->start('expire', new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);
        $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());

        $workflow->refresh()
            ->terminate('operator stop');

        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowTerminated, $terminal->event_type);
        $this->assertSame('preempted', $terminal->payload['timeout_cleanup']['outcome'] ?? null);
        $this->assertSame(RunStatus::Terminated, WorkflowRun::query()->findOrFail($runId)->status);
    }

    public function testWithoutTheOptionTheHardTimeoutIsUnchanged(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-off');
        $workflow->start('complete', new StartOptions(executionTimeoutSeconds: 70));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready->value)
            ->where('task_type', TaskType::Workflow->value)
            ->count());

        $this->travel(10)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTaskIfAny($runId);

        $events = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get();
        $this->assertNull($events->firstWhere('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted));
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $events->last()->event_type);
        $this->assertArrayNotHasKey('timeout_cleanup', $events->last()->payload);
    }

    public function testTaskCompletionAtTheHardDeadlineRecordsExpiredCleanup(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-task-expired');
        $workflow->start('expire', new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);

        $run = WorkflowRun::query()->findOrFail($runId);
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $runId,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'payload' => [
                'reason' => 'cleanup_resume',
            ],
        ]);
        $bridge = $this->app->make(WorkflowTaskBridge::class);
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'leased_at' => now(),
            'lease_expires_at' => now()
                ->addSeconds(60),
            'lease_owner' => 'old-worker',
        ])->save();
        $this->travel(9)
            ->seconds();
        $result = $bridge->complete($task->id, [[
            'type' => 'complete_workflow',
            'result' => 'late',
        ]]);
        $this->assertFalse($result['completed']);
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame('expired', $terminal->payload['timeout_cleanup']['outcome'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function replayErrors(): iterable
    {
        yield 'version range' => ['version-mismatch'];
        yield 'history shape' => ['history-mismatch'];
        yield 'unresolved failure' => ['unresolved-failure'];
    }

    #[DataProvider('replayErrors')]
    public function testReplayErrorsDuringCleanupRemainRepairable(string $mode): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-' . $mode);
        $workflow->start($mode, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);

        $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowTimedOut->value)->count());
    }

    public function testLateDetectionAndReplacementDoNotRenewOrRestartCleanup(): void
    {
        [$workflow, $run] = $this->enterCleanupAt(67);
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)->sole();
        $this->assertSame(now()->toIso8601String(), $started->payload['detected_at']);
        $this->assertSame($run->execution_deadline_at->toIso8601String(), $started->payload['cleanup_deadline_at']);
        $deadline = $run->cancellation_deadline_at;

        $this->newResumeTask($run);
        $this->runReadyWorkflowTask($run->id);
        TaskWatchdog::runPass(runIds: [$run->id]);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)->count());
        $this->assertTrue($run->refresh()->cancellation_deadline_at->equalTo($deadline));
    }

    public function testTheEarliestHardDeadlineOwnsCleanup(): void
    {
        [$workflow, $run] = $this->enterCleanupAt(30, new StartOptions(
            executionTimeoutSeconds: 70,
            runTimeoutSeconds: 40,
            timeoutCleanupSeconds: 10,
        ));
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)->sole();
        $this->assertSame('run', $started->payload['timeout_kind']);
        $this->assertSame($run->run_deadline_at->toIso8601String(), $started->payload['cleanup_deadline_at']);
    }

    public function testUserCancellationIsAuditedWithoutReplacingTheTimeoutRequest(): void
    {
        [$workflow, $run] = $this->enterCleanupAt(61);
        $requestId = $run->cancellation_request_command_id;
        $deadline = $run->cancellation_deadline_at;
        $result = $workflow->refresh()
            ->attemptRequestCancellation('operator request', 120);
        $this->assertTrue($result->accepted());
        $this->assertNotSame($requestId, $result->commandId());
        $this->assertSame($requestId, $result->payloadValues(['existing_request_id'])['existing_request_id']);
        $this->assertSame('operator request', $result->reason());
        $this->assertSame($requestId, $run->refresh()->cancellation_request_command_id);
        $this->assertTrue($run->cancellation_deadline_at->equalTo($deadline));
    }

    public function testHardCancelPreemptsCleanup(): void
    {
        [$workflow, $run] = $this->enterCleanupAt(61);
        $workflow->refresh()
            ->cancel('operator stop');
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowCancelled, $terminal->event_type);
        $this->assertSame('preempted', $terminal->payload['timeout_cleanup']['outcome'] ?? null);
    }

    public function testEarlierCooperativeCancellationIsPreemptedWhenTheHardDeadlineWins(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-earlier-cancel');
        $workflow->start('expire', new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(20)
            ->seconds();
        $workflow->refresh()
            ->requestCancellation('earlier request', 120);
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertTrue($run->cancellation_deadline_at->equalTo($run->execution_deadline_at));
        $this->travel(50)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame('preempted', $terminal->payload['cancellation_cleanup']['outcome'] ?? null);
        $this->assertFalse(WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)->exists());
    }

    public function testTimeoutCleanupListsAnAbandonedActivityAndAcceptsNoLateHistory(): void
    {
        [$workflow, $run] = $this->enterCleanupAt(61);
        $task = $this->newResumeTask($run);
        // Internal abandon lifetime, as in V2PortableCancellationDeliveryTest.
        // Public embedded authoring does not expose this policy yet.
        $execution = \Workflow\V2\Models\ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'activity_class' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'activity_type' => \Tests\Fixtures\V2\TestGreetingActivity::class,
            'status' => \Workflow\V2\Enums\ActivityStatus::Pending,
            'arguments' => \Workflow\Serializers\Serializer::serialize(['cleanup']),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'activity_options' => [
                'cancellation_policy' => 'abandon',
            ],
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [0],
                'schedule_to_close_timeout' => 180,
            ],
            'schedule_to_close_deadline_at' => now()
                ->addSeconds(180),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 3,
            'activity' => \Workflow\V2\Support\ActivitySnapshot::fromExecution($execution),
        ], $task);
        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Activity,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
        ]);
        $this->travel(9)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$run->id]);
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame('expired', $terminal->payload['timeout_cleanup']['outcome']);
        $this->assertEquals([[
            'activity_execution_id' => $execution->id,
            'sequence' => 3,
        ]], $terminal->payload['abandoned_activities']);
        $this->assertSame(\Workflow\V2\Enums\ActivityStatus::Pending, $execution->refresh()->status);
        $count = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count();
        $activities = $this->app->make(\Workflow\V2\Contracts\ActivityTaskBridge::class);
        $claim = $activities->claimStatus($activityTask->id, 'independent-cleanup-worker');
        $this->assertTrue($claim['claimed']);
        $this->assertTrue($activities->heartbeat($claim['activity_attempt_id'])['can_continue']);
        $outcome = $activities->complete(
            $claim['activity_attempt_id'],
            \Workflow\Serializers\Serializer::serialize('done')
        );
        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_closed', $outcome['reason']);
        $this->assertSame($count, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testAScopedChildOfATimeoutRootCancelsWithoutItsOwnTimeout(): void
    {
        [$parent, $parentRun] = $this->enterCleanupAt(61);
        $root = \Workflow\V2\Support\CooperativeCancellationDelivery::context($parentRun);
        $child = WorkflowStub::make(\Tests\Fixtures\V2\TestTimerWorkflow::class, 'timeout-cleanup-scoped-child');
        $child->start(3600);
        $childRun = WorkflowRun::query()->findOrFail($child->runId());
        $this->runReadyWorkflowTask($childRun->id);
        $requestId = (string) \Illuminate\Support\Str::ulid();
        $context = \Workflow\V2\CancellationContext::fromScopeContext(
            \Workflow\V2\ScopedCancellationContext::fromRunContext($root),
            $requestId,
            $childRun->workflow_instance_id,
            $childRun->id,
        );
        $command = \Workflow\V2\Models\WorkflowCommand::record($childRun->instance, $childRun, [
            'id' => $requestId,
            'command_type' => \Workflow\V2\Enums\CommandType::RequestCancellation,
            'target_scope' => 'run',
            'status' => \Workflow\V2\Enums\CommandStatus::Accepted,
            'outcome' => \Workflow\V2\Enums\CommandOutcome::CancellationRequested,
            'payload_codec' => 'avro',
            'payload' => \Workflow\Serializers\Serializer::serialize([
                'cancellation' => $context->toArray(),
            ]),
            'accepted_at' => now(),
            'applied_at' => now(),
        ]);
        WorkflowHistoryEvent::record($childRun, HistoryEventType::CooperativeCancellationRequested, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $childRun->workflow_instance_id,
            'workflow_run_id' => $childRun->id,
            'command_type' => 'request_cancellation',
            'reason' => $context->reason,
            'cleanup_deadline_at' => $context->deadline()
                ->toISOString(),
            'cancellation' => $context->toArray(),
        ], null, $command);
        $childRun->forceFill([
            'cancellation_request_command_id' => $command->id,
            'cancellation_requested_at' => now(),
            'cancellation_deadline_at' => $context->deadline(),
        ])->save();
        $this->newResumeTask($childRun);
        $this->runReadyWorkflowTask($childRun->id);
        $this->assertNull($childRun->refresh()->execution_deadline_at);
        $this->assertNull($childRun->run_deadline_at);
        $this->assertSame(RunStatus::Cancelled, $childRun->status);
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $childRun->id)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowCancelled, $terminal->event_type);
        $this->assertArrayNotHasKey('timeout_cleanup', $terminal->payload);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function unavailableWorkers(): iterable
    {
        yield 'detected during the cleanup window' => [false];
        yield 'first detected after the hard deadline' => [true];
    }

    #[DataProvider('unavailableWorkers')]
    public function testUnavailableWorkersCannotKeepAnOptedInRunOpen(bool $lateDetection): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-no-worker');
        $workflow->start('expire', new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        // Leave even the initial task Ready: no worker ever executes it.
        $this->travel($lateDetection ? 71 : 61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        if (! $lateDetection) {
            $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());
            $this->travel(9)
                ->seconds();
            TaskWatchdog::runPass(runIds: [$runId]);
        }
        $this->assertSame(RunStatus::Failed, WorkflowRun::query()->findOrFail($runId)->status);
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc(
            'sequence'
        )->firstOrFail();
        $this->assertSame(HistoryEventType::WorkflowTimedOut, $terminal->event_type);
        $this->assertSame('expired', $terminal->payload['timeout_cleanup']['outcome'] ?? null);
        $count = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->assertSame($count, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    /**
     * @return array{WorkflowStub, WorkflowRun}
     */
    private function enterCleanupAt(int $seconds, ?StartOptions $options = null): array
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupWorkflow::class, 'timeout-cleanup-boundary');
        $workflow->start(
            'expire',
            $options ?? new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10)
        );
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel($seconds)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);
        return [$workflow, WorkflowRun::query()->findOrFail($runId)];
    }

    private function newResumeTask(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'payload' => [
                'reason' => 'cleanup_resume',
            ],
        ]);
    }

    private function runReadyWorkflowTask(string $runId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function runReadyWorkflowTaskIfAny(string $runId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->first();
        if ($task !== null) {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        }
    }
}
