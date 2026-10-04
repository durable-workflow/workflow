<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityAbandonment;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\ActivityCancellationAcknowledgement;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\LocalActivityCall;
use Workflow\V2\Support\LocalActivityExecutor;
use Workflow\V2\Support\LocalActivityRuntime;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;
use Workflow\V2\Testing\ActivityFakeContext;
use Workflow\V2\WorkflowStub;

final class V2PortableCancellationDeliveryTest extends TestCase
{
    private DefaultWorkflowTaskBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        $this->bridge = $this->app->make(WorkflowTaskBridge::class);
    }

    public function testOptionalCooperativeBridgeUsesTheExistingBinding(): void
    {
        $this->assertSame($this->bridge, $this->app->make(CooperativeWorkflowTaskBridge::class));
    }

    #[DataProvider('portableRemoteActivityPolicies')]
    public function testPortableRemoteActivityPolicySurvivesAdmissionAndControlsCancellation(?string $policy): void
    {
        [$run, $initial] = $this->newRun();
        $command = [
            'type' => 'schedule_activity',
            'activity_type' => 'tests.portable-remote',
            'arguments' => Serializer::serialize(['Taylor']),
            'schedule_to_close_timeout' => 180,
        ];
        if ($policy !== null) {
            $command['cancellation_policy'] = $policy;
        }
        $commands = WorkflowCommandNormalizer::normalize([$command], '1.20');
        $this->assertTrue($this->bridge->complete($initial->id, $commands)['completed']);
        $execution = $run->activityExecutions()
            ->sole();
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)->sole();
        $this->assertSame($policy, $scheduled->payload['activity']['cancellation_policy'] ?? null);
        $this->assertSame($policy, $execution->activity_options['cancellation_policy'] ?? null);
        $originalActivityDeadline = $execution->schedule_to_close_deadline_at->toISOString();
        if ($policy === 'abandon') {
            $this->assertSame(
                $originalActivityDeadline,
                $scheduled->payload['activity']['schedule_to_close_deadline_at']
            );
        }
        $activityTask = $run->tasks()
            ->where('task_type', TaskType::Activity)->sole();
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'remote-owner')['claimed']);
        $attempt = $execution->attempts()
            ->sole();
        $this->request($run);
        $originalCleanupDeadline = $run->cancellation_deadline_at->toISOString();
        $resume = $this->leaseReadyTask($run);
        $delivery = $this->deliver($run, $resume);
        if ($policy === 'wait_cancellation_completed') {
            $this->assertFalse($delivery['delivered']);
            $this->assertTrue($delivery['claim_released']);
            $this->assertSame('cancellation_waiting_for_activity', $delivery['reason']);
            $this->assertSame(0, $this->deliveryCount($run));
            $receipt = ActivityCancellationAcknowledgement::recordStopped(
                $attempt->id,
                'remote-owner',
                $run->cancellation_request_command_id,
            );
            $this->assertTrue($receipt['acknowledged']);
            $resume = $this->leaseReadyTask($run);
            $delivery = $this->deliver($run, $resume);
        }
        $this->assertTrue($delivery['delivered']);
        $this->assertSame(1, $this->deliveryCount($run));
        $this->assertSame($originalCleanupDeadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertTrue($this->bridge->complete($resume->id, [[
            'type' => 'complete_workflow',
        ]])['completed']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(
            $originalActivityDeadline,
            $execution->refresh()
                ->schedule_to_close_deadline_at->toISOString()
        );
        $completion = $activities->complete($attempt->id, 'late callback result');
        $this->assertSame($policy === 'abandon', $completion['recorded']);
        $this->assertSame(
            $policy === 'abandon' ? ActivityStatus::Completed : ActivityStatus::Cancelled,
            $execution->refresh()
->status
        );
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(
            0,
            $run->tasks()
                ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->count()
        );
    }

    public static function portableRemoteActivityPolicies(): iterable
    {
        yield 'historical default' => [null];
        yield 'request and continue' => ['try_cancel'];
        yield 'wait for original callback stop' => ['wait_cancellation_completed'];
        yield 'bounded independent remote work' => ['abandon'];
    }

    #[DataProvider('invalidPortableRemoteActivityPolicies')]
    public function testPortableRemoteActivityPolicyRefusesInvalidBridgeInputBeforeScheduling(mixed $policy): void
    {
        [$run, $initial] = $this->newRun();
        $historyBefore = $run->historyEvents()
            ->count();
        $outcome = $this->bridge->complete($initial->id, [[
            'type' => 'schedule_activity',
            'activity_type' => 'tests.portable-remote',
            'cancellation_policy' => $policy,
        ]]);
        $this->assertFalse($outcome['completed']);
        $this->assertSame(0, $run->activityExecutions()->count());
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $this->assertSame($historyBefore, $run->historyEvents()->count());
        $this->assertSame(TaskStatus::Leased, $initial->refresh()->status);
    }

    public static function invalidPortableRemoteActivityPolicies(): iterable
    {
        yield 'unknown policy' => ['unknown'];
        yield 'non-string policy' => [[]];
        yield 'unbounded abandon' => ['abandon'];
    }

    public function testInternalActivityAbandonCompletesAfterParentClosureWithoutReopeningIt(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity($run, $initial);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'abandoned-owner')['claimed']);
        $attempt = $execution->attempts()
            ->sole();
        $activityDeadline = $execution->refresh()
            ->schedule_to_close_deadline_at->toISOString();
        $this->closeCooperativeParent($run, $initial);
        $closedAt = $run->closed_at->toISOString();
        $request = $run->cancellation_request_command_id;
        $cleanupDeadline = $run->cancellation_deadline_at->toISOString();
        $this->assertSame(ActivityStatus::Running, $execution->refresh()->status);
        $this->assertSame(TaskStatus::Leased, $activityTask->refresh()->status);
        $this->assertSame('detached_activity_still_open', WorkflowRunRetentionCleanup::retentionHoldReason($run));
        $historyCount = $run->historyEvents()
            ->count();
        try {
            WorkflowRunRetentionCleanup::pruneRun($run);
            $this->fail('An open detached activity must hold retention.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('detached_activity_still_open', $exception->getMessage());
        }
        $this->assertSame($historyCount, $run->historyEvents()->count());
        $this->assertNull($run->refresh()->details_pruned_at);
        try {
            Carbon::setTestNow($run->cancellation_deadline_at->copy()->addSecond());
            $control = $activities->heartbeat($attempt->id);
            $this->assertTrue($control['can_continue']);
            $this->assertFalse($control['cancel_requested']);
            $outcome = $activities->complete($attempt->id, 'independent result');
        } finally {
            Carbon::setTestNow();
        }
        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task_id']);
        $this->assertSame(ActivityStatus::Completed, $execution->refresh()->status);
        $this->assertSame($activityDeadline, $execution->schedule_to_close_deadline_at->toISOString());
        $this->assertSame('independent result', Serializer::unserialize($execution->result));
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCancelled)->count());
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame($closedAt, $run->closed_at->toISOString());
        $this->assertSame($request, $run->cancellation_request_command_id);
        $this->assertSame($cleanupDeadline, $run->cancellation_deadline_at->toISOString());
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Workflow)
            ->where('status', TaskStatus::Ready)->count());
        $this->assertNull(WorkflowRunRetentionCleanup::retentionHoldReason($run));
        $this->assertSame(1, WorkflowRunRetentionCleanup::pruneRun($run)['activity_executions_deleted']);
    }

    public function testInternalActivityAbandonRetriesWithItsOriginalBudgetAndRefusesStalePublication(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity($run, $initial, maxAttempts: 2);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'first-owner')['claimed']);
        $first = $execution->attempts()
            ->sole();
        $deadline = $execution->refresh()
            ->schedule_to_close_deadline_at->toISOString();
        $this->closeCooperativeParent($run, $initial);
        $retry = $activities->fail($first->id, [
            'class' => \RuntimeException::class,
            'message' => 'temporary failure',
        ]);
        $this->assertTrue($retry['recorded']);
        $retryTask = WorkflowTask::query()->findOrFail($retry['next_task_id']);
        $this->assertSame(TaskType::Activity, $retryTask->task_type);
        $this->assertSame(ActivityStatus::Pending, $execution->refresh()->status);
        $this->assertSame('detached_activity_still_open', WorkflowRunRetentionCleanup::retentionHoldReason($run));
        $this->assertTrue($activities->claimStatus($retryTask->id, 'replacement-owner')['claimed']);
        $second = $execution->attempts()
            ->where('attempt_number', 2)
            ->sole();
        $this->assertSame('stale_attempt', $activities->complete($first->id, 'stale result')['reason']);
        $this->assertTrue($activities->status($second->id)['can_continue']);
        $outcome = $activities->complete($second->id, 'replacement result');
        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task_id']);
        $this->assertSame($deadline, $execution->refresh()->schedule_to_close_deadline_at->toISOString());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)->count()
        );
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
    }

    public function testInternalActivityAbandonExpiresAtItsOriginalTotalDeadlineWithoutWakingParent(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity($run, $initial, maxAttempts: 3);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'deadline-owner')['claimed']);
        $attempt = $execution->attempts()
            ->sole();
        $this->closeCooperativeParent($run, $initial);
        try {
            Carbon::setTestNow($execution->refresh()->schedule_to_close_deadline_at);
            $this->assertFalse($activities->status($attempt->id)['can_continue']);
            $this->assertSame('activity_deadline_elapsed', $activities->complete($attempt->id, 'too late')['reason']);
            $expired = ActivityTimeoutEnforcer::enforce($execution->id);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertTrue($expired['enforced']);
        $this->assertNull($expired['next_task']);
        $this->assertSame(ActivityStatus::Failed, $execution->refresh()->status);
        $this->assertSame(1, $run->historyEvents()->where('event_type', HistoryEventType::ActivityTimedOut)->count());
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertSame(0, $run->tasks()->where('status', TaskStatus::Ready)->count());
        $this->assertNull(WorkflowRunRetentionCleanup::retentionHoldReason($run));
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
    }

    public function testInternalActivityAbandonCanBeFirstClaimedAfterCooperativeParentClosure(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity($run, $initial);
        $this->closeCooperativeParent($run, $initial);
        $this->assertSame(ActivityStatus::Pending, $execution->refresh()->status);
        $this->assertSame(TaskStatus::Ready, $activityTask->refresh()->status);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'late-first-owner')['claimed']);
        $attempt = $execution->attempts()
            ->sole();
        $this->assertTrue($activities->status($attempt->id)['can_continue']);
        $this->assertTrue($activities->complete($attempt->id, 'late first claim')['recorded']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(
            0,
            $run->tasks()
                ->where('task_type', TaskType::Workflow)->where('status', TaskStatus::Ready)->count()
        );
    }

    public function testInternalActivityAbandonRecoversAnExpiredOwnerAfterParentClosure(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity($run, $initial);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'lost-owner')['claimed']);
        $first = $execution->attempts()
            ->sole();
        $deadline = $execution->refresh()
            ->schedule_to_close_deadline_at->toISOString();
        $this->closeCooperativeParent($run, $initial);
        $activityTask->refresh()
            ->forceFill([
                'lease_expires_at' => now()
                    ->subSecond(),
            ])->save();
        $repaired = TaskRepair::recoverExistingTask($activityTask, $run->fresh());
        $this->assertNotNull($repaired);
        $this->assertSame(TaskStatus::Ready, $repaired->status);
        $this->assertSame(ActivityAttemptStatus::Expired, $first->refresh()->status);
        $this->assertTrue($activities->claimStatus($repaired->id, 'fresh-owner')['claimed']);
        $second = $execution->attempts()
            ->where('attempt_number', 2)
            ->sole();
        $this->assertSame('stale_attempt', $activities->complete($first->id, 'lost owner result')['reason']);
        $this->assertTrue($activities->complete($second->id, 'recovered result')['recorded']);
        $this->assertSame($deadline, $execution->refresh()->schedule_to_close_deadline_at->toISOString());
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
    }

    public function testInternalActivityAbandonCannotBeEnabledByChangingOnlyAMutableOption(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity(
            $run,
            $initial,
            policy: CancellationPolicy::TryCancel
        );
        $execution->forceFill([
            'activity_options' => [
                'cancellation_policy' => CancellationPolicy::Abandon->value,
            ],
        ])->save();
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'mutable-owner')['claimed']);
        $attempt = $execution->attempts()
            ->sole();
        $this->closeCooperativeParent($run, $initial);
        $this->assertSame(ActivityStatus::Cancelled, $execution->refresh()->status);
        $this->assertFalse($activities->status($attempt->id)['can_continue']);
        $this->assertFalse($activities->complete($attempt->id, 'not authorized')['recorded']);
    }

    public function testInternalActivityAbandonDoesNotOverrideLegacyTerminalCancellation(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask] = $this->scheduleInternalAbandonableActivity($run, $initial);
        $activities = $this->app->make(ActivityTaskBridge::class);
        $this->assertTrue($activities->claimStatus($activityTask->id, 'terminal-owner')['claimed']);
        $attempt = $execution->attempts()
            ->sole();
        $this->request($run);
        $this->assertTrue(
            WorkflowStub::load($run->workflow_instance_id)->cancel('terminal operator action')->accepted()
        );
        $this->assertFalse($activities->status($attempt->id)['can_continue']);
        $this->assertFalse($activities->complete($attempt->id, 'terminal late result')['recorded']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
    }

    public function testInternalActivityAbandonRefusesAnUnboundedDetachedLifetime(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution] = $this->scheduleInternalAbandonableActivity($run, $initial, totalTimeout: null);
        $this->request($run);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original finite total deadline');
        ActivityAbandonment::prepare($run->fresh(['historyEvents']), $execution);
    }

    public function testInternalActivityWaitReleasesClaimAndResumesOnlyAfterOriginalCallbackStopReceipt(): void
    {
        [$run, $initial] = $this->newRun();
        [$execution, $activityTask, $attempt] = $this->scheduleInternalWaitingActivity($run, $initial, 1);
        $initial->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();
        $this->request($run);
        $deadline = $run->cancellation_deadline_at->toISOString();
        $resume = $this->leaseReadyTask($run);
        $waiting = $this->deliver($run, $resume);
        $this->assertFalse($waiting['delivered']);
        $this->assertTrue($waiting['claim_released']);
        $this->assertSame('cancellation_waiting_for_activity', $waiting['reason']);
        $this->assertSame(TaskStatus::Completed, $resume->refresh()->status);
        $this->assertNull($resume->lease_expires_at);
        $this->assertSame(ActivityStatus::Cancelled, $execution->refresh()->status);
        $this->assertSame(TaskStatus::Cancelled, $activityTask->refresh()->status);
        $this->assertSame(0, $this->deliveryCount($run));
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->count());

        $stale = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            'replacement-owner',
            $run->cancellation_request_command_id
        );
        $this->assertFalse($stale['acknowledged']);
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->count());
        $receipt = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            $attempt->lease_owner,
            $run->cancellation_request_command_id
        );
        $this->assertTrue($receipt['acknowledged']);
        $next = $this->leaseReadyTask($run);
        $this->assertNotSame($resume->id, $next->id);
        $this->assertSame('activity_cancellation_acknowledged', $next->payload['resume_source_kind']);
        $this->assertSame($receipt['history_event_id'], $next->payload['resume_source_id']);
        $changedBoundary = $this->deliver($run, $next, 2, 'timer');
        $this->assertFalse($changedBoundary['delivered']);
        $this->assertSame('cancellation_wait_boundary_mismatch', $changedBoundary['reason']);
        $this->assertSame(TaskStatus::Leased, $next->refresh()->status);
        $delivery = $this->deliver($run, $next);
        $this->assertTrue($delivery['delivered']);
        $this->assertSame(1, $this->deliveryCount($run));
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $duplicate = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            $attempt->lease_owner,
            $run->cancellation_request_command_id
        );
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($receipt['history_event_id'], $duplicate['history_event_id']);
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->count());
    }

    public function testInternalParallelActivityWaitWakesOnlyAfterAllOriginalCallbacksAreAcknowledged(): void
    {
        [$run, $initial] = $this->newRun();
        [, , $first] = $this->scheduleInternalWaitingActivity($run, $initial, 1, 2);
        [, , $second] = $this->scheduleInternalWaitingActivity($run, $initial, 2, 2);
        $initial->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();
        $this->request($run);
        $waiting = $this->deliver($run, $this->leaseReadyTask($run), 1, 'parallel', 2);
        $this->assertFalse($waiting['delivered']);
        foreach ([$first, $second] as $index => $attempt) {
            $receipt = ActivityCancellationAcknowledgement::recordStopped(
                $attempt->id,
                $attempt->lease_owner,
                $run->cancellation_request_command_id
            );
            $this->assertTrue($receipt['acknowledged']);
            $this->assertSame($index, $run->tasks()->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)->count());
        }
        $this->assertTrue($this->deliver($run, $this->leaseReadyTask($run), 1, 'parallel', 2)['delivered']);
        $this->assertSame(1, $this->deliveryCount($run));
    }

    public function testInternalActivityWaitDoesNotTurnLateStopEvidenceIntoANewCleanupBudget(): void
    {
        [$run, $initial] = $this->newRun();
        [, , $attempt] = $this->scheduleInternalWaitingActivity($run, $initial, 1);
        $initial->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();
        $this->request($run);
        $waiting = $this->deliver($run, $this->leaseReadyTask($run));
        $this->assertFalse($waiting['delivered']);
        Carbon::setTestNow($run->cancellation_deadline_at->copy()->addSecond());
        try {
            $receipt = ActivityCancellationAcknowledgement::recordStopped(
                $attempt->id,
                $attempt->lease_owner,
                $run->cancellation_request_command_id
            );
            $this->assertTrue($receipt['acknowledged']);
            $event = $run->historyEvents()
                ->findOrFail($receipt['history_event_id']);
            $this->assertTrue($event->payload['received_after_deadline']);
            $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)->count());
            $this->assertSame(0, $this->deliveryCount($run));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testPortableChildWaitReleasesItsClaimUntilCanonicalChildCancellationCompletes(): void
    {
        [$run, $task] = $this->newRun();
        $scheduled = $this->bridge->complete($task->id, [[
            'type' => 'start_child_workflow',
            'workflow_type' => 'portable-cleanup-child',
            'arguments' => Serializer::serialize([]),
            'cancellation_policy' => CancellationPolicy::WaitCancellationCompleted->value,
        ]]);
        $this->assertTrue($scheduled['completed']);
        $child = $run->childLinks()
            ->sole()
->childRun;
        $this->assertInstanceOf(WorkflowRun::class, $child);
        $this->request($run);
        $resume = $this->leaseReadyTask($run);
        $deadline = $run->cancellation_deadline_at->toISOString();

        $waiting = $this->deliver($run, $resume, 1, 'child');
        $this->assertFalse($waiting['delivered']);
        $this->assertTrue($waiting['claim_released']);
        $this->assertSame('cancellation_waiting_for_child', $waiting['reason']);
        $this->assertSame(TaskStatus::Completed, $resume->refresh()->status);
        $this->assertNull($resume->lease_expires_at);
        $this->assertSame(0, $this->deliveryCount($run));
        $this->assertSame('task_not_leased', $this->deliver($run, $resume, 1, 'child')['reason']);
        $openTasks = $run->tasks()
            ->where('task_type', TaskType::Workflow);
        $openTasks->whereIn('status', [TaskStatus::Ready, TaskStatus::Leased]);
        $this->assertSame(0, $openTasks->count());
        $this->assertSame($deadline, $child->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationRequested)->count()
        );
        $childTask = $this->leaseReadyTask($child);
        $this->assertTrue($this->deliver($child, $childTask, 1, 'timer')['delivered']);
        $this->assertTrue($this->bridge->complete($childTask->id, [[
            'type' => 'complete_workflow',
            'output' => Serializer::serialize('cleanup complete'),
        ]])['completed']);
        $this->assertSame(RunStatus::Cancelled, $child->refresh()->status);

        $replacement = $this->leaseReadyTask($run);
        $delivery = $this->deliver($run, $replacement, 1, 'child');
        $this->assertTrue($delivery['delivered']);
        $this->assertSame($run->cancellation_request_command_id, $delivery['request_id']);
        $this->assertSame(1, $this->deliveryCount($run));
        $this->assertTrue($this->bridge->complete($replacement->id, [[
            'type' => 'complete_workflow',
            'output' => Serializer::serialize('parent cleanup complete'),
        ]])['completed']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame($deadline, $run->cancellation_deadline_at->toISOString());
    }

    public function testPortableWaitDoesNotTreatATerminalProjectionAsCanonicalAcknowledgement(): void
    {
        [$run, $task] = $this->newRun();
        $this->assertTrue($this->bridge->complete($task->id, [[
            'type' => 'start_child_workflow',
            'workflow_type' => 'portable-cleanup-child',
            'arguments' => Serializer::serialize([]),
            'cancellation_policy' => CancellationPolicy::WaitCancellationCompleted->value,
        ]])['completed']);
        $child = $run->childLinks()
            ->sole()
->childRun;
        $child->forceFill([
            'status' => RunStatus::Cancelled,
        ])->save();
        $this->request($run);
        $resume = $this->leaseReadyTask($run);

        $result = $this->deliver($run, $resume, 1, 'child');
        $this->assertFalse($result['delivered']);
        $this->assertSame('cancellation_waiting_for_child', $result['reason']);
        $this->assertSame(0, $this->deliveryCount($run));
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ChildCancellationResolved)->count()
        );
    }

    public function testPortableChildPolicyRejectsInvalidValuesBeforeCreatingAnyChild(): void
    {
        [$run, $task] = $this->newRun();
        foreach (['unknown', false, 1, []] as $invalid) {
            $result = $this->bridge->complete($task->id, [[
                'type' => 'start_child_workflow',
                'workflow_type' => 'portable-cleanup-child',
                'arguments' => Serializer::serialize([]),
                'cancellation_policy' => $invalid,
            ]]);
            $this->assertFalse($result['completed']);
            $this->assertSame('invalid_commands', $result['reason']);
            $this->assertSame(0, $run->childLinks()->count());
            $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
        }
    }

    public function testExistingCustomBridgeDoesNotAcquireCooperativeCapability(): void
    {
        $custom = \Mockery::mock(WorkflowTaskBridge::class);
        $this->app->instance(WorkflowTaskBridge::class, $custom);
        $resolved = $this->app->make(CooperativeWorkflowTaskBridge::class);
        $this->assertSame($custom, $resolved);
        $this->assertNotInstanceOf(CooperativeWorkflowTaskBridge::class, $resolved);
    }

    public function testDeliveryWithoutAScheduledCallReservesItsPositionAndPreservesCleanupAuthority(): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);
        $deadline = $run->cancellation_deadline_at?->toISOString();
        $lease = $task->lease_expires_at?->copy();

        $result = $this->deliver($run, $task);

        $this->assertTrue($result['delivered']);
        $this->assertSame($run->cancellation_request_command_id, $result['request_id']);
        $this->assertSame(1, $result['sequence']);
        $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
        $this->assertTrue($task->lease_expires_at->lt($lease));
        $this->assertTrue($task->lease_expires_at->gt(now()));
        $this->assertTrue($task->lease_expires_at->lte(now()->addSeconds(10)));
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at?->toISOString());
        $this->assertTrue($this->bridge->heartbeat($task->id)['renewed']);
        $cleanup = $this->bridge->complete($task->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 10,
        ]]);
        $this->assertTrue($cleanup['completed']);
        $this->assertSame(2, $run->timers()->sole()->sequence);
        $this->assertSame(RunStatus::Waiting, $run->refresh()->status);
        $this->assertSame(1, $this->deliveryCount($run));
    }

    public function testDeliveryRetryAfterCleanupSubmissionDoesNotCancelCleanupOrChangeIdentity(): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);
        $first = $this->deliver($run, $task);
        $deliveredAt = $run->refresh()
            ->cancellation_delivered_at?->toISOString();
        $this->assertTrue($this->bridge->complete(
            $task->id,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 10,
            ]],
        )['completed']);
        $replacement = $this->newLease($run);

        $duplicate = $this->deliver($run->fresh(), $replacement);

        $this->assertTrue($duplicate['delivered']);
        $this->assertSame($first['request_id'], $duplicate['request_id']);
        $this->assertSame($first['sequence'], $duplicate['sequence']);
        $this->assertSame($deliveredAt, $run->refresh()->cancellation_delivered_at?->toISOString());
        $this->assertSame(TimerStatus::Pending, $run->timers()->sole()->status);
        $this->assertSame(1, $this->deliveryCount($run));
    }

    public function testParallelDeliveryBeforeSchedulingReservesTheEntireAuthoredRange(): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);

        $delivery = $this->deliver($run, $task, 1, 'parallel', 3);
        $this->assertTrue($delivery['delivered']);
        $this->assertSame(3, $delivery['sequence_span']);
        $this->assertTrue($this->bridge->complete(
            $task->id,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 10,
            ]],
        )['completed']);
        $this->assertSame(4, $run->timers()->sole()->sequence);
    }

    public function testDeliveryRevokesAnOpenActivityAttemptAndRefusesLateCompletion(): void
    {
        [$run, $task] = $this->newRun();
        $scheduled = $this->bridge->complete($task->id, [[
            'type' => 'schedule_activity',
            'activity_type' => TestGreetingActivity::class,
            'arguments' => Serializer::serialize(['Taylor']),
        ]]);
        $this->assertTrue($scheduled['completed']);
        $activityBridge = $this->app->make(ActivityTaskBridge::class);
        $claim = $activityBridge->claimStatus($scheduled['created_task_ids'][0], 'remote-worker');
        $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
        $this->request($run);
        $resume = $this->leaseReadyTask($run);

        $this->assertTrue($this->deliver($run, $resume)['delivered']);
        $execution = $run->activityExecutions()
            ->sole();
        $this->assertSame(ActivityStatus::Cancelled, $execution->status);
        $attempt = $execution->attempts()
            ->sole();
        $this->assertSame(ActivityAttemptStatus::Cancelled, $attempt->status);
        $this->assertNull($attempt->lease_expires_at);
        $this->assertSame(TaskStatus::Leased, $resume->refresh()->status);
        $late = $activityBridge->complete($attempt->id, Serializer::serialize('late effect'));
        $this->assertFalse($late['recorded']);
        $this->assertSame(0, $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityCompleted->value)->count());
    }

    public function testDeliveryCancelsTheOriginalTimerAndItsQueuedTask(): void
    {
        [$run, $task] = $this->newRun();
        $this->assertTrue($this->bridge->complete(
            $task->id,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 300,
            ]],
        )['completed']);
        $this->request($run);
        $resume = $this->leaseReadyTask($run);
        $this->assertTrue($this->deliver($run, $resume, 1, 'timer')['delivered']);
        $this->assertSame(TimerStatus::Cancelled, $run->timers()->sole()->status);
        $this->assertSame(TaskStatus::Cancelled, $run->tasks()
            ->where('task_type', TaskType::Timer->value)->sole()->status);
        $this->assertSame(1, $run->historyEvents()
            ->where('event_type', HistoryEventType::TimerCancelled->value)->count());
    }

    #[DataProvider('workflowLeaseStates')]
    public function testWorkflowLeaseRecoveryPreservesAnOlderRunningActivity(bool $expired): void
    {
        $startedAt = now();
        Carbon::setTestNow($startedAt);
        config()
            ->set('workflows.v2.workflow_task_lease_seconds', 10);

        try {
            [$run, $task] = $this->newRun();
            $scheduled = $this->bridge->complete($task->id, [[
                'type' => 'schedule_activity',
                'activity_type' => TestGreetingActivity::class,
                'arguments' => Serializer::serialize(['Taylor']),
            ]]);
            $this->assertTrue($scheduled['completed']);
            Carbon::setTestNow($startedAt->copy()->addSecond());
            $activityBridge = $this->app->make(ActivityTaskBridge::class);
            $activityClaim = $activityBridge->claimStatus($scheduled['created_task_ids'][0], 'activity-owner');
            $this->assertTrue($activityClaim['claimed'], $activityClaim['reason'] ?? '');
            $activityTask = WorkflowTask::query()->findOrFail($scheduled['created_task_ids'][0]);
            $execution = $run->activityExecutions()
                ->sole();
            $attempt = $execution->attempts()
                ->sole();
            $activityTaskBefore = $activityTask->getAttributes();
            $executionBefore = $execution->getAttributes();
            $attemptBefore = $attempt->getAttributes();
            $activityHistoryBefore = $run->historyEvents()
                ->where('event_type', 'like', 'Activity%')
                ->get()
                ->toArray();

            Carbon::setTestNow($startedAt->copy()->addSeconds(2));
            $this->request($run);
            $resume = $run->tasks()
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)->sole();
            $claim = $this->bridge->claimStatus($resume->id, 'lost-workflow-owner');
            $this->assertTrue($claim['claimed'], $claim['reason'] ?? '');
            $resume->refresh();
            $oldAttempt = $resume->attempt_count;
            Carbon::setTestNow($startedAt->copy()->addSeconds($expired ? 13 : 5));

            $summary = RunSummaryProjector::project($run->fresh());
            if ($expired) {
                $this->assertSame($resume->id, $summary->next_task_id);
                $this->assertSame('repair_needed', $summary->liveness_state);
            }
            $repair = WorkflowStub::loadRun($run->id)->attemptRepair();
            $this->assertSame($expired ? 'repair_dispatched' : 'repair_not_needed', $repair->outcome());
            $this->assertSame(2, $run->tasks()->where('task_type', TaskType::Workflow->value)->count());
            $resume->refresh();
            $this->assertSame($expired ? TaskStatus::Ready : TaskStatus::Leased, $resume->status);
            $this->assertSame($expired ? null : 'lost-workflow-owner', $resume->lease_owner);
            $this->assertSame($oldAttempt, $resume->attempt_count);

            if ($expired) {
                $replacement = $this->bridge->claimStatus($resume->id, 'replacement-workflow-owner');
                $this->assertTrue($replacement['claimed'], $replacement['reason'] ?? '');
                $this->assertSame($oldAttempt + 1, $resume->refresh()->attempt_count);
                $this->assertSame('replacement-workflow-owner', $resume->lease_owner);
            }
            $this->assertSame($activityTaskBefore, $activityTask->refresh()->getAttributes());
            $this->assertSame($executionBefore, $execution->refresh()->getAttributes());
            $this->assertSame($attemptBefore, $attempt->refresh()->getAttributes());
            $this->assertSame(
                $activityHistoryBefore,
                $run->historyEvents()
                    ->where('event_type', 'like', 'Activity%')
                    ->get()
                    ->toArray(),
            );
            $this->assertSame(0, $this->deliveryCount($run));
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function workflowLeaseStates(): iterable
    {
        yield 'expired workflow lease' => [true];
        yield 'fresh workflow lease' => [false];
    }

    public function testRemoteCallbackStopReceiptIsSeparateFromFencingAndKeepsTheOriginalBudget(): void
    {
        [$run, $activityTask, $attempt] = $this->cancelledRemoteAttempt();
        $deadline = $run->cancellation_deadline_at->toISOString();
        $taskBefore = $activityTask->getAttributes();
        $attemptBefore = $attempt->getAttributes();
        $this->assertSame(0, $this->stopReceiptCount($run));
        $accepted = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            'activity-owner',
            $run->cancellation_request_command_id,
        );
        $this->assertTrue($accepted['acknowledged']);
        $this->assertFalse($accepted['duplicate']);
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->sole();
        $this->assertSame($event->id, $accepted['history_event_id']);
        $this->assertSame($run->cancellation_request_command_id, $event->workflow_command_id);
        $this->assertSame($run->cancellation_request_command_id, $event->payload['root_request_id']);
        $this->assertSame($deadline, $event->payload['cleanup_deadline_at']);
        $this->assertSame('stopped', $event->payload['callback_state']);
        $this->assertSame('activity_worker', $event->payload['evidence_source']);
        $this->assertFalse($event->payload['received_after_deadline']);
        $repeated = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            'activity-owner',
            $run->cancellation_request_command_id,
        );
        $this->assertTrue($repeated['acknowledged']);
        $this->assertTrue($repeated['duplicate']);
        $this->assertSame($accepted['history_event_id'], $repeated['history_event_id']);
        $this->assertSame(1, $this->stopReceiptCount($run));
        $this->assertSame($taskBefore, $activityTask->refresh()->getAttributes());
        $this->assertSame($attemptBefore, $attempt->refresh()->getAttributes());
        $summary = RunSummaryProjector::project($run->fresh());
        $this->assertSame($run->historyEvents()->count(), $summary->history_event_count);
        $receipt = collect(HistoryTimeline::forRun($run->fresh()))->firstWhere(
            'type',
            'ActivityCancellationAcknowledged'
        );
        $this->assertSame($event->id, $receipt['id']);
        $this->assertSame($attempt->id, $receipt['activity']['attempt_id']);
        $this->assertSame('cancelled', $receipt['activity_status']);
        $this->assertSame($deadline, $receipt['cancellation_acknowledgement']['cleanup_deadline_at']);
        $this->assertSame(
            $event->payload['root_request_id'],
            $receipt['cancellation_acknowledgement']['root_request_id']
        );
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertFalse(
            $this->app->make(ActivityTaskBridge::class)->complete($attempt->id, 'late result')['recorded']
        );
    }

    #[DataProvider('stopAcknowledgementFences')]
    public function testRemoteStopReceiptRejectsAnotherOwnerAttemptOrRequest(string $field): void
    {
        [$run, , $attempt] = $this->cancelledRemoteAttempt();
        $arguments = [$attempt->id, 'activity-owner', $run->cancellation_request_command_id];
        $index = array_search($field, ['attempt', 'owner', 'request'], true);
        $arguments[$index] = 'another-identity';
        $reply = ActivityCancellationAcknowledgement::recordStopped(...$arguments);
        $this->assertFalse($reply['acknowledged']);
        $this->assertFalse($reply['duplicate']);
        $this->assertNotNull($reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    public static function stopAcknowledgementFences(): iterable
    {
        foreach (['attempt', 'owner', 'request'] as $field) {
            yield $field => [$field];
        }
    }

    public function testMutableCancelledRowsCannotSubstituteForCanonicalCancellationHistory(): void
    {
        [$run, $task, $attempt] = $this->cancelledRemoteAttempt(deliver: false);
        $task->forceFill([
            'status' => TaskStatus::Cancelled,
            'lease_expires_at' => null,
        ])->save();
        $attempt->forceFill([
            'status' => ActivityAttemptStatus::Cancelled,
            'lease_expires_at' => null,
        ])->save();
        $attempt->execution->forceFill([
            'status' => ActivityStatus::Cancelled,
        ])->save();
        $reply = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            'activity-owner',
            $run->cancellation_request_command_id,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('activity_cancellation_not_recorded', $reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    #[DataProvider('invalidStopSnapshots')]
    public function testLegacyOrMalformedCancellationSnapshotCannotAuthorizeAWorkerStopReceipt(bool $malformed): void
    {
        [$run, , $attempt] = $this->cancelledRemoteAttempt();
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityCancelled)->sole();
        $payload = $event->payload;
        unset($payload['activity_attempt']['id']);
        if ($malformed) {
            $payload['activity_attempt'] = 'not-an-attempt-snapshot';
        }
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $reply = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            'activity-owner',
            $run->cancellation_request_command_id,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('activity_cancellation_snapshot_mismatch', $reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    public static function invalidStopSnapshots(): iterable
    {
        yield 'attempt snapshot without identity' => [false];
        yield 'malformed attempt snapshot' => [true];
    }

    public function testLateStopReportRemainsLateAndCannotGrantAnotherCleanupBudget(): void
    {
        [$run, $task, $attempt] = $this->cancelledRemoteAttempt();
        $deadline = $run->cancellation_deadline_at->toISOString();
        Carbon::setTestNow($run->cancellation_deadline_at->copy()->addSecond());
        try {
            $reply = ActivityCancellationAcknowledgement::recordStopped(
                $attempt->id,
                'activity-owner',
                $run->cancellation_request_command_id,
            );
            $this->assertTrue($reply['acknowledged']);
            $event = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->sole();
            $this->assertTrue($event->payload['received_after_deadline']);
            $this->assertSame($deadline, $event->payload['cleanup_deadline_at']);
            $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
            $this->assertNull($task->refresh()->lease_expires_at);
            $this->assertNull($attempt->refresh()->lease_expires_at);
            $this->assertSame(0, $run->tasks()->where('status', TaskStatus::Ready)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testRemoteStopReceiptCannotAuthorizeALocalActivityCallback(): void
    {
        [$run, , $attempt] = $this->cancelledRemoteAttempt();
        $attempt->execution->forceFill([
            'activity_options' => [
                'execution_mode' => LocalActivityRuntime::EXECUTION_MODE,
            ],
        ])->save();
        $reply = ActivityCancellationAcknowledgement::recordStopped(
            $attempt->id,
            'activity-owner',
            $run->cancellation_request_command_id,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('local_cancellation_acknowledgement_requires_workflow_claim', $reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    public function testLocalStopReceiptKeepsTheOriginalWorkflowClaimAndDoesNotRenewCleanupAuthority(): void
    {
        [$run, $task, $attempt] = $this->cancelledLocalAttempt();
        $taskBefore = $task->getAttributes();
        $attemptBefore = $attempt->getAttributes();
        $deadline = $run->cancellation_deadline_at->toISOString();
        $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'portable-worker',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertTrue($reply['acknowledged']);
        $event = $run->historyEvents()
            ->whereKey($reply['history_event_id'])->sole();
        $this->assertTrue($event->payload['local_activity']);
        $this->assertSame(LocalActivityRuntime::EXECUTION_MODE, $event->payload['execution_mode']);
        $this->assertSame('workflow_worker', $event->payload['evidence_source']);
        $this->assertSame($task->id, $event->payload['workflow_task_id']);
        $this->assertSame('portable-worker', $event->payload['task']['lease_owner']);
        $this->assertSame(1, $event->payload['task']['attempt_count']);
        $this->assertSame($deadline, $event->payload['cleanup_deadline_at']);
        $this->assertSame($run->cancellation_request_command_id, $event->payload['root_request_id']);
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $this->assertSame($attemptBefore, $attempt->refresh()->getAttributes());
        $this->assertSame(TaskStatus::Leased, $task->status);
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $receipt = collect(HistoryTimeline::forRun($run->fresh()))->firstWhere(
            'type',
            'ActivityCancellationAcknowledged'
        );
        $this->assertSame($event->id, $receipt['id']);
        $this->assertSame($attempt->id, $receipt['activity']['attempt_id']);
    }

    public function testLocalOriginalOwnerCanReportAfterWorkflowTakeoverButTheReplacementCannotImpersonateIt(): void
    {
        [$run, $task, $attempt] = $this->cancelledLocalAttempt();
        $task->forceFill([
            'lease_owner' => 'replacement-owner',
            'attempt_count' => 2,
        ])->save();
        $taskBefore = $task->refresh()
            ->getAttributes();
        foreach ([['replacement-owner', 2],
            ['portable-worker', 2],
            ['portable-worker', 0],
        ] as [$owner, $claimAttempt]) {
            $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
                $attempt->id,
                $owner,
                $run->cancellation_request_command_id,
                $claimAttempt,
            );
            $this->assertFalse($reply['acknowledged']);
        }
        $this->assertSame(0, $this->stopReceiptCount($run));
        $original = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'portable-worker',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertTrue($original['acknowledged']);
        $event = $run->historyEvents()
            ->whereKey($original['history_event_id'])->sole();
        $this->assertSame('portable-worker', $event->payload['task']['lease_owner']);
        $this->assertSame(1, $event->payload['task']['attempt_count']);
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $receipt = collect(HistoryTimeline::forRun($run->fresh()))->firstWhere(
            'type',
            'ActivityCancellationAcknowledged'
        );
        $this->assertSame('workflow', $receipt['task']['type']);
        $this->assertSame('leased', $receipt['task']['status']);
        $this->assertSame(1, $receipt['task']['attempt_count']);
        $task->forceFill([
            'status' => TaskStatus::Completed,
            'attempt_count' => 3,
        ])->save();
        $taskBefore = $task->refresh()
            ->getAttributes();
        $duplicate = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'portable-worker',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertTrue($duplicate['acknowledged']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame($original['history_event_id'], $duplicate['history_event_id']);
        $this->assertSame(1, $this->stopReceiptCount($run));
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $receipt = collect(HistoryTimeline::forRun($run->fresh()))->firstWhere(
            'type',
            'ActivityCancellationAcknowledged'
        );
        $this->assertSame('workflow', $receipt['task']['type']);
        $this->assertSame('leased', $receipt['task']['status']);
        $this->assertSame(1, $receipt['task']['attempt_count']);
    }

    #[DataProvider('invalidLocalStopClaims')]
    public function testLocalStopReceiptRequiresTheClaimSavedBeforeItsCallback(string $field, mixed $value): void
    {
        [$run, , $attempt] = $this->cancelledLocalAttempt();
        $event = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->sole();
        $payload = $event->payload;
        if ($field === 'task') {
            $payload['task'] = $value;
        } else {
            $payload['task'][$field] = $value;
        }
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'portable-worker',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('local_activity_workflow_claim_mismatch', $reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    public static function invalidLocalStopClaims(): iterable
    {
        yield 'legacy start without claim' => ['task', null];
        yield 'malformed claim' => ['task', 'not-a-claim'];
        yield 'another task' => ['id', 'another-task'];
        yield 'ordinary activity task' => ['type', TaskType::Activity->value];
        yield 'unclaimed task' => ['status', TaskStatus::Ready->value];
        yield 'another original owner' => ['lease_owner', 'another-owner'];
        yield 'missing original attempt' => ['attempt_count', null];
        yield 'nonpositive original attempt' => ['attempt_count', 0];
    }

    public function testLocalStopReceiptCannotBorrowARemoteAttemptOrAnotherCancellationRequest(): void
    {
        [$run, , $attempt] = $this->cancelledRemoteAttempt();
        $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'activity-owner',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('activity_is_not_local', $reply['reason']);
        [$run, , $attempt] = $this->cancelledLocalAttempt();
        $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'portable-worker',
            'another-request',
            1,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('cancellation_request_mismatch', $reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    #[DataProvider('unpreparedLocalCallbacks')]
    public function testLocalStopReceiptRejectsAnAbsentOrPostCancellationStart(bool $missing): void
    {
        [$run, , $attempt] = $this->cancelledLocalAttempt();
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->sole();
        if ($missing) {
            $started->delete();
        } else {
            $started->forceFill([
                'sequence' => $run->last_history_sequence + 1,
            ])->save();
        }
        $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
            $attempt->id,
            'portable-worker',
            $run->cancellation_request_command_id,
            1,
        );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('local_activity_workflow_claim_mismatch', $reply['reason']);
        $this->assertSame(0, $this->stopReceiptCount($run));
    }

    public static function unpreparedLocalCallbacks(): iterable
    {
        yield 'callback without durable preparation' => [true];
        yield 'post hoc callback start' => [false];
    }

    public function testLateLocalReceiptDoesNotRenewTheOriginalDeadlineOrReplacementLease(): void
    {
        [$run, $task, $attempt] = $this->cancelledLocalAttempt();
        $task->forceFill([
            'lease_owner' => 'replacement-owner',
            'attempt_count' => 2,
        ])->save();
        $taskBefore = $task->refresh()
            ->getAttributes();
        $deadline = $run->cancellation_deadline_at->toISOString();
        Carbon::setTestNow($run->cancellation_deadline_at->copy()->addSecond());
        try {
            $reply = ActivityCancellationAcknowledgement::recordLocalStopped(
                $attempt->id,
                'portable-worker',
                $run->cancellation_request_command_id,
                1,
            );
            $this->assertTrue($reply['acknowledged']);
            $event = $run->historyEvents()
                ->whereKey($reply['history_event_id'])->sole();
            $this->assertTrue($event->payload['received_after_deadline']);
            $this->assertSame($deadline, $event->payload['cleanup_deadline_at']);
            $this->assertSame($taskBefore, $task->refresh()->getAttributes());
            $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testARecordedCallCannotBeRelabelledAsADifferentOperation(): void
    {
        [$run, $task] = $this->newRun();
        $this->assertTrue($this->bridge->complete(
            $task->id,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 300,
            ]],
        )['completed']);
        $this->request($run);
        $result = $this->deliver($run, $this->leaseReadyTask($run), 1, 'activity');
        $this->assertFalse($result['delivered']);
        $this->assertSame('cancellation_delivery_shape_mismatch', $result['reason']);
        $this->assertSame(TimerStatus::Pending, $run->timers()->sole()->status);
        $this->assertSame(0, $this->deliveryCount($run));
    }

    #[DataProvider('openWaits')]
    public function testOpenWaitsCanBeInterruptedWithoutRevokingCleanupAuthority(
        string $kind,
        array $command,
    ): void {
        [$run, $task] = $this->newRun();
        $this->assertTrue($this->bridge->complete($task->id, [$command])['completed']);
        $this->request($run);
        $resume = $this->leaseReadyTask($run);
        $this->assertTrue($this->deliver($run, $resume, 1, $kind)['delivered']);
        $this->assertSame(TaskStatus::Leased, $resume->refresh()->status);
        $this->assertSame(RunStatus::Waiting, $run->refresh()->status);
        foreach ($run->timers()->get() as $timer) {
            $this->assertSame(TimerStatus::Cancelled, $timer->status);
        }
        $this->assertTrue($this->bridge->complete($resume->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 10,
        ]])['completed']);
        $cleanup = $run->timers()
            ->where('sequence', 2)
            ->sole();
        $this->assertSame(TimerStatus::Pending, $cleanup->status);
    }

    public static function openWaits(): iterable
    {
        foreach ([null, 300] as $timeout) {
            yield 'condition timeout ' . ($timeout ?? 'unbounded') => ['condition', [
                'type' => 'open_condition_wait',
                'condition_key' => 'approval',
                'timeout_seconds' => $timeout,
            ]];
            yield 'signal timeout ' . ($timeout ?? 'unbounded') => ['signal', [
                'type' => 'open_signal_wait',
                'signal_name' => 'approval',
                'timeout_seconds' => $timeout,
            ]];
        }
    }

    #[DataProvider('timerResolutionOrdering')]
    public function testRecordedResultsBeforeTheRequestReplayNormally(bool $resultBeforeRequest): void
    {
        [$run, $task] = $this->newRun();
        $this->assertTrue($this->bridge->complete(
            $task->id,
            [[
                'type' => 'start_timer',
                'delay_seconds' => 300,
            ]],
        )['completed']);
        if (! $resultBeforeRequest) {
            $this->request($run);
        }
        $timer = $run->timers()
            ->sole();
        $timer->forceFill([
            'status' => TimerStatus::Fired,
        ])->save();
        WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired, [
            'sequence' => 1,
            'timer_id' => $timer->id,
            'delay_seconds' => 300,
        ]);
        if ($resultBeforeRequest) {
            $this->request($run);
        }
        $result = $this->deliver($run, $this->leaseReadyTask($run), 1, 'timer');
        $this->assertSame(! $resultBeforeRequest, $result['delivered']);
        $this->assertSame($resultBeforeRequest ? 'cancellation_delivery_not_eligible' : null, $result['reason']);
        $this->assertSame($resultBeforeRequest ? 0 : 1, $this->deliveryCount($run));
    }

    public static function timerResolutionOrdering(): iterable
    {
        yield 'result precedes request' => [true];
        yield 'request precedes result' => [false];
    }

    #[DataProvider('invalidDeliveryClaims')]
    public function testInvalidDeliveryClaimsDoNotPartiallyApply(
        string $requestId,
        int $sequence,
        string $kind,
        int $span,
        ?int $operationSequence,
    ): void {
        [$run, $task] = $this->newRun();
        $this->request($run);
        $result = $this->bridge->deliverCancellation(
            $task->id,
            $requestId === 'original' ? $run->cancellation_request_command_id : $requestId,
            $sequence,
            $kind,
            $span,
            $operationSequence,
        );
        $this->assertFalse($result['delivered']);
        $this->assertSame(0, $this->deliveryCount($run));
        $this->assertNull($run->refresh()->cancellation_delivery_sequence);
        $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
    }

    public static function invalidDeliveryClaims(): iterable
    {
        yield 'another request' => ['wrong', 1, 'activity', 1, null];
        yield 'future call' => ['original', 2, 'activity', 1, null];
        yield 'zero call' => ['original', 0, 'activity', 1, null];
        yield 'unknown kind' => ['original', 1, 'guess', 1, null];
        yield 'zero range' => ['original', 1, 'parallel', 0, null];
        yield 'scalar range' => ['original', 1, 'activity', 2, null];
        yield 'overflowing range' => ['original', PHP_INT_MAX, 'parallel', 2, null];
        yield 'absent selection member' => ['original', 1, 'selection_handle', 1, 1];
        yield 'selection metadata on scalar' => ['original', 1, 'timer', 1, 1];
    }

    public function testDeliveryCannotUseAnExpiredWorkflowLease(): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);
        $task->forceFill([
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $result = $this->deliver($run, $task);
        $this->assertFalse($result['delivered']);
        $this->assertSame('lease_expired', $result['reason']);
        $this->assertSame(0, $this->deliveryCount($run));
        $this->assertSame(RunStatus::Waiting, $run->refresh()->status);
    }

    public function testDeadlineExpiryClosesTheOriginalRequestWithoutDeliveringLate(): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);
        try {
            Carbon::setTestNow($run->cancellation_deadline_at);
            $result = $this->deliver($run, $task);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertFalse($result['delivered']);
        $this->assertSame('run_cancelled', $result['reason']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(0, $this->deliveryCount($run));
        $this->assertSame(1, $run->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowCancelled->value)
            ->where('workflow_command_id', $run->cancellation_request_command_id)
            ->count());
    }

    #[DataProvider('cleanupTerminalCommands')]
    public function testCleanupEndsWithTheOriginalCancellationOutcome(array $command): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);
        $this->assertTrue($this->deliver($run, $task)['delivered']);
        if ($command['type'] === 'complete_workflow') {
            $command['result'] = Serializer::serialize('cleanup result');
        }
        $result = $this->bridge->complete($task->id, [$command]);
        $this->assertTrue($result['completed']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertNull($run->output);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $run->workflow_instance_id)->count());
        $this->assertSame(1, $run->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowCancelled->value)
            ->where('workflow_command_id', $run->cancellation_request_command_id)
            ->count());
    }

    public static function cleanupTerminalCommands(): iterable
    {
        yield 'return' => [[
            'type' => 'complete_workflow',
        ]];
        yield 'throw' => [[
            'type' => 'fail_workflow',
            'message' => 'cleanup failed',
        ]];
        yield 'continue' => [[
            'type' => 'continue_as_new',
        ]];
    }

    public function testTerminationRevokesCleanupAndCannotBeReplacedByCancellation(): void
    {
        [$run, $task] = $this->newRun();
        $this->request($run);
        $this->assertTrue($this->deliver($run, $task)['delivered']);
        $this->assertTrue(WorkflowStub::load($run->workflow_instance_id)->terminate('stop cleanup')->accepted());
        $this->assertFalse($this->deliver($run, $task)['delivered']);
        $this->assertFalse($this->bridge->complete($task->id, [[
            'type' => 'complete_workflow',
        ]])['completed']);
        $this->assertSame(RunStatus::Terminated, $run->refresh()->status);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowCancelled->value)->count()
        );
        $this->assertSame(1, $this->deliveryCount($run));
    }

    public function testSelectionHandleDeliveryOnlyCancelsTheNamedOpenOperation(): void
    {
        [$run, $task] = $this->newRun();
        $commands = [];
        for ($index = 0; $index < 2; ++$index) {
            $commands[] = [
                'type' => 'start_timer',
                'delay_seconds' => 300,
                ...ParallelChildGroup::itemMetadata(1, 2, $index, 'timer'),
            ];
        }
        $this->assertTrue($this->bridge->complete($task->id, $commands)['completed']);
        $this->request($run);
        $result = $this->deliver($run, $this->leaseReadyTask($run), 3, 'selection_handle', 1, 1);
        $this->assertTrue($result['delivered']);
        $timers = $run->timers()
            ->orderBy('sequence')
            ->get();
        $this->assertSame(TimerStatus::Cancelled, $timers[0]->status);
        $this->assertSame(TimerStatus::Pending, $timers[1]->status);
    }

    #[DataProvider('parallelDeliveryCalls')]
    public function testRecordedParallelRangeIsCancelledAsOneAuthoredCall(bool $selectionHandle): void
    {
        [$run, $task] = $this->newRun();
        $commands = [];
        for ($index = 0; $index < 2; ++$index) {
            $commands[] = [
                'type' => 'start_timer',
                'delay_seconds' => 300,
                ...ParallelChildGroup::itemMetadata(1, 2, $index, 'timer'),
            ];
        }
        $this->assertTrue($this->bridge->complete($task->id, $commands)['completed']);
        $this->request($run);
        $resume = $this->leaseReadyTask($run);
        $delivery = $selectionHandle
            ? $this->bridge->deliverCancellation(
                $resume->id,
                $run->cancellation_request_command_id,
                3,
                'selection_handle',
                1,
                1,
                2,
            )
            : $this->deliver($run, $resume, 1, 'parallel', 2);
        $this->assertTrue($delivery['delivered']);
        $this->assertSame(2, $run->timers()->where('status', TimerStatus::Cancelled->value)->count());
        $this->assertSame(2, $run->tasks()->where('task_type', TaskType::Timer->value)
            ->where('status', TaskStatus::Cancelled->value)->count());
        $this->assertTrue($this->bridge->complete($resume->id, [[
            'type' => 'start_timer',
            'delay_seconds' => 10,
        ]])['completed']);
        $this->assertSame($selectionHandle ? 4 : 3, $run->timers()
            ->where('status', TimerStatus::Pending->value)->sole()->sequence);
        $this->assertSame(1, $this->deliveryCount($run));
    }

    public static function parallelDeliveryCalls(): iterable
    {
        yield 'parallel barrier' => [false];
        yield 'selection member range' => [true];
    }

    /**
     * Creates internal Source history before callback admission. Portable SDK
     * policy admission and local Abandon lifetime are not exposed yet.
     *
     * @return array{ActivityExecution, WorkflowTask}
     */
    private function scheduleInternalAbandonableActivity(
        WorkflowRun $run,
        WorkflowTask $task,
        CancellationPolicy $policy = CancellationPolicy::Abandon,
        ?int $totalTimeout = 180,
        int $maxAttempts = 1,
    ): array {
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'database',
            'queue' => 'default',
            'activity_options' => [
                'cancellation_policy' => $policy->value,
            ],
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => $maxAttempts,
                'backoff_seconds' => [0],
                'schedule_to_close_timeout' => $totalTimeout,
            ],
            'schedule_to_close_deadline_at' => $totalTimeout === null ? null : now()
                ->addSeconds($totalTimeout),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ], $task);
        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
        ]);
        return [$execution, $activityTask];
    }

    private function closeCooperativeParent(WorkflowRun $run, WorkflowTask $initial): void
    {
        $initial->forceFill([
            'status' => TaskStatus::Completed,
            'lease_expires_at' => null,
        ])->save();
        $this->request($run);
        $resume = $this->leaseReadyTask($run);
        $this->assertTrue($this->deliver($run, $resume)['delivered']);
        $this->assertTrue($this->bridge->complete($resume->id, [[
            'type' => 'complete_workflow',
        ]])['completed']);
        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
    }

    /**
     * @return array{ActivityExecution, WorkflowTask, \Workflow\V2\Models\ActivityAttempt}
     */
    private function scheduleInternalWaitingActivity(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        ?int $groupSize = null,
    ): array {
        $metadata = $groupSize === null ? [] : ParallelChildGroup::itemMetadata(
            1,
            $groupSize,
            $sequence - 1,
            'activity'
        );
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => TestGreetingActivity::class,
            'status' => ActivityStatus::Pending,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'database',
            'queue' => 'default',
            'activity_options' => [
                'cancellation_policy' => CancellationPolicy::WaitCancellationCompleted->value,
            ],
            'parallel_group_path' => $metadata['parallel_group_path'] ?? null,
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $sequence,
            'activity' => ActivitySnapshot::fromExecution($execution),
            ...$metadata,
        ], $task);
        $activityTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity,
            'status' => TaskStatus::Ready,
            'available_at' => now(),
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
        ]);
        $claimed = $this->app->make(ActivityTaskBridge::class)->claimStatus($activityTask->id, 'owner-' . $sequence);
        $this->assertTrue($claimed['claimed']);
        return [$execution, $activityTask, $execution->attempts()->sole()];
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, \Workflow\V2\Models\ActivityAttempt}
     */
    private function cancelledRemoteAttempt(bool $deliver = true): array
    {
        [$run, $task] = $this->newRun();
        $scheduled = $this->bridge->complete($task->id, [[
            'type' => 'schedule_activity',
            'activity_type' => TestGreetingActivity::class,
            'arguments' => Serializer::serialize(['Taylor']),
        ]]);
        $activityTask = WorkflowTask::query()->findOrFail($scheduled['created_task_ids'][0]);
        $claim = $this->app->make(ActivityTaskBridge::class)->claimStatus($activityTask->id, 'activity-owner');
        $this->assertTrue($claim['claimed']);
        $attempt = $run->activityExecutions()
            ->sole()
            ->attempts()
            ->sole();
        $this->assertNull($attempt->worker_attempt_id);
        $this->request($run);
        if ($deliver) {
            $this->assertTrue($this->deliver($run, $this->leaseReadyTask($run), 1, 'activity')['delivered']);
        }

        return [$run, $activityTask->refresh(), $attempt->refresh()];
    }

    /**
     * Exercises the actual embedded preparation path. The mock checks history
     * before cancellation, but does not claim to prove an SDK process stopped.
     *
     * @return array{WorkflowRun, WorkflowTask, \Workflow\V2\Models\ActivityAttempt}
     */
    private function cancelledLocalAttempt(): array
    {
        [$run, $task] = $this->newRun();
        $task->forceFill([
            'attempt_count' => 1,
        ])->save();
        WorkflowStub::mock(TestGreetingActivity::class, function (ActivityFakeContext $context): string {
            $started = $context->run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityStarted)->sole();
            $this->assertSame($context->taskId, $started->payload['task']['id']);
            $this->assertSame('portable-worker', $started->payload['task']['lease_owner']);
            $this->assertSame(1, $started->payload['task']['attempt_count']);
            $this->assertSame(TaskStatus::Leased->value, $started->payload['task']['status']);
            $this->request($context->run);
            ActivityCancellation::record(
                $context->run,
                $context->execution,
                command: $context->run->cancellation_request_command_id,
            );

            return 'a fenced local result';
        });
        $outcome = (new LocalActivityExecutor())->execute(
            $run,
            $task,
            1,
            new LocalActivityCall(TestGreetingActivity::class, ['Taylor']),
        );
        $this->assertSame('waiting', $outcome['status']);
        $this->assertSame(0, $run->historyEvents()->where('event_type', HistoryEventType::ActivityCompleted)->count());
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $attempt = $run->activityExecutions()
            ->sole()
            ->attempts()
            ->sole();

        return [$run->refresh(), $task->refresh(), $attempt];
    }

    private function stopReceiptCount(WorkflowRun $run): int
    {
        return $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count();
    }

    /**
     * @return array{WorkflowRun, WorkflowTask}
     */
    private function newRun(): array
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return [$run, $this->newLease($run)];
    }

    private function request(WorkflowRun $run): void
    {
        $this->assertTrue(
            WorkflowStub::load($run->workflow_instance_id)->requestCancellation('maintenance', 60)->accepted()
        );
        $run->refresh();
    }

    private function newLease(WorkflowRun $run): WorkflowTask
    {
        return WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'portable-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ]);
    }

    private function leaseReadyTask(WorkflowRun $run): WorkflowTask
    {
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'portable-worker',
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();

        return $task;
    }

    private function deliver(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence = 1,
        string $kind = 'activity',
        int $span = 1,
        ?int $operationSequence = null,
    ): array {
        return $this->bridge->deliverCancellation(
            $task->id,
            $run->cancellation_request_command_id,
            $sequence,
            $kind,
            $span,
            $operationSequence,
        );
    }

    private function deliveryCount(WorkflowRun $run): int
    {
        return $run->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationDelivered->value)->count();
    }
}
