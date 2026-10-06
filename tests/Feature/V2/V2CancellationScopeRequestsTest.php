<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestSignalWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\CancellationScopeDelivery;
use Workflow\V2\Support\CancellationScopeHistory;
use Workflow\V2\Support\CancellationScopeRequests;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

final class V2CancellationScopeRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Carbon::setTestNow('2026-10-03T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testAcceptedRequestRecoversBeforePreparationWithoutCreatingANewBudget(): void
    {
        [, $run, , $scope] = $this->scopeTree('request-recovery');
        $task = $run->tasks()
            ->sole();
        WorkflowRunSummary::query()->whereKey($run->id)->update([
            'next_task_id' => $task->id,
            'next_task_lease_expires_at' => $task->lease_expires_at,
        ]);
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $acceptedClaim = $task->fresh()
            ->getAttributes();
        $acceptedSummary = WorkflowRunSummary::query()->findOrFail($run->id);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );

        Carbon::setTestNow('2026-10-03T00:00:11Z');
        $repair = TaskWatchdog::runPass(respectThrottle: false, runIds: [$run->id]);
        $this->assertSame([], $repair['existing_task_failures']);
        $this->assertSame(1, $repair['repaired_existing_tasks']);
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->claimStatus($task->id, 'replacement')['claimed']);
        $replacement = $task->fresh();
        $this->assertSame(2, $replacement->attempt_count);
        $this->assertSame('2026-10-03T00:00:21.000000Z', $replacement->lease_expires_at->toISOString());
        $this->assertSame('2026-10-03T00:00:10.000000Z', $acceptedSummary->next_task_lease_expires_at->toISOString());
        $this->assertSame($acceptedClaim['lease_owner'], $task->lease_owner);
        $this->assertSame($acceptedClaim['attempt_count'], $task->attempt_count);
        $this->assertSame(
            '2026-10-03T00:00:30.000000Z',
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );

        $beforeDuplicate = $replacement->getAttributes();
        $duplicate = CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 300);
        $this->assertSame($request->id, $duplicate->id);
        $this->assertSameJsonObject($request->payload, $duplicate->payload);
        $this->assertSame($beforeDuplicate, $replacement->fresh()->getAttributes());

        $historyBefore = $run->historyEvents()
            ->count();
        try {
            CancellationScopeDelivery::prepare(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                3,
                'local_activity',
                '1.20'
            );
            $this->fail('Replaced owner must not prepare the cancellation.');
        } catch (LogicException $exception) {
            $this->assertSame('cancellation_scope_workflow_claim_mismatch', $exception->getMessage());
        }
        $this->assertSame($historyBefore, $run->historyEvents()->count());
        $this->assertSame($beforeDuplicate, $replacement->fresh()->getAttributes());
        $prepared = CancellationScopeDelivery::prepare(
            $run->fresh(),
            $replacement,
            $scope,
            $request->payload['request_id'],
            3,
            'local_activity',
            '1.20'
        );
        $this->assertSame($request->payload['request_id'], $prepared->payload['request_id']);
        $this->assertSameJsonObject($request->payload['cancellation'], $prepared->payload['cancellation']);
        $this->assertSame('2026-10-03T00:00:30.000000Z', $prepared->payload['authority_deadline_at']);
        $this->assertSame('2026-10-03T00:00:11.000000Z', $prepared->recorded_at->toISOString());
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
        $this->assertRunCancellationUntouched($run);
    }

    public function testDirectRequestColdReadsAndDuplicatesPreserveOriginalMetadataWithoutCancellingRun(): void
    {
        [$workflow, $run, $parent, $child] = $this->scopeTree('direct');
        $claimBefore = $run->tasks()
            ->sole()
            ->getAttributes();
        $event = CancellationScopeRequests::request(
            $run,
            $child,
            '1.20',
            30,
            'release resource',
            CommandContext::phpApi()->withPrincipal('operator', 'operator-fixture', 'Operator'),
        );
        $context = CancellationScopeRequests::context($run->fresh(), $child);
        $this->assertSame($event->payload['request_id'], $context->requestId);
        $this->assertSame($context->requestId, $context->rootContext->rootRequestId);
        $this->assertSame($child, $context->rootScopeId);
        $this->assertSame('release resource', $context->rootContext->reason);
        $this->assertSameJsonObject([
            'type' => 'operator',
            'id' => 'operator-fixture',
            'label' => 'Operator',
        ], $context->rootContext->requester);
        $this->assertSame('php', $context->rootContext->source);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $context->requestedAt()->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $context->deadline()->toISOString());
        Carbon::setTestNow('2026-10-03T00:00:10Z');
        $duplicate = CancellationScopeRequests::request($run->fresh(), $child, '1.20', 300, 'different reason');
        $this->assertSame($event->id, $duplicate->id);
        $this->assertSame($context->toArray(), CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $parent));
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
        $claimAfter = $run->tasks()
            ->sole()
            ->getAttributes();
        $this->assertSame('2026-10-03T00:00:10.000000Z', $run->tasks()->sole()->lease_expires_at->toISOString());
        unset($claimBefore['lease_expires_at'], $claimAfter['lease_expires_at'], $claimBefore['updated_at'], $claimAfter['updated_at']);
        $this->assertSame($claimBefore, $claimAfter);
        $this->assertRunCancellationUntouched($run);
    }

    #[DataProvider('requestHostingIntervals')]
    public function testAcceptanceShortensLiveOwnershipWithoutExtendingAnExistingLease(
        int $budget,
        int $configuredLease,
        int $existingLease,
        int $expectedInterval,
    ): void {
        [, $run, , $scope] = $this->scopeTree('request-interval');
        config()
            ->set('workflows.v2.workflow_task_lease_seconds', $configuredLease);
        $task = $run->tasks()
            ->sole();
        $task->forceFill([
            'lease_expires_at' => now()
                ->addSeconds($existingLease),
        ])->save();
        $before = $task->getAttributes();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', $budget);
        $after = $task->fresh()
            ->getAttributes();
        $this->assertSame(
            now()
                ->addSeconds($expectedInterval)
                ->toISOString(),
            $task->fresh()
                ->lease_expires_at->toISOString()
        );
        unset($before['lease_expires_at'], $after['lease_expires_at'], $before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame(
            now()
                ->addSeconds($budget)
                ->toISOString(),
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );
        $this->assertSame(
            now()
                ->addSeconds($budget)
                ->toISOString(),
            CancellationScopeRequests::context($run->fresh(), $scope)->deadline()->toISOString()
        );
        $this->assertSame($request->id, CancellationScopeRequests::request($run, $scope, '1.20', 3600)->id);
        $this->assertRunCancellationUntouched($run);
    }

    public static function requestHostingIntervals(): iterable
    {
        yield 'ordinary lease' => [30, 300, 300, 10];
        yield 'smaller configured lease' => [30, 5, 300, 5];
        yield 'short original lease is preserved' => [30, 300, 4, 4];
        yield 'short scope budget preserves shared hosting' => [3, 300, 300, 10];
    }

    public function testAcceptanceMakesPendingClaimRecoverableWithoutLeasingIt(): void
    {
        [, $run, , $scope] = $this->scopeTree('request-pending');
        $task = $run->tasks()
            ->sole();
        $task->forceFill([
            'status' => TaskStatus::Ready,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();
        $before = $task->fresh()
            ->getAttributes();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(
            '2026-10-03T00:00:30.000000Z',
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->claimStatus($task->id, 'first-owner')['claimed']);
        $this->assertSame('2026-10-03T00:00:10.000000Z', $task->fresh()->lease_expires_at->toISOString());
        $this->assertNull(CancellationScopeDelivery::recorded($run->fresh(), $scope));
        $this->assertRunCancellationUntouched($run);
    }

    public function testAcceptanceWakesWaitingRunWithoutDeliveringOrDuplicatingAnOpenClaim(): void
    {
        [, $run, , $scope] = $this->scopeTree('request-sleeping');
        $original = $run->tasks()
            ->sole();
        $original->forceFill([
            'status' => TaskStatus::Completed,
            'lease_owner' => null,
            'lease_expires_at' => null,
        ])->save();
        $run->forceFill([
            'status' => RunStatus::Waiting,
        ])->save();

        $accepted = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $task = $run->tasks()
            ->where('status', TaskStatus::Ready)->sole();
        $this->assertNotSame($original->id, $task->id);
        $this->assertSame(TaskType::Workflow, $task->task_type);
        $this->assertNull($task->lease_owner);
        $this->assertNull($task->lease_expires_at);
        $this->assertSame($run->namespace, $task->namespace);
        $this->assertSame($run->connection, $task->connection);
        $this->assertSame($run->queue, $task->queue);
        $this->assertSame($run->compatibility, $task->compatibility);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $task->available_at->toISOString());
        $this->assertSameJsonObject([
            'resume_source_kind' => 'cancellation_scope_request',
            'resume_source_id' => $accepted->id,
            'workflow_command_id' => $accepted->payload['request_id'],
            'scope_id' => $scope,
        ], $task->payload);
        $this->assertSame($task->id, WorkflowRunSummary::query()->findOrFail($run->id)->next_task_id);
        $this->assertSame(RunStatus::Waiting, $run->fresh()->status);
        $this->assertSame($accepted->id, CancellationScopeRequests::request($run, $scope, '1.20', 3600)->id);
        $this->assertSame(2, $run->tasks()->count());
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
        $this->assertSame(0, $run->historyEvents()->whereIn('event_type', [
            HistoryEventType::CancellationScopeDeliveryPrepared,
            HistoryEventType::CancellationScopeDelivered,
        ])->count());
        $this->assertRunCancellationUntouched($run);
    }

    public function testAcceptanceDoesNotReviveAnExpiredClaim(): void
    {
        [, $run, , $scope] = $this->scopeTree('request-expired');
        $task = $run->tasks()
            ->sole();
        $task->forceFill([
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $before = $task->fresh()
            ->getAttributes();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(
            '2026-10-03T00:00:30.000000Z',
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );
        try {
            CancellationScopeDelivery::prepare(
                $run,
                $task,
                $scope,
                $request->payload['request_id'],
                3,
                'local_activity',
                '1.20'
            );
            $this->fail('An expired owner must not prepare the cancellation.');
        } catch (LogicException $exception) {
            $this->assertSame('cancellation_scope_workflow_claim_mismatch', $exception->getMessage());
        }
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDeliveryPrepared)->count()
        );
        $this->assertRunCancellationUntouched($run);
    }

    public function testAcceptanceLeavesActivityOwnershipAndOtherRunsAlone(): void
    {
        [, $run, $parent, $scope] = $this->scopeTree('request-isolation');
        $activity = $run->tasks()
            ->sole()
            ->replicate();
        $activity->forceFill([
            'task_type' => TaskType::Activity,
        ])->save();
        [, $other] = $this->scopeTree('request-other-run');
        $activityBefore = $activity->fresh()
            ->getAttributes();
        $otherClaimBefore = $other->tasks()
            ->sole()
            ->getAttributes();
        CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $this->assertSame($activityBefore, $activity->fresh()->getAttributes());
        $this->assertSame($otherClaimBefore, $other->tasks()->sole()->getAttributes());
        $this->assertNull($other->fresh()->cancellation_scope_recovery_until);
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $parent));
        $this->assertSame(
            '2026-10-03T00:00:10.000000Z',
            $run->tasks()
                ->where('task_type', TaskType::Workflow)->sole()->lease_expires_at->toISOString()
        );
        $this->assertRunCancellationUntouched($run);
    }

    public function testAcceptanceProjectsAuthorityCeilingWithoutChangingAcceptedDeadline(): void
    {
        [, $run, , $scope] = $this->scopeTree('request-ceiling');
        $run->forceFill([
            'execution_deadline_at' => now()
                ->addSeconds(5),
        ])->save();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 30);
        $this->assertSame(
            '2026-10-03T00:00:05.000000Z',
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );
        $this->assertSame(
            '2026-10-03T00:00:30.000000Z',
            CancellationScopeRequests::context($run->fresh(), $scope)->deadline()->toISOString()
        );
        $this->assertSame('2026-10-03T00:00:10.000000Z', $run->tasks()->sole()->lease_expires_at->toISOString());
        $this->assertSame($request->id, CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 300)->id);
        $this->assertRunCancellationUntouched($run);
    }

    public function testAcceptanceProjectionKeepsHeartbeatsRecoverableOnlyDuringOriginalScopeBudget(): void
    {
        [, $run, , $scope] = $this->scopeTree('request-heartbeat');
        config()
            ->set('workflows.v2.workflow_task_lease_seconds', 300);
        $task = $run->tasks()
            ->sole();
        $request = CancellationScopeRequests::request($run, $scope, '1.20', 15);
        Carbon::setTestNow('2026-10-03T00:00:05Z');
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->heartbeat($task->id)['renewed']);
        $this->assertSame('2026-10-03T00:00:15.000000Z', $task->fresh()->lease_expires_at->toISOString());
        $this->advanceWithWorkflowHeartbeats($task, now()->copy()->addSeconds(11));
        $this->assertTrue(app(DefaultWorkflowTaskBridge::class)->heartbeat($task->id)['renewed']);
        $this->assertSame('2026-10-03T00:05:16.000000Z', $task->fresh()->lease_expires_at->toISOString());
        $this->assertFalse(CancellationScopeRequests::authority($run->fresh(), $scope)['active']);
        $this->assertSame(
            '2026-10-03T00:00:15.000000Z',
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );
        $this->assertSame($request->id, CancellationScopeRequests::request($run->fresh(), $scope, '1.20', 300)->id);
        $this->assertRunCancellationUntouched($run);
    }

    public function testInheritanceHasDistinctAddressIdentityAndOneOriginalRootBudget(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('inherit');
        $first = CancellationScopeRequests::request($run, $parent, '1.20', 30, 'original reason');
        $acceptedClaim = $run->tasks()
            ->sole()
            ->getAttributes();
        Carbon::setTestNow('2026-10-03T00:00:09Z');
        $inherited = CancellationScopeRequests::request(
            $run,
            $child,
            '1.20',
            300,
            'ignored descendant reason',
            parentScopeId: $parent
        );
        $context = CancellationScopeRequests::context($run->fresh(), $child);
        $this->assertNotSame($first->payload['request_id'], $context->requestId);
        $this->assertSame($first->payload['request_id'], $context->parentRequestId);
        $this->assertSame($first->payload['request_id'], $context->rootContext->rootRequestId);
        $this->assertSame([$parent, $child], array_column($context->lineage, 'scope_id'));
        $this->assertSame('original reason', $context->rootContext->reason);
        $this->assertSame('2026-10-03T00:00:00.000000Z', $context->requestedAt()->toISOString());
        $this->assertSame('2026-10-03T00:00:30.000000Z', $context->deadline()->toISOString());
        $this->assertSame($context->rootDeadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertSame($acceptedClaim, $run->tasks()->sole()->getAttributes());
        $this->assertSame(
            '2026-10-03T00:00:30.000000Z',
            $run->fresh()
                ->cancellation_scope_recovery_until->toISOString()
        );
        $this->assertSame(
            $inherited->id,
            CancellationScopeRequests::request($run, $child, '1.20', 3600, parentScopeId: $parent)->id
        );
        $this->assertRunCancellationUntouched($run);
    }

    public function testCompetingAncestorRootIsVisibleAndShortensAuthorityWithoutRewritingAcceptedBudget(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('conflict', true);
        CancellationScopeRequests::request($run, $child, '1.20', 30, 'independent scope');
        $accepted = CancellationScopeRequests::context($run, $child)->toArray();
        $ancestor = CancellationScopeRequests::request($run, $parent, '1.20', 10, 'parent budget');
        $conflict = CancellationScopeRequests::request($run, $child, '1.20', 300, parentScopeId: $parent);
        $this->assertSame(HistoryEventType::CancellationScopeRequestConflicted, $conflict->event_type);
        $this->assertSame('cancellation_root_conflict', $conflict->payload['reason']);
        $this->assertSameJsonObject($accepted, $conflict->payload['accepted_cancellation']);
        $incoming = $conflict->payload['incoming_cancellation'];
        $this->assertSame($ancestor->payload['request_id'], $incoming['root_context']['root_request_id']);
        $this->assertSame('2026-10-03T00:00:10.000000Z', $incoming['root_context']['cleanup_deadline_at']);
        $this->assertSame($accepted, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertSame([
            'active' => true,
            'deadline_at' => '2026-10-03T00:00:10.000000Z',
        ], CancellationScopeRequests::authority($run, $child));
        $timeline = collect(HistoryTimeline::fromHistory($run->fresh()))->firstWhere('id', $conflict->id);
        $this->assertSame('cancellation_scope', $timeline['kind']);
        $this->assertSameJsonObject($accepted, $timeline['cancellation_scope']['accepted_cancellation']);
        $this->assertSameJsonObject($incoming, $timeline['cancellation_scope']['incoming_cancellation']);
        $this->assertSame(
            $conflict->id,
            CancellationScopeRequests::request($run->fresh(), $child, '1.20', 3600, parentScopeId: $parent)->id
        );
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequestConflicted)->count()
        );
        Carbon::setTestNow('2026-10-03T00:00:10Z');
        $this->assertFalse(CancellationScopeRequests::authority($run, $child)['active']);
        $this->assertSame($accepted, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertRunCancellationUntouched($run);
    }

    public function testRunRequestIsCanonicalParentAndOriginalRunFieldsKeepTheirMeaning(): void
    {
        [$workflow, $run, $parent] = $this->scopeTree('run-parent');
        $runRequest = $workflow->requestCancellation('whole run', 30)
            ->cancellationContext();
        Carbon::setTestNow('2026-10-03T00:00:08Z');
        $event = CancellationScopeRequests::request($run, $parent, '1.20', 300, parentScopeId: 'root');
        $context = CancellationScopeRequests::context($run->fresh(), $parent);
        $this->assertSame($runRequest->requestId, $context->rootContext->rootRequestId);
        $this->assertSame($runRequest->requestId, $context->parentRequestId);
        $this->assertSame(['root', $parent], array_column($context->lineage, 'scope_id'));
        $this->assertSame($runRequest->deadline()->toISOString(), $context->deadline()->toISOString());
        $this->assertSame($runRequest->requestId, $run->fresh()->cancellation_request_command_id);
        $this->assertNotSame($runRequest->requestId, $event->payload['request_id']);
        $this->assertSame(
            $runRequest->deadline()
                ->toISOString(),
            CancellationScopeRequests::authority($run, $parent)['deadline_at']
        );
    }

    public function testRunRequestPropagatesOriginalIdentityAndDeadlineToUnshieldedScopes(): void
    {
        [$workflow, $run, $parent, $child] = $this->scopeTree('run-propagation');
        $root = $workflow->withCommandContext(
            CommandContext::phpApi()->withPrincipal('operator', 'operator-fixture', 'Operator')
        )->requestCancellation('release the whole tree', 30)
            ->cancellationContext();

        $parentContext = CancellationScopeRequests::context($run->fresh(), $parent);
        $childContext = CancellationScopeRequests::context($run->fresh(), $child);
        $this->assertNotNull($parentContext);
        $this->assertNotNull($childContext);
        $this->assertSame(['root', $parent, $child], array_column($childContext->lineage, 'scope_id'));
        $this->assertSame($parentContext->requestId, $childContext->parentRequestId);
        foreach ([$parentContext, $childContext] as $context) {
            $this->assertSame($root->toArray(), $context->rootContext->toArray());
            $this->assertSame($root->deadline()->toISOString(), $context->deadline()->toISOString());
            $this->assertSame($root->requestedAt()->toISOString(), $context->requestedAt()->toISOString());
        }
        $this->assertSame(
            2,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeDelivered)->count()
        );
        $this->assertFalse($run->fresh()->status->isTerminal());

        $historyCount = $run->historyEvents()
            ->count();
        Carbon::setTestNow(now()->addSeconds(10));
        $duplicate = $workflow->requestCancellation('replacement reason', 300)
            ->cancellationContext();
        $this->assertSame($root->toArray(), $duplicate->toArray());
        $this->assertSame($historyCount, $run->historyEvents()->count());
        $this->assertSame(
            $childContext->toArray(),
            CancellationScopeRequests::context($run->fresh(), $child)->toArray()
        );
    }

    public function testRunRequestDoesNotPropagateThroughAShield(): void
    {
        [$workflow, $run, $parent, $shield] = $this->scopeTree('run-shield', true);
        $task = $run->tasks()
            ->sole();
        $shieldChild = CancellationScopeHistory::open($run, $task, 3, '1.20', $shield)->payload['scope_id'];
        $sibling = CancellationScopeHistory::open($run, $task, 4, '1.20')->payload['scope_id'];
        $root = $workflow->requestCancellation('whole run', 30)
            ->cancellationContext();

        foreach ([$parent, $sibling] as $scope) {
            $context = CancellationScopeRequests::context($run->fresh(), $scope);
            $this->assertNotNull($context);
            $this->assertSame($root->requestId, $context->rootContext->rootRequestId);
            $this->assertSame($root->deadline()->toISOString(), $context->deadline()->toISOString());
        }
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $shield));
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $shieldChild));
        $this->assertSame(
            $root->deadline()
                ->toISOString(),
            CancellationScopeRequests::authority($run->fresh(), $shieldChild)['deadline_at']
        );
        $this->assertSame(
            2,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
    }

    public function testRunRequestPreservesAnIndependentScopeRequestAndCapsItsAuthority(): void
    {
        [$workflow, $run, $parent, $child] = $this->scopeTree('run-conflict');
        $accepted = CancellationScopeRequests::request($run, $child, '1.20', 300, 'independent cleanup');
        $original = CancellationScopeRequests::context($run->fresh(), $child)->toArray();
        $root = $workflow->requestCancellation('whole run', 30)
            ->cancellationContext();

        $this->assertSame($original, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $this->assertSame($accepted->id, CancellationScopeRequests::request($run->fresh(), $child, '1.20', 3600)->id);
        $conflict = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeRequestConflicted)->sole();
        $this->assertSameJsonObject($original, $conflict->payload['accepted_cancellation']);
        $this->assertSame($parent, $conflict->payload['parent_scope_id']);
        $this->assertSame(
            $root->requestId,
            $conflict->payload['incoming_cancellation']['root_context']['root_request_id']
        );
        $this->assertSame(
            $root->deadline()
                ->toISOString(),
            CancellationScopeRequests::authority($run->fresh(), $child)['deadline_at']
        );
        Carbon::setTestNow(now()->addSeconds(30));
        $this->assertFalse(CancellationScopeRequests::authority($run->fresh(), $child)['active']);
        $this->assertSame($original, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
        $duplicate = $workflow->requestCancellation('duplicate', 3600)
            ->cancellationContext();
        $this->assertSame($root->requestId, $duplicate->requestId);
        $this->assertSame(
            1,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequestConflicted)->count()
        );
    }

    public function testRunRequestRollsBackTheWholePropagationWhenADescendantIsInvalid(): void
    {
        [$workflow, $run, $parent, $child] = $this->scopeTree('run-atomicity');
        $accepted = CancellationScopeRequests::request($run, $child, '1.20', 300);
        $payload = $accepted->payload;
        $payload['cancellation']['lineage'][0]['scope_id'] = 'fabricated';
        $accepted->forceFill([
            'payload' => $payload,
        ])->save();
        $historyCount = $run->historyEvents()
            ->count();
        $claimBefore = $run->tasks()
            ->sole()
            ->getAttributes();

        try {
            $workflow->requestCancellation('whole run', 30);
            $this->fail('Invalid descendant authority must not leave a partial run request.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_request_history_invalid', $error->getMessage());
        }
        $this->assertSame($historyCount, $run->historyEvents()->count());
        $this->assertNull(CancellationScopeRequests::context($run->fresh(), $parent));
        $this->assertSame($claimBefore, $run->tasks()->sole()->getAttributes());
        $this->assertRunCancellationUntouched($run);
    }

    #[DataProvider('authorityCeilings')]
    public function testShieldedIndependentScopeCannotOutliveRunAuthority(string $ceiling): void
    {
        [$workflow, $run, , $child] = $this->scopeTree('ceiling', true);
        CancellationScopeRequests::request($run, $child, '1.20', 30);
        $original = CancellationScopeRequests::context($run, $child)->toArray();
        if ($ceiling === 'cancellation') {
            $workflow->requestCancellation('run ceiling', 12);
        } else {
            $run->forceFill([
                $ceiling => now()
                    ->addSeconds(12),
            ])->save();
        }
        $this->assertSame(
            '2026-10-03T00:00:12.000000Z',
            CancellationScopeRequests::authority($run, $child)['deadline_at']
        );
        Carbon::setTestNow('2026-10-03T00:00:12Z');
        $this->assertFalse(CancellationScopeRequests::authority($run, $child)['active']);
        $this->assertSame($original, CancellationScopeRequests::context($run->fresh(), $child)->toArray());
    }

    public static function authorityCeilings(): iterable
    {
        yield 'run cancellation' => ['cancellation'];
        yield 'execution deadline' => ['execution_deadline_at'];
        yield 'run deadline' => ['run_deadline_at'];
    }

    public function testTerminalRunReturnsAcceptedRetryButRejectsNewScopeRequest(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('terminal');
        $event = CancellationScopeRequests::request($run, $child, '1.20');
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $this->assertSame($event->id, CancellationScopeRequests::request($run, $child, '1.20', 300)->id);
        $this->assertFalse(CancellationScopeRequests::authority($run, $child)['active']);
        $this->expectExceptionMessage('cancellation_scope_authority_expired');
        CancellationScopeRequests::request($run, $parent, '1.20');
    }

    public function testExpiredParentCannotGrantNewDescendantBudget(): void
    {
        [, $run, $parent, $child] = $this->scopeTree('expired');
        CancellationScopeRequests::request($run, $parent, '1.20', 10);
        Carbon::setTestNow('2026-10-03T00:00:10Z');
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $child, '1.20', 300, parentScopeId: $parent);
            $this->fail('An expired parent must not grant new authority.');
        } catch (LogicException $error) {
            $this->assertSame('cancellation_scope_authority_expired', $error->getMessage());
        }
        $this->assertNull(CancellationScopeRequests::context($run, $child));
        $this->assertSame($before, $run->historyEvents()->count());
    }

    #[DataProvider('invalidScopes')]
    public function testUnknownForeignAndImplicitRootAddressesRefuseBeforeWriting(string $kind): void
    {
        [, $run] = $this->scopeTree('invalid');
        $scope = $kind === 'root' ? 'root' : 'missing';
        if ($kind === 'foreign') {
            [, , $scope] = $this->scopeTree('foreign');
        }
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $scope, '1.20');
            $this->fail('An address outside the exact run must be refused.');
        } catch (LogicException $error) {
            $this->assertSame(
                $kind === 'root' ? 'root_scope_requires_run_cancellation' : 'cancellation_scope_not_recorded',
                $error->getMessage()
            );
        }
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertRunCancellationUntouched($run);
    }

    public static function invalidScopes(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'foreign' => ['foreign'];
        yield 'implicit run root' => ['root'];
    }

    #[DataProvider('invalidParents')]
    public function testInheritanceRejectsUnrecordedRequestAndWrongParent(string $kind): void
    {
        [, $run, $parent, $child] = $this->scopeTree('invalid-parent');
        $requestedParent = $kind === 'unrequested' ? $parent : 'root';
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $child, '1.20', parentScopeId: $requestedParent);
            $this->fail('Inheritance must use its accepted canonical immediate parent.');
        } catch (LogicException $error) {
            $this->assertSame(
                $kind === 'unrequested' ? 'cancellation_scope_parent_request_unavailable' : 'cancellation_scope_parent_mismatch',
                $error->getMessage()
            );
        }
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public static function invalidParents(): iterable
    {
        yield 'parent has no request' => ['unrequested'];
        yield 'skip canonical edge' => ['wrong'];
    }

    public function testCorruptCanonicalRequestAddressIsRefusedOnFreshRead(): void
    {
        [, $run, , $child] = $this->scopeTree('corrupt');
        $event = CancellationScopeRequests::request($run, $child, '1.20');
        $payload = $event->payload;
        $payload['cancellation']['lineage'][0]['scope_id'] = 'fabricated';
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $this->expectExceptionMessage('cancellation_scope_request_history_invalid');
        CancellationScopeRequests::context($run->fresh(), $child);
    }

    #[DataProvider('invalidProtocols')]
    public function testRequestsRequireKnownCandidateProtocol(string $version): void
    {
        [, $run, $parent] = $this->scopeTree('protocol');
        $this->expectExceptionMessage('cancellation_scope_requires_protocol_1_20');
        CancellationScopeRequests::request($run, $parent, $version);
    }

    public static function invalidProtocols(): iterable
    {
        yield 'published default' => ['1.19'];
        yield 'unknown major' => ['2.0'];
    }

    #[DataProvider('invalidTimeouts')]
    public function testRequestBudgetMustBeFiniteAndBounded(int $timeout): void
    {
        [, $run, $parent] = $this->scopeTree('invalid-budget');
        $before = $run->historyEvents()
            ->count();
        try {
            CancellationScopeRequests::request($run, $parent, '1.20', $timeout);
            $this->fail('Unbounded or empty cleanup budgets must be refused.');
        } catch (LogicException $error) {
            $this->assertSame('invalid_scope_cancellation_request', $error->getMessage());
        }
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public static function invalidTimeouts(): iterable
    {
        yield 'zero seconds' => [0];
        yield 'above maximum' => [3601];
    }

    public function testWholeRunProjectionCannotForgeTheInheritedRootBudget(): void
    {
        [$workflow, $run, $parent] = $this->scopeTree('corrupt-run');
        $workflow->requestCancellation('real request', 30);
        $run->refresh()
            ->forceFill([
                'cancellation_deadline_at' => now()
                    ->addSeconds(300),
            ])->save();
        $this->expectExceptionMessage('cancellation_scope_run_context_mismatch');
        CancellationScopeRequests::request($run, $parent, '1.20', parentScopeId: 'root');
    }

    #[DataProvider('requestRaces')]
    public function testConcurrentRequestsKeepOneAcceptedAddressAndExplicitCompetingRoot(
        bool $winnerInherited,
        bool $loserInherited,
    ): void {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'pgsql'], true)
            || ($driver === 'mysql' && str_contains(
                strtolower((string) DB::selectOne('SELECT VERSION() AS version')->version),
                'mariadb'
            ))
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('MySQL or PostgreSQL row-lock observation and process control are required.');
        }
        [, $run, $parent, $child] = $this->scopeTree('race');
        CancellationScopeRequests::request($run, $parent, '1.20', 30, 'ancestor');
        $parentContext = CancellationScopeRequests::context($run, $parent)->toArray();
        Carbon::setTestNow(now()->addSeconds(5));
        DB::purge();
        $actors = [];
        try {
            $actors['winner'] = $this->forkScopeRequest($run->id, $child, $winnerInherited ? $parent : null, true);
            $this->readActor($actors['winner']);
            $actors['loser'] = $this->forkScopeRequest($run->id, $child, $loserInherited ? $parent : null, false);
            $ready = $this->readActor($actors['loser']);
            fwrite($actors['winner']['socket'], "go\n");
            $uncommitted = $this->readActor($actors['winner']);
            $this->assertSame(HistoryEventType::CancellationScopeRequested->value, $uncommitted['type']);
            fwrite($actors['loser']['socket'], "go\n");
            $query = $driver === 'mysql'
                ? 'SELECT COUNT(*) AS waiting FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?'
                : 'SELECT COUNT(*) AS waiting FROM pg_locks WHERE pid = ? AND NOT granted';
            $until = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne($query, [$ready['connection_id']])->waiting;
                if ($waiting > 0) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $until);
            $this->assertGreaterThan(0, $waiting, 'The second actor must actually wait on the database lock.');
            $this->assertNull(CancellationScopeRequests::context($run->fresh(), $child));
            fwrite($actors['winner']['socket'], "commit\n");
            $winner = $this->finishActor($actors['winner']);
            unset($actors['winner']);
            $loser = $this->finishActor($actors['loser']);
            unset($actors['loser']);
        } finally {
            foreach ($actors as $actor) {
                posix_kill($actor['pid'], SIGKILL);
                pcntl_waitpid($actor['pid'], $status);
                fclose($actor['socket']);
            }
            DB::purge();
            DB::reconnect();
        }
        $accepted = CancellationScopeRequests::context($run->fresh(), $child)->toArray();
        $this->assertSameJsonObject($winner['payload']['cancellation'], $accepted);
        $this->assertSame(
            $winnerInherited ? '2026-10-03T00:00:30.000000Z' : '2026-10-03T00:00:25.000000Z',
            $accepted['lineage'][count($accepted['lineage']) - 1]['cleanup_deadline_at']
        );
        if (! $winnerInherited && $loserInherited) {
            $this->assertSame(HistoryEventType::CancellationScopeRequestConflicted->value, $loser['type']);
            $this->assertSameJsonObject($accepted, $loser['payload']['accepted_cancellation']);
            $this->assertSame(
                $parentContext['root_context']['root_request_id'],
                $loser['payload']['incoming_cancellation']['root_context']['root_request_id']
            );
            $this->assertSame('cancellation_root_conflict', $loser['payload']['reason']);
        } else {
            $this->assertSame($winner['id'], $loser['id']);
            $this->assertSameJsonObject($winner['payload'], $loser['payload']);
        }
        $this->assertSame(
            2,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::CancellationScopeRequested)->count()
        );
        $this->assertSameJsonObject(
            $parentContext,
            CancellationScopeRequests::context($run->fresh(), $parent)->toArray()
        );
        $this->assertRunCancellationUntouched($run);
    }

    public static function requestRaces(): iterable
    {
        yield 'direct duplicate 20 versus 300 seconds' => [false, false];
        yield 'direct duplicate retains inherited root' => [true, false];
        yield 'ancestor conflicts with direct winner' => [false, true];
    }

    /**
     * @return array{pid: int, socket: resource}
     */
    private function forkScopeRequest(string $runId, string $scope, ?string $parent, bool $holdCommit): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            stream_set_timeout($sockets[1], 10);
            $ok = false;
            try {
                DB::reconnect();
                $query = DB::connection()->getDriverName() === 'mysql'
                    ? 'SELECT CONNECTION_ID() AS connection_id' : 'SELECT pg_backend_pid() AS connection_id';
                fwrite($sockets[1], json_encode([
                    'connection_id' => (int) DB::selectOne($query)->connection_id,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                if (fgets($sockets[1]) !== "go\n") {
                    throw new RuntimeException('Scope cancellation actor was not released.');
                }
                if ($holdCommit) {
                    DB::beginTransaction();
                }
                $event = CancellationScopeRequests::request(
                    WorkflowRun::query()->findOrFail($runId),
                    $scope,
                    '1.20',
                    $holdCommit ? 20 : 300,
                    $holdCommit ? 'winner' : 'duplicate',
                    parentScopeId: $parent,
                );
                $result = [
                    'id' => $event->id,
                    'type' => $event->event_type->value,
                    'payload' => $event->payload,
                ];
                if ($holdCommit) {
                    fwrite($sockets[1], json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
                    if (fgets($sockets[1]) !== "commit\n") {
                        throw new RuntimeException('Scope cancellation commit was not released.');
                    }
                    DB::commit();
                }
                fwrite($sockets[1], json_encode([
                    'result' => $result,
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
                $ok = true;
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => $error::class . ': ' . $error->getMessage(),
                ], JSON_THROW_ON_ERROR) . PHP_EOL);
            } finally {
                DB::disconnect();
                fclose($sockets[1]);
            }
            exit($ok ? 0 : 1);
        }
        fclose($sockets[1]);
        stream_set_timeout($sockets[0], 10);
        return [
            'pid' => $pid,
            'socket' => $sockets[0],
        ];
    }

    /** @param array{pid: int, socket: resource} $actor
     * @return array<string, mixed>
     */
    private function readActor(array $actor): array
    {
        $line = fgets($actor['socket']);
        $this->assertIsString($line, 'Scope cancellation actor did not respond.');
        $result = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        return $result;
    }

    /** @param array{pid: int, socket: resource} $actor
     * @return array<string, mixed>
     */
    private function finishActor(array $actor): array
    {
        $message = $this->readActor($actor);
        pcntl_waitpid($actor['pid'], $status);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        fclose($actor['socket']);
        return $message['result'];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun, string, string}
     */
    private function scopeTree(string $name, bool $shield = false): array
    {
        $workflow = WorkflowStub::make(TestSignalWorkflow::class, 'scope-request-' . $name);
        $workflow->start();
        $run = $workflow->run()
            ->fresh();
        $task = $run->tasks()
            ->where('task_type', TaskType::Workflow)->sole();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'scope-request-fixture',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinutes(5),
        ])->save();
        $parent = CancellationScopeHistory::open($run, $task, 1, '1.20')->payload['scope_id'];
        $child = CancellationScopeHistory::open($run, $task, 2, '1.20', $parent, $shield)->payload['scope_id'];
        return [$workflow, $run, $parent, $child];
    }

    private function assertRunCancellationUntouched(WorkflowRun $run): void
    {
        $run = $run->fresh();
        $this->assertFalse($run->status->isTerminal());
        $this->assertNull($run->cancellation_request_command_id);
        $this->assertNull($run->cancellation_requested_at);
        $this->assertNull($run->cancellation_deadline_at);
        $this->assertSame(0, $run->commands()->where('command_type', 'request_cancellation')->count());
    }
}
