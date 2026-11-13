<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestScopedOperation;
use Tests\Fixtures\V2\TestScopeRunBoundaryWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowChildSupervision;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2CompensationScopeRunBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
        ]);
        Queue::fake();
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.operation' => [
                '1' => new TestScopedOperation(),
            ],
        ]));
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new \LogicException('Scope access refused.');
                }
                return 'boundary-policy-1';
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testContinueAsNewHandsOffTheOpenChildWithoutTransferringScopeOwnership(): void
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'continued-order', '1');
        $child = new ScopeIdentity('shop', 'client-a', 'continued-shipment', '1');
        $stub = WorkflowStub::make(TestScopeRunBoundaryWorkflow::class, 'scope-continue', 'client-a');
        $stub->start($scope->toArray(), 'continue', 0, $child->toArray());
        $this->drainTasks();
        $runs = WorkflowRun::query()->where('workflow_instance_id', 'scope-continue')->orderBy('run_number')->get();
        $this->assertCount(2, $runs);
        [$origin, $continued] = [$runs[0], $runs[1]];
        $this->assertSame('continued', $origin->closed_reason);
        $this->assertSame(RunStatus::Waiting, $continued->status);
        $originHistory = $this->history($origin->id);
        $this->assertSame(HistoryEventType::WorkflowContinuedAsNew->value, end($originHistory)['event_type']);
        $link = WorkflowLink::query()->where('parent_workflow_run_id', $origin->id)->where(
            'link_type',
            'child_workflow'
        )->sole();
        $handoff = WorkflowChildSupervision::query()->where('supervising_workflow_run_id', $continued->id)->sole();
        $this->assertSame($link->id, $handoff->workflow_link_id);
        $this->assertSame($origin->id, $handoff->parent_workflow_run_id);
        $this->assertSame($link->child_workflow_run_id, $handoff->child_workflow_run_id);
        $this->assertSame(RunStatus::Waiting, WorkflowRun::query()->findOrFail($link->child_workflow_run_id)->status);
        $this->assertSame($origin->id, (new ScopeRepository())->inspect($scope)['original_run_id']);
        $stub->signal('resume');
        $this->drainTasks();
        $this->assertSame(RunStatus::Failed, $continued->refresh()->status);
        $this->assertForwardRefused($continued->id, $scope, 2);
        $result = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->drainTasks();
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame('verified', (new ScopeRepository())->inspect($child)['status']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($result['runId'])->status);
        $this->assertSame(
            ['continued-shipment/child', 'continued-order/first'],
            Cache::get('test:scope:undo-scoped-log')
        );
        $this->assertSame($originHistory, $this->history($origin->id));
        $effects = Cache::get('test:scope:effect-count');
        app(WorkflowReplayer::class)->replay($origin->fresh());
        $this->assertSame($effects, Cache::get('test:scope:effect-count'));
    }

    public function testOriginRedriveRefusesNewForwardWorkAndRetainsTheOriginalRecovery(): void
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'redriven-order', '1');
        $stub = WorkflowStub::make(TestScopeRunBoundaryWorkflow::class, 'scope-redrive', 'client-a');
        $stub->start($scope->toArray(), 'redrive');
        $this->drainTasks();
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(RunStatus::Failed, $origin->status);
        $originHistory = $this->history($origin->id);
        $result = app(WorkflowControlPlane::class)->redrive('scope-redrive', $origin->id, [
            'namespace' => 'client-a',
            'request_id' => 'origin-redrive',
        ]);
        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->drainTasks();
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $successor->status);
        $this->assertForwardRefused($successor->id, $scope, 1);
        $this->assertSame(2, (int) Cache::get('test:redrive:second-calls'));
        $recovery = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->drainTasks();
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($recovery['runId'])->status);
        $this->assertSame(['redriven-order/first'], Cache::get('test:scope:undo-scoped-log'));
        $this->assertSame($origin->id, (new ScopeRepository())->inspect($scope)['original_run_id']);
        $this->assertSame($originHistory, $this->history($origin->id));
    }

    public function testOriginRedriveCannotReissueAnUncertainForwardEffectFromTheOriginalScope(): void
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'unknown-redriven-order', '1');
        $stub = WorkflowStub::make(TestScopeRunBoundaryWorkflow::class, 'scope-unknown-redrive', 'client-a');
        $stub->start($scope->toArray(), 'unknown');
        $this->drainTasks();
        $origin = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertSame(RunStatus::Failed, $origin->status);
        $originHistory = $this->history($origin->id);
        $before = (new ScopeRepository())->inspect($scope);
        $this->assertSame('unknown', $before['obligations'][0]['forward_status']);
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
        $result = app(WorkflowControlPlane::class)->redrive('scope-unknown-redrive', $origin->id, [
            'namespace' => 'client-a',
            'request_id' => 'origin-redrive',
        ]);
        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->drainTasks();
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(RunStatus::Failed, $successor->status);
        $messages = WorkflowFailure::query()->where('workflow_run_id', $successor->id)->pluck('message')->all();
        $this->assertStringContainsString('scope_execution_owner_mismatch', implode('\n', $messages));
        $this->assertSame($before, (new ScopeRepository())->inspect($scope));
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
        $recovery = app(ScopeRecovery::class)->request($scope, 'cancel', 'operator-a', 'cancel');
        $this->drainTasks();
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(RunStatus::Completed, WorkflowRun::query()->findOrFail($recovery['runId'])->status);
        $this->assertSame(['unknown-redriven-order/first'], Cache::get('test:scope:undo-scoped-log'));
        $this->assertSame($originHistory, $this->history($origin->id));
    }

    private function assertForwardRefused(string $runId, ScopeIdentity $scope, int $effectCount): void
    {
        $messages = WorkflowFailure::query()->where('workflow_run_id', $runId)->pluck('message')->all();
        $this->assertStringContainsString('scope_execution_owner_mismatch', implode('\n', $messages));
        $snapshot = (new ScopeRepository())->inspect($scope);
        $this->assertSame('open', $snapshot['status']);
        $this->assertNotContains('second', array_column(array_column($snapshot['obligations'], 'descriptor'), 'key'));
        $this->assertSame($effectCount, (int) Cache::get('test:scope:effect-count'));
        $this->assertNull(Cache::get('test:scope:undo-log'));
    }

    private function history(string $runId): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderBy('sequence')->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'sequence' => $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
            ])->all();
    }

    private function drainTasks(): void
    {
        for ($count = 0; $count < 300; ++$count) {
            $task = WorkflowTask::query()->where('status', TaskStatus::Ready->value)->orderBy('created_at')->first();
            if ($task === null) {
                return;
            }
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                Carbon::setTestNow($task->available_at->copy()->addSecond());
            }
            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };
            $this->app->call([$job, 'handle']);
        }
        $this->fail('Ready tasks did not settle within the bounded drain.');
    }
}
