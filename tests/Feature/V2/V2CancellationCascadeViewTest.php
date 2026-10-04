<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestParentChildPolicyWorkflow;
use Tests\Fixtures\V2\TestParentCloseCooperativeWorkflow;
use Tests\TestCase;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\ScopedCancellationContext;
use Workflow\V2\Support\CancellationCascadeView;
use Workflow\V2\WorkflowStub;

final class V2CancellationCascadeViewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
        Carbon::setTestNow('2026-10-02T00:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testOneViewRetainsTheRootBudgetThroughChildCleanupAndDuplicateRequests(): void
    {
        [$parent, $child] = $this->start();
        $request = $parent->requestCancellation('maintenance', 30);
        $context = $request->cancellationContext();
        $this->assertNotNull($context);
        $requested = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($requested);
        $this->assertCount(2, $requested['runs']);
        $this->assertTrue($requested['inspection_complete']);
        $this->assertSame('requested', $requested['runs'][0]['lifecycle']);
        $this->assertNull($requested['runs'][1]['request']);
        $this->assertCount(1, $requested['edges']);

        $this->runTask($parent, TaskType::Workflow);
        $this->runTask($child, TaskType::Workflow);
        $cleaning = CancellationCascadeView::forRun($child->run()->fresh());
        $this->assertNotNull($cleaning);
        $this->assertSame($child->runId(), $cleaning['selected_run_id']);
        $this->assertSame($context->rootRequestId, $cleaning['root']['root_request_id']);
        $this->assertSame($context->deadline()->toISOString(), $cleaning['root']['cleanup_deadline_at']);
        $this->assertTrue($cleaning['inspection_complete']);
        $this->assertSame('requested', $cleaning['runs'][0]['lifecycle']);
        $this->assertSame('cleaning_up', $cleaning['runs'][1]['lifecycle']);
        $this->assertTrue($cleaning['runs'][1]['same_root_budget']);
        $this->assertNotSame(
            $cleaning['runs'][0]['request']['request_id'],
            $cleaning['runs'][1]['request']['request_id']
        );

        Carbon::setTestNow(now()->addSeconds(2));
        $this->runTask($child, TaskType::Timer);
        $this->runTask($child, TaskType::Workflow);
        $this->runTask($parent, TaskType::Workflow);
        $finished = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($finished);
        $this->assertTrue($finished['inspection_complete']);
        $this->assertCount(2, $finished['runs']);
        foreach ($finished['runs'] as $node) {
            $this->assertSame('cancelled', $node['lifecycle']);
            $this->assertSame('WorkflowCancelled', $node['terminal_event_type']);
            $this->assertSame('completed', $node['cleanup']['outcome']);
            $this->assertSame($context->deadline()->toISOString(), $node['cleanup']['cleanup_deadline_at']);
            $this->assertTrue($node['same_root_budget']);
        }
        $this->assertSame($request->commandId(), $parent->requestCancellation('duplicate', 300)->commandId());
        Carbon::setTestNow('2026-12-01T00:00:00Z');
        $this->assertSame($finished, CancellationCascadeView::forRun($parent->run()->fresh()));
    }

    public function testForeignNamespaceReferencesCannotExposeTheirRunOrRequest(): void
    {
        [$parent] = $this->start();
        $parent->requestCancellation('public test reason', 30);
        [$foreign] = $this->start();
        $foreign->run()
            ->forceFill([
                'namespace' => 'foreign',
            ])->save();
        $foreign->run()
            ->instance->forceFill([
                'namespace' => 'foreign',
            ])->save();
        $foreign->requestCancellation('DO_NOT_EXPOSE_PRIVATE_REQUEST', 30);
        WorkflowLink::query()->create([
            'parent_workflow_instance_id' => $parent->id(),
            'parent_workflow_run_id' => $parent->runId(),
            'child_workflow_instance_id' => $foreign->id(),
            'child_workflow_run_id' => $foreign->runId(),
            'link_type' => 'child_workflow',
            'sequence' => 99,
            'is_primary_parent' => false,
        ]);

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertContains('related_run_unavailable', array_column($view['findings'], 'code'));
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($foreign->runId(), $encoded);
        $this->assertStringNotContainsString($foreign->id(), $encoded);
        $this->assertStringNotContainsString('DO_NOT_EXPOSE_PRIVATE_REQUEST', $encoded);
        $this->assertCount(2, $view['runs']);
    }

    public function testScopedChildKeepsTheOriginalRootAndItsNarrowerAuthorityInOneView(): void
    {
        [$parent, $child] = $this->start();
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->assertNotNull($root);
        $scope = ScopedCancellationContext::fromRunContext($root)->forDescendant(
            'inner-request',
            $parent->id(),
            $parent->runId(),
            'inner',
            $root->requestedAt()
                ->addSeconds(20)
        );
        $context = $this->recordScopedChild($child, $scope);

        foreach ([$parent, $child] as $selected) {
            $view = CancellationCascadeView::forRun($selected->run()->fresh());
            $this->assertNotNull($view);
            $this->assertTrue($view['inspection_complete']);
            $this->assertSame([], $view['findings']);
            $this->assertSame($root->rootRequestId, $view['root']['root_request_id']);
            $this->assertSame($root->deadline()->toISOString(), $view['root']['cleanup_deadline_at']);
            $node = collect($view['runs'])->firstWhere('run_id', $child->runId());
            $this->assertTrue($node['same_root_budget']);
            $this->assertSame($context->deadline()->toISOString(), $node['request']['cleanup_deadline_at']);
            $this->assertSame(
                $root->deadline()
                    ->toISOString(),
                $node['request']['scope_origin']['root_context']['cleanup_deadline_at']
            );
            $this->assertSame(['root', 'inner'], array_column($node['request']['scope_origin']['lineage'], 'scope_id'));
        }
    }

    public function testScopedOriginCannotExposeAnUnavailableAncestorAddress(): void
    {
        [$parent, $child] = $this->start();
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->assertNotNull($root);
        [$foreign] = $this->start();
        $foreign->run()
            ->forceFill([
                'namespace' => 'foreign',
            ])->save();
        $foreign->run()
            ->instance->forceFill([
                'namespace' => 'foreign',
            ])->save();
        $scope = ScopedCancellationContext::fromRunContext($root)->forDescendant(
            'DO_NOT_EXPOSE_PRIVATE_SCOPE_REQUEST',
            $foreign->id(),
            $foreign->runId(),
            'DO_NOT_EXPOSE_PRIVATE_SCOPE',
            $root->requestedAt()
                ->addSeconds(20)
        );
        $this->recordScopedChild($child, $scope);

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertContains('scope_origin_unavailable', array_column($view['findings'], 'code'));
        $node = collect($view['runs'])->firstWhere('run_id', $child->runId());
        $this->assertNull($node['request']['scope_origin']);
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);
        foreach ([$foreign->id(), $foreign->runId(), 'DO_NOT_EXPOSE_PRIVATE_SCOPE'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $encoded);
        }
    }

    public function testScopedOriginUsesTheSameRequestTextLimitAsItsVisibleRoot(): void
    {
        [$parent, $child] = $this->start();
        $root = $parent->requestCancellation(str_repeat('x', 8193), 30)
            ->cancellationContext();
        $this->assertNotNull($root);
        $this->recordScopedChild($child, ScopedCancellationContext::fromRunContext($root));

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $node = collect($view['runs'])->firstWhere('run_id', $child->runId());
        $this->assertSame(8192, strlen($node['request']['scope_origin']['root_context']['reason']));
        $this->assertFalse($view['inspection_complete']);
        $this->assertTrue($view['truncated']);
        $this->assertContains('request_text_limit', array_column($view['findings'], 'code'));
    }

    public function testAnExceededScopePathLimitRemainsAnIncompleteInspection(): void
    {
        [$parent, $child] = $this->start();
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->assertNotNull($root);
        $scope = ScopedCancellationContext::fromRunContext($root);
        for ($index = 1; $index <= 100; ++$index) {
            $scope = $scope->forDescendant(
                'scope-request-' . $index,
                $parent->id(),
                $parent->runId(),
                'scope-' . $index
            );
        }
        $this->recordScopedChild($child, $scope);

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $node = collect($view['runs'])->firstWhere('run_id', $child->runId());
        $this->assertNull($node['request']['scope_origin']);
        $this->assertFalse($view['inspection_complete']);
        $this->assertTrue($view['truncated']);
        $this->assertContains('scope_origin_limit', array_column($view['findings'], 'code'));
        $this->assertSame(100, $view['limits']['scope_origin_addresses']);
    }

    public function testScopedOriginCannotSubstituteADifferentOriginalRootBudget(): void
    {
        [$parent, $child] = $this->start();
        $root = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->assertNotNull($root);
        $snapshot = $root->toArray();
        $snapshot['cleanup_deadline_at'] = $root->requestedAt()->addSeconds(25)->toISOString();
        $changed = CancellationContext::fromArray($snapshot);
        $this->recordScopedChild($child, ScopedCancellationContext::fromRunContext($changed));

        $view = CancellationCascadeView::forRun($child->run()->fresh());
        $this->assertNotNull($view);
        $this->assertNull($view['root']);
        $this->assertFalse($view['inspection_complete']);
        $this->assertContains('root_request_mismatch', array_column($view['findings'], 'code'));
        $node = collect($view['runs'])->firstWhere('run_id', $child->runId());
        $this->assertFalse($node['same_root_budget']);
    }

    public function testMissingRequestHistoryRemainsAnIncompleteInspection(): void
    {
        [$parent] = $this->start();
        $parent->requestCancellation('maintenance', 30);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->runId())
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)->delete();

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertNull($view['root']);
        $this->assertNull($view['runs'][0]['request']);
        $this->assertContains('request_history_unavailable', array_column($view['findings'], 'code'));
    }

    public function testAnInaccessibleRootCannotBeExpandedFromAVisibleChildSnapshot(): void
    {
        [$parent, $child] = $this->start();
        $parent->requestCancellation('maintenance', 30);
        $this->runTask($parent, TaskType::Workflow);
        $parent->run()
            ->forceFill([
                'namespace' => 'foreign',
            ])->save();
        $parent->run()
            ->instance->forceFill([
                'namespace' => 'foreign',
            ])->save();

        $view = CancellationCascadeView::forRun($child->run()->fresh());
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertNull($view['root']);
        $this->assertNull($view['runs'][0]['request']);
        $this->assertContains('root_unavailable', array_column($view['findings'], 'code'));
        $this->assertStringNotContainsString($parent->runId(), json_encode($view, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $view['runs']);
    }

    public function testADeletedRequestProjectionDoesNotHideTheOriginalCanonicalBudget(): void
    {
        [$parent] = $this->start();
        $context = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->assertNotNull($context);
        $parent->run()
            ->forceFill([
                'cancellation_request_command_id' => null,
            ])->save();

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertSame($context->requestId, $view['root']['request_id']);
        $this->assertSame($context->deadline()->toISOString(), $view['root']['cleanup_deadline_at']);
        $this->assertContains('request_projection_mismatch', array_column($view['findings'], 'code'));
    }

    public function testIndependentChildCancellationIsExplainedWithoutReplacingEitherBudget(): void
    {
        [$parent, $child] = $this->start();
        $childContext = $child->requestCancellation('independent child', 45)
            ->cancellationContext();
        $parentContext = $parent->requestCancellation('parent request', 30)
            ->cancellationContext();
        $this->assertNotNull($childContext);
        $this->assertNotNull($parentContext);
        $this->runTask($parent, TaskType::Workflow);

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertTrue($view['inspection_complete']);
        $this->assertSame($parentContext->rootRequestId, $view['root']['root_request_id']);
        $this->assertSame($childContext->rootRequestId, $view['runs'][1]['request']['root_request_id']);
        $this->assertSame($childContext->deadline()->toISOString(), $view['runs'][1]['request']['cleanup_deadline_at']);
        $this->assertFalse($view['runs'][1]['same_root_budget']);
        $this->assertSame('cancellation_root_conflict', $view['runs'][0]['child_propagation'][0]['rejection_reason']);
        $this->assertSame('rejected', $view['runs'][0]['child_propagation'][0]['request_outcome']);
    }

    public function testParentCloseOriginKeepsItsCompletedParentAndOriginalSharedBudget(): void
    {
        $parent = WorkflowStub::make(TestParentCloseCooperativeWorkflow::class);
        $parent->start();
        $this->runTask($parent, TaskType::Workflow);
        foreach (WorkflowLink::query()->where('parent_workflow_run_id', $parent->runId())->get() as $link) {
            $this->runTask(WorkflowStub::loadRun($link->child_workflow_run_id), TaskType::Workflow);
        }
        Carbon::setTestNow(now()->addSecond());
        $this->runTask($parent, TaskType::Timer);
        $this->runTask($parent, TaskType::Workflow);

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertTrue($view['inspection_complete']);
        $this->assertSame('parent_close_policy', $view['root']['source']);
        $this->assertSame('completed', $view['runs'][0]['lifecycle']);
        $this->assertNull($view['runs'][0]['cleanup']);
        $this->assertCount(3, $view['runs']);
        foreach (array_slice($view['runs'], 1) as $child) {
            $this->assertSame('requested', $child['lifecycle']);
            $this->assertTrue($child['same_root_budget']);
            $this->assertSame($view['root']['cleanup_deadline_at'], $child['request']['cleanup_deadline_at']);
        }
        Carbon::setTestNow(now()->addSeconds(20));
        $this->assertSame($view, CancellationCascadeView::forRun($parent->run()->fresh()));
    }

    public function testSelectingAnHistoricalRunDoesNotFollowTheCurrentRunPointer(): void
    {
        [$parent] = $this->start();
        $context = $parent->requestCancellation('historical request', 30)
            ->cancellationContext();
        $this->assertNotNull($context);
        $selected = $parent->run()
            ->fresh();
        $replacement = $selected->replicate(['id']);
        $replacement->run_number = $selected->run_number + 1;
        $replacement->cancellation_request_command_id = null;
        $replacement->save();
        $selected->instance->forceFill([
            'current_run_id' => $replacement->id,
        ])->save();

        $view = CancellationCascadeView::forRun($selected);
        $this->assertNotNull($view);
        $this->assertSame($selected->id, $view['selected_run_id']);
        $this->assertSame($context->requestId, $view['root']['request_id']);
        $this->assertSame($selected->id, $view['runs'][0]['run_id']);
        $this->assertStringNotContainsString($replacement->id, json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testTerminalStatusWithoutTerminalHistoryCannotClaimFinishedCleanup(): void
    {
        [$parent, $child] = $this->start();
        $parent->requestCancellation('maintenance', 30);
        $this->runTask($parent, TaskType::Workflow);
        $this->runTask($child, TaskType::Workflow);
        Carbon::setTestNow(now()->addSeconds(2));
        $this->runTask($child, TaskType::Timer);
        $this->runTask($child, TaskType::Workflow);
        $this->runTask($parent, TaskType::Workflow);
        WorkflowHistoryEvent::query()->where('workflow_run_id', $parent->runId())
            ->where('event_type', HistoryEventType::WorkflowCancelled->value)->delete();

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertFalse($view['inspection_complete']);
        $this->assertSame('unknown', $view['runs'][0]['lifecycle']);
        $this->assertNull($view['runs'][0]['cleanup']);
        $this->assertContains('terminal_history_unavailable', array_column($view['findings'], 'code'));
    }

    public function testAnExceededHistoryWindowDoesNotClaimCompleteEvidence(): void
    {
        [$parent] = $this->start();
        $context = $parent->requestCancellation('maintenance', 30)
            ->cancellationContext();
        $this->assertNotNull($context);
        for ($index = 0; $index < 129; ++$index) {
            WorkflowHistoryEvent::record($parent->run()->fresh(), HistoryEventType::ActivityRetryScheduled, []);
        }

        $view = CancellationCascadeView::forRun($parent->run()->fresh());
        $this->assertNotNull($view);
        $this->assertTrue($view['truncated']);
        $this->assertFalse($view['inspection_complete']);
        $this->assertContains('history_limit', array_column($view['findings'], 'code'));
        $this->assertSame($context->deadline()->toISOString(), $view['root']['cleanup_deadline_at']);
        $this->assertSame(128, $view['limits']['history_events_per_run']);
    }

    /**
     * @return array{WorkflowStub, WorkflowStub}
     */
    private function start(): array
    {
        $parent = WorkflowStub::make(TestParentChildPolicyWorkflow::class);
        $parent->start(CancellationPolicy::WaitCancellationCompleted->value);
        $this->runTask($parent, TaskType::Workflow);
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $parent->runId())->sole();
        $child = WorkflowStub::loadRun($link->child_workflow_run_id);
        $this->runTask($child, TaskType::Workflow);
        return [$parent, $child];
    }

    private function recordScopedChild(WorkflowStub $child, ScopedCancellationContext $scope): CancellationContext
    {
        $context = CancellationContext::fromScopeContext(
            $scope,
            'scoped-child-request',
            $child->id(),
            $child->runId(),
            $scope->requestedAt()
                ->addSeconds(15)
        );
        $child->run()
            ->historyEvents()
            ->create([
                'sequence' => $child->run()
                    ->historyEvents()
                    ->max('sequence') + 1,
                'event_type' => HistoryEventType::CooperativeCancellationRequested,
                'workflow_command_id' => $context->requestId,
                'recorded_at' => now(),
                'payload' => [
                    'workflow_command_id' => $context->requestId,
                    'workflow_run_id' => $child->runId(),
                    'workflow_instance_id' => $child->id(),
                    'reason' => $context->reason,
                    'cleanup_deadline_at' => $context->deadline()
                        ->toISOString(),
                    'cancellation' => $context->toArray(),
                ],
            ]);
        $child->run()
            ->forceFill([
                'cancellation_request_command_id' => $context->requestId,
                'cancellation_requested_at' => now(),
                'cancellation_deadline_at' => $context->deadline(),
            ])->save();

        return $context;
    }

    private function runTask(WorkflowStub $workflow, TaskType $type): void
    {
        $task = WorkflowTask::query()->where('workflow_run_id', $workflow->runId())
            ->where('task_type', $type->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->firstOrFail();
        $job = $type === TaskType::Timer ? new RunTimerTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);
    }
}
