<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestTimeoutCleanupCompensationWorkflow;
use Tests\Fixtures\V2\TestTimeoutCleanupSameTurnWorkflow;
use Tests\Fixtures\V2\TestTimeoutCleanupSelectionWorkflow;
use Tests\Fixtures\V2\TestTimeoutCleanupSignalWorkflow;
use Tests\Fixtures\V2\TestTimeoutCleanupWorkflow;
use Tests\Support\WorkerLossControl;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\SignalWaits;
use Workflow\V2\Support\WorkflowTaskLeaseLoss;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * Deadline-triggered cleanup end to end (#863, contract vectors 1-3, 11).
 */
final class V2TimeoutCleanupTest extends TestCase
{
    use WorkerLossControl;

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
        // Armed, not entered: nothing to report yet.
        $this->assertVisibleTimeoutCleanup(WorkflowRun::query()->findOrFail($runId), null);

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
        $this->assertVisibleTimeoutCleanup($run, $terminal->payload['timeout_cleanup']);
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
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(RunStatus::Terminated, $run->status);
        $this->assertVisibleTimeoutCleanup($run, $terminal->payload['timeout_cleanup']);
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
        $this->assertVisibleTimeoutCleanup(WorkflowRun::query()->findOrFail($runId), null);
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
        $visible = [
            'outcome' => 'running',
            'cause' => 'timeout',
            'timeout_kind' => 'execution',
            'started_at' => $started->payload['detected_at'],
            'work_deadline_at' => $started->payload['work_deadline_at'],
            'cleanup_deadline_at' => $started->payload['cleanup_deadline_at'],
        ];
        $this->assertVisibleTimeoutCleanup($run, $visible);

        $this->newResumeTask($run);
        $this->runReadyWorkflowTask($run->id);
        TaskWatchdog::runPass(runIds: [$run->id]);
        $this->assertSame(1, WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)->count());
        $this->assertTrue($run->refresh()->cancellation_deadline_at->equalTo($deadline));
        $this->assertVisibleTimeoutCleanup($run, $visible);
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
        $this->assertVisibleTimeoutCleanup($run->refresh(), $terminal->payload['timeout_cleanup']);
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
        $this->assertVisibleTimeoutCleanup(
            WorkflowRun::query()->findOrFail($runId),
            $terminal->payload['timeout_cleanup']
        );
        $count = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->assertSame($count, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function testASummaryColumnRollbackKeepsProjectingWithoutTheListValue(): void
    {
        [, $run] = $this->enterCleanupAt(61);
        $this->assertTrue(WorkflowTaskLeaseLoss::summaryHasColumn('timeout_cleanup'));

        // The process still believes the summary column exists.
        Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
            $table->dropColumn('timeout_cleanup');
        });

        try {
            $summary = RunSummaryProjector::project($run->refresh());
            $this->assertSame($run->id, $summary->id);
            $this->assertFalse(WorkflowTaskLeaseLoss::summaryHasColumn('timeout_cleanup'));
            $item = $this->app->make(OperatorObservabilityRepository::class)
                ->listItem(WorkflowRunSummary::query()->findOrFail($run->id));
            $this->assertNull($item['timeout_cleanup']);
            // Describe still reads history.
            $this->assertSame('running', $this->app->make(WorkflowControlPlane::class)
                ->describe($run->workflow_instance_id, [
                    'run_id' => $run->id,
                ])['run']['timeout_cleanup']['outcome'] ?? null);
        } finally {
            Schema::table('workflow_run_summaries', static function (Blueprint $table): void {
                $table->json('timeout_cleanup')
                    ->nullable();
            });
            WorkflowTaskLeaseLoss::summaryHasColumn('timeout_cleanup', true);
        }
    }

    public function testExpiryWhileWaitingOnASignalInterruptsTheWaitAndALateSignalNeverApplies(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupSignalWorkflow::class, 'timeout-cleanup-signal-wait');
        $workflow->start(new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $opened = $this->soleEvent($runId, HistoryEventType::SignalWaitOpened);
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($runId)->status);

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);

        // The interruption reached workflow code at the signal wait itself.
        $run = WorkflowRun::query()->findOrFail($runId);
        $started = $this->assertTimeoutInterruptionDelivered($workflow, $run, 'signal', $opened->payload['sequence']);

        // Delivery ended the wait: it is reported as interrupted, not open.
        $waitId = $opened->payload['signal_wait_id'];
        $this->assertInterruptedSignalWait($runId, $waitId);

        // A signal that arrives during cleanup is recorded but never satisfies
        // the interrupted wait. No wait is open, so it is buffered under its
        // own command identity, as any signal without an open wait is, and
        // it schedules no workflow task of its own.
        $late = $workflow->refresh()
            ->attemptSignal('approve', 'late');
        $this->assertTrue($late->accepted());
        $bufferedWaitId = SignalWaits::bufferedWaitIdForCommandId($late->commandId());
        $received = $this->soleEvent($runId, HistoryEventType::SignalReceived);
        $this->assertSame($late->commandId(), $received->payload['workflow_command_id']);
        $this->assertSame($bufferedWaitId, $received->payload['signal_wait_id']);
        $this->assertSame(0, WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->count());
        $this->assertInterruptedSignalWait($runId, $waitId);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SignalApplied));
        $this->assertArrayNotHasKey('business', $workflow->refresh()->memo());
        $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());

        // The cleanup's workflow task runs with the signal pending and still
        // does not apply it.
        $this->runReadyActivityTask($runId);
        $this->runReadyWorkflowTask($runId);

        $this->assertTimedOutAfterCleanup($workflow, $runId, $started, 'completed');
        $rejected = $this->soleEvent($runId, HistoryEventType::SignalRejected);
        $this->assertSame($late->commandId(), $rejected->payload['workflow_command_id']);
        $this->assertSame('run_closed_before_signal_applied', $rejected->payload['rejection_reason']);
        $this->assertSame($bufferedWaitId, $rejected->payload['signal_wait_id']);
        $this->assertInterruptedSignalWait($runId, $waitId);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SignalApplied));
        $this->assertArrayNotHasKey('business', $workflow->refresh()->memo());
        $this->assertSame('Hello, cleanup!', $workflow->memo()['cleanup']);

        // After the run closes, a signal is refused outright.
        $closed = $workflow->refresh()
            ->attemptSignal('approve', 'after-close');
        $this->assertTrue($closed->rejected());
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SignalApplied));
    }

    public function testExpiryWhileWaitingOnASelectionCancelsEveryBranchAndNoBranchCommits(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupSelectionWorkflow::class, 'timeout-cleanup-selection-wait');
        $workflow->start(new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $opened = $this->soleEvent($runId, HistoryEventType::SignalWaitOpened);
        $this->assertSame('select', $opened->payload['parallel_group_mode']);
        $scheduled = $this->soleEvent($runId, HistoryEventType::ActivityScheduled);

        // The selection's activity branch is already running on a worker.
        $activities = $this->app->make(ActivityTaskBridge::class);
        $workTask = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->sole();
        $claim = $activities->claimStatus($workTask->id, 'business-worker');
        $this->assertTrue($claim['claimed']);

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $this->runReadyWorkflowTask($runId);

        $run = WorkflowRun::query()->findOrFail($runId);
        $started = $this->assertTimeoutInterruptionDelivered(
            $workflow,
            $run,
            'parallel',
            $opened->payload['parallel_group_base_sequence'],
        );
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SelectionResolved));

        // The running branch was cancelled under the timeout request, and its
        // worker can neither keep going nor record a late result.
        $cancelled = $this->soleEvent($runId, HistoryEventType::ActivityCancelled);
        $this->assertSame($scheduled->payload['activity_execution_id'], $cancelled->payload['activity_execution_id']);
        $this->assertSame($started->payload['workflow_command_id'], $cancelled->payload['workflow_command_id']);
        $heartbeat = $activities->heartbeat($claim['activity_attempt_id']);
        $this->assertFalse($heartbeat['can_continue']);
        $this->assertTrue($heartbeat['cancel_requested']);
        $outcome = $activities->complete($claim['activity_attempt_id'], Serializer::serialize('late-work'));
        $this->assertFalse($outcome['recorded']);

        // The signal branch can't win the selection after cleanup started:
        // its wait ended at delivery, and a late signal is buffered.
        $waitId = $opened->payload['signal_wait_id'];
        $this->assertInterruptedSignalWait($runId, $waitId);
        $late = $workflow->refresh()
            ->attemptSignal('approve', 'late');
        $this->assertTrue($late->accepted());
        $this->assertSame(
            SignalWaits::bufferedWaitIdForCommandId($late->commandId()),
            $this->soleEvent($runId, HistoryEventType::SignalReceived)->payload['signal_wait_id'],
        );
        $this->runReadyWorkflowTaskIfAny($runId);
        $this->assertInterruptedSignalWait($runId, $waitId);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SignalApplied));
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SelectionResolved));

        $this->runReadyActivityTask($runId);
        $this->runReadyWorkflowTask($runId);

        $this->assertTimedOutAfterCleanup($workflow, $runId, $started, 'completed');
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SelectionResolved));
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::SignalApplied));
        $this->assertSame(
            'run_closed_before_signal_applied',
            $this->soleEvent($runId, HistoryEventType::SignalRejected)->payload['rejection_reason'],
        );
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->where('payload->activity_execution_id', $scheduled->payload['activity_execution_id'])
            ->count());
        $this->assertArrayNotHasKey('business', $workflow->refresh()->memo());
        $this->assertSame('Hello, cleanup!', $workflow->memo()['cleanup']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function resultsAtTheWorkDeadline(): iterable
    {
        yield 'timer fires at the work deadline' => ['timer'];
        yield 'activity completes after the work deadline' => ['activity'];
    }

    /**
     * Contract: a result that is in history before cleanup starts wins the
     * cooperative delivery, as for any cooperative cancellation. The workflow
     * returns in the same task in which the engine notices the work deadline,
     * so cleanup is entered once and the run still ends timed out with
     * outcome completed. The business result is discarded, and the cleanup
     * branch never runs, because no interruption was delivered.
     */
    #[DataProvider('resultsAtTheWorkDeadline')]
    public function testCompletingInTheTaskThatNoticesTheWorkDeadlineEndsTimedOut(string $wait): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupSameTurnWorkflow::class, 'timeout-cleanup-same-turn-' . $wait);
        $workflow->start($wait, 60, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $workDeadline = WorkflowRun::query()->findOrFail($runId)->timeout_cleanup_work_deadline_at;

        if ($wait === 'timer') {
            $this->travel(60)
                ->seconds();
            $this->assertTrue(now()->equalTo($workDeadline));
            $this->runReadyTask($runId, TaskType::Timer, static fn (string $id): RunTimerTask => new RunTimerTask($id));
        } else {
            $this->travel(61)
                ->seconds();
            $this->runReadyActivityTask($runId);
        }

        // The result is in history and nothing has noticed the deadline yet.
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertFalse($run->status->isTerminal());
        $this->assertNull($run->cancellation_request_command_id);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::WorkflowTimeoutCleanupStarted));

        $this->runReadyWorkflowTask($runId);

        $run = WorkflowRun::query()->findOrFail($runId);
        $started = $this->soleEvent($runId, HistoryEventType::WorkflowTimeoutCleanupStarted);
        $this->assertSame(now()->toIso8601String(), $started->payload['detected_at']);
        $this->assertSame($run->execution_deadline_at->toIso8601String(), $started->payload['cleanup_deadline_at']);
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertTimedOutAfterCleanup($workflow, $runId, $started, 'completed');
        $this->assertSame([], $workflow->refresh()->memo());
    }

    public function testAnActivityResultRecordedAfterCleanupStartsIsDiscardedForTheInterruption(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupSameTurnWorkflow::class, 'timeout-cleanup-late-result');
        $workflow->start('activity', 60, new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);

        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $started = $this->soleEvent($runId, HistoryEventType::WorkflowTimeoutCleanupStarted);
        $this->travel(1)
            ->seconds();
        $this->runReadyActivityTask($runId);
        $completed = $this->soleEvent($runId, HistoryEventType::ActivityCompleted);
        $this->assertGreaterThan($started->sequence, $completed->sequence);

        $this->runReadyWorkflowTaskIfAny($runId);
        $this->runReadyWorkflowTaskIfAny($runId);

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertTimeoutInterruptionDelivered($workflow, $run, 'activity', $completed->payload['sequence']);
        $this->assertTimedOutAfterCleanup($workflow, $runId, $started, 'completed');
    }

    public function testABoundedProjectionAfterACleanupActivityKeepsTheListedValue(): void
    {
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(TestTimeoutCleanupCompensationWorkflow::class, 'timeout-cleanup-bounded');
        $workflow->start(new StartOptions(executionTimeoutSeconds: 70, timeoutCleanupSeconds: 10));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(61)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        // The cleanup turn schedules the compensation activity.
        $this->runReadyWorkflowTask($runId);
        $started = $this->soleEvent($runId, HistoryEventType::WorkflowTimeoutCleanupStarted);
        $running = [
            'outcome' => 'running',
            'cause' => 'timeout',
            'timeout_kind' => 'execution',
            'started_at' => $started->payload['detected_at'],
            'work_deadline_at' => $started->payload['work_deadline_at'],
            'cleanup_deadline_at' => $started->payload['cleanup_deadline_at'],
        ];
        $this->assertVisibleTimeoutCleanup(WorkflowRun::query()->findOrFail($runId), $running);
        $before = WorkflowRunSummary::query()->findOrFail($runId);

        // The activity outcome opens the resume workflow task, and the bounded
        // open-workflow-task projection is the summary's last writer. It
        // writes only the task, wait and history columns.
        $this->runReadyActivityTask($runId);
        $completed = $this->soleEvent($runId, HistoryEventType::ActivityCompleted);
        $after = WorkflowRunSummary::query()->findOrFail($runId);
        $resume = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->assertSame($resume->id, $after->next_task_id);
        $this->assertSame((int) $completed->sequence, (int) $after->history_event_count);
        $this->assertGreaterThan((int) $before->history_event_count, (int) $after->history_event_count);
        $this->assertVisibleTimeoutCleanup(WorkflowRun::query()->findOrFail($runId), $running);

        $this->runReadyWorkflowTask($runId);
        $this->assertTimedOutAfterCleanup($workflow, $runId, $started, 'completed');
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function replacementWorkers(): iterable
    {
        yield 'replacement resumes inside the cleanup window' => [false, 'completed'];
        yield 'replacement first runs after the hard deadline' => [true, 'expired'];
    }

    /**
     * Contract vector 6 under real worker loss: the process holding the
     * cleanup turn dies by SIGKILL before that turn commits, and a fresh
     * process finishes the run from persisted history.
     */
    #[DataProvider('replacementWorkers')]
    public function testAWorkerKilledMidCleanupIsReplacedFromHistoryWithoutRestartingCleanup(
        bool $afterHardDeadline,
        string $outcome,
    ): void {
        $this->requireWorkerLossSupport();
        $this->travelTo(now()->startOfSecond());
        $workflow = WorkflowStub::make(
            TestTimeoutCleanupCompensationWorkflow::class,
            'timeout-cleanup-worker-loss-' . $outcome,
        );
        // The cleanup window outlasts one abandoned workflow-task lease.
        $workflow->start(new StartOptions(executionTimeoutSeconds: 3600, timeoutCleanupSeconds: 1200));
        $runId = $workflow->runId();
        $this->runReadyWorkflowTask($runId);
        $this->travel(2401)
            ->seconds();
        TaskWatchdog::runPass(runIds: [$runId]);
        $started = $this->soleEvent($runId, HistoryEventType::WorkflowTimeoutCleanupStarted);
        $run = WorkflowRun::query()->findOrFail($runId);
        $cleanupDeadline = $run->cancellation_deadline_at;
        $this->assertTrue($cleanupDeadline->equalTo($run->execution_deadline_at));

        [$cut, $resumed] = $this->loseWorkerThenRecover(
            'tests/Support/TimeoutCleanupProcessLossProcessTest.php',
            'DWF_TIMEOUT_CLEANUP_PROCESS_INPUT',
            [
                'run_id' => $runId,
                'clock' => now()
                    ->toISOString(),
            ],
            function (array $cut, array $data) use ($runId, $run, $afterHardDeadline): array {
                // The dead worker still holds the cleanup turn, and nothing it
                // did inside that turn committed.
                $task = WorkflowTask::query()->findOrFail($cut['task_id']);
                $this->assertSame(TaskStatus::Leased, $task->status);
                $this->assertSame($cut['lease_owner'], $task->lease_owner);
                $this->assertFalse(WorkflowRun::query()->findOrFail($runId)->status->isTerminal());
                $this->assertSame(1, $this->eventCount($runId, HistoryEventType::WorkflowTimeoutCleanupStarted));
                $this->assertSame(1, $this->eventCount($runId, HistoryEventType::ActivityCompleted));
                $this->assertSame(0, $this->eventCount($runId, HistoryEventType::MemoUpserted));
                $resumeAt = $afterHardDeadline
                    ? $run->execution_deadline_at->copy()
                        ->addSecond()
                    : Carbon::parse($cut['lease_expires_at'])->addSecond();
                $this->assertSame(! $afterHardDeadline, $resumeAt->lt($run->execution_deadline_at));

                return [
                    ...$data,
                    'clock' => $resumeAt->toISOString(),
                ];
            },
        );
        Carbon::setTestNow($resumed['clock']);

        // One cleanup transition with its original deadline; the completed
        // compensation was replayed, not repeated.
        $this->assertSame(
            $started->id,
            $this->soleEvent($runId, HistoryEventType::WorkflowTimeoutCleanupStarted)->id
        );
        $run->refresh();
        $this->assertTrue($run->cancellation_deadline_at->equalTo($cleanupDeadline));
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::CooperativeCancellationDelivered));
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::ActivityScheduled));
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::ActivityCompleted));
        $this->assertTimedOutAfterCleanup($workflow, $runId, $started, $outcome);
        $this->assertSame($cleanupDeadline->toIso8601String(), $started->payload['cleanup_deadline_at']);
        if ($outcome === 'completed') {
            $this->assertSame([
                'cause' => 'timeout',
                'compensation' => 'Hello, compensation!',
            ], $this->sorted($workflow->refresh()->memo()));
            $this->assertSame(1, $this->eventCount($runId, HistoryEventType::MemoUpserted));
        } else {
            $this->assertSame([], $workflow->refresh()->memo());
            $this->assertSame(0, $this->eventCount($runId, HistoryEventType::MemoUpserted));
        }
    }

    /**
     * Cleanup was entered once for this run, and workflow code received the
     * typed timeout interruption at the given wait with the original
     * identities (#863).
     */
    private function assertTimeoutInterruptionDelivered(
        WorkflowStub $workflow,
        WorkflowRun $run,
        string $callKind,
        int $sequence,
    ): WorkflowHistoryEvent {
        $started = $this->soleEvent($run->id, HistoryEventType::WorkflowTimeoutCleanupStarted);
        $requestId = $started->payload['workflow_command_id'];
        $this->assertSame($requestId, $run->cancellation_request_command_id);
        $this->assertSame('execution', $started->payload['timeout_kind']);
        $this->assertSame($run->execution_deadline_at->toIso8601String(), $started->payload['cleanup_deadline_at']);
        $this->assertTrue($run->cancellation_deadline_at->equalTo($run->execution_deadline_at));
        $this->assertSame(
            $requestId,
            $this->soleEvent($run->id, HistoryEventType::CooperativeCancellationRequested)->workflow_command_id,
        );

        $delivered = $this->soleEvent($run->id, HistoryEventType::CooperativeCancellationDelivered);
        $this->assertSame($requestId, $delivered->payload['workflow_command_id']);
        $this->assertSame($callKind, $delivered->payload['call_kind']);
        $this->assertSame($sequence, $delivered->payload['sequence']);
        $this->assertSame('timeout', $delivered->payload['cancellation']['cause']);

        $this->assertSame([
            'cause' => 'timeout',
            'class' => WorkflowTimeoutCleanupRequested::class,
            'cleanup_deadline_at' => $started->payload['cleanup_deadline_at'],
            'parent_request_id' => null,
            'request_id' => $requestId,
            'root_request_id' => $requestId,
            'root_workflow_instance_id' => $run->workflow_instance_id,
            'root_workflow_run_id' => $run->id,
        ], $this->sorted($workflow->refresh()->memo()['interruption'] ?? null));

        // Still the original instance and run: no new run carries cleanup.
        $this->assertSame($run->id, $workflow->runId());
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $run->workflow_instance_id)->count());

        return $started;
    }

    private function assertTimedOutAfterCleanup(
        WorkflowStub $workflow,
        string $runId,
        WorkflowHistoryEvent $started,
        string $outcome,
    ): void {
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('timed_out', $run->closed_reason);
        $this->assertNull($run->output);
        $this->assertNull($workflow->refresh()->output());
        $this->assertSame(0, $this->eventCount($runId, HistoryEventType::WorkflowCompleted));
        $this->assertSame(1, $this->eventCount($runId, HistoryEventType::WorkflowTimeoutCleanupStarted));
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc('sequence')
            ->where('event_type', HistoryEventType::WorkflowTimedOut->value)
            ->sole();
        $this->assertSame('execution_timeout', $terminal->payload['timeout_kind']);
        $this->assertSame($outcome, $terminal->payload['timeout_cleanup']['outcome']);
        $this->assertSame($started->payload['detected_at'], $terminal->payload['timeout_cleanup']['started_at']);
        $this->assertSame(
            $started->payload['cleanup_deadline_at'],
            $terminal->payload['timeout_cleanup']['cleanup_deadline_at'],
        );
        // Describe, observation, detail, the summary and the list item agree.
        $this->assertVisibleTimeoutCleanup($run, $terminal->payload['timeout_cleanup']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sorted(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }
        ksort($values);

        return $values;
    }

    /**
     * The derived wait views report a wait interrupted by cancellation
     * delivery as ended there, never as received or resolved.
     */
    private function assertInterruptedSignalWait(string $runId, string $waitId): void
    {
        $run = WorkflowRun::query()->findOrFail($runId);
        $wait = collect(SignalWaits::forRun($run))->firstWhere('signal_wait_id', $waitId);
        $this->assertIsArray($wait);
        $this->assertSame('cancelled', $wait['status']);
        $this->assertSame('cancellation_delivered', $wait['source_status']);
        $this->assertNull($wait['signal_id']);
        $this->assertNull($wait['command_id']);
        $this->assertNotSame($waitId, SignalWaits::openWaitIdForName($run, 'approve'));

        $detail = collect(RunDetailView::forRun($run->fresh())['waits'])
            ->first(static fn (array $wait): bool => ($wait['kind'] ?? null) === 'signal'
                && ($wait['id'] ?? null) === $waitId);
        $this->assertIsArray($detail);
        $this->assertSame('cancelled', $detail['status']);
        $this->assertSame('cancellation_delivered', $detail['source_status']);
        $this->assertNull($detail['command_id']);
    }

    private function soleEvent(string $runId, HistoryEventType $type): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $type->value)
            ->sole();
    }

    private function eventCount(string $runId, HistoryEventType $type): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->where('event_type', $type->value)
            ->count();
    }

    private function runReadyActivityTask(string $runId): void
    {
        $this->runReadyTask(
            $runId,
            TaskType::Activity,
            static fn (string $id): RunActivityTask => new RunActivityTask($id)
        );
    }

    /**
     * @param \Closure(string): object $job
     */
    private function runReadyTask(string $runId, TaskType $type, \Closure $job): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([$job($task->id), 'handle']);
    }

    /**
     * @param array<string, string>|null $expected
     */
    private function assertVisibleTimeoutCleanup(WorkflowRun $run, ?array $expected): void
    {
        $controlPlane = $this->app->make(WorkflowControlPlane::class);
        $observer = $this->app->make(OperatorObservabilityRepository::class);
        $description = $controlPlane->describe($run->workflow_instance_id, [
            'run_id' => $run->id,
        ]);
        $this->assertTrue($description['found']);

        foreach ([$description['run'], $observer->runObservation($run), $observer->runDetail($run)] as $surface) {
            if ($expected === null) {
                $this->assertArrayNotHasKey('timeout_cleanup', $surface);
            } else {
                $this->assertSame($expected, $surface['timeout_cleanup'] ?? null);
            }
        }

        // Run lists: the projected summary (Waterline serializes the model)
        // and the list item carry the same value, null when none. A JSON
        // column may reorder keys, so only the members are compared.
        $summary = WorkflowRunSummary::query()->findOrFail($run->id);
        $item = $observer->listItem($summary);
        $this->assertArrayHasKey('timeout_cleanup', $item);
        foreach ([
            $summary->timeout_cleanup,
            $summary->toArray()['timeout_cleanup'],
            $item['timeout_cleanup'],
        ] as $listed) {
            if ($expected === null) {
                $this->assertNull($listed);
            } else {
                $this->assertIsArray($listed);
                $this->assertEquals($expected, $listed);
            }
        }
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
