<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestRetainedScopeWorkflow;
use Tests\Fixtures\V2\TestScopedOperation;
use Tests\TestCase;
use Workflow\V2\Compensation\Activities\CompensateObligationActivity;
use Workflow\V2\Compensation\Activities\PrepareRecoveryActivity;
use Workflow\V2\Compensation\Activities\VerifyScopeActivity;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\RecoveryWorkflow;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\WorkflowStub;

final class V2CompensationRecoveryAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        config([
            'queue.default' => 'redis',
            'workflows.v2.namespaces' => ['client-a'],
        ]);
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.operation' => [
                '1' => new TestScopedOperation(),
            ],
        ]));
        $this->authorize();
    }

    public function testOrdinaryStartWinningAfterTheRetainedRouteCannotPerformRecovery(): void
    {
        [$scope] = $this->origin();
        $winner = null;
        $injected = false;
        ConfiguredV2Models::connection()->listen(function (QueryExecuted $query) use (
            $scope,
            &$winner,
            &$injected
        ): void {
            if ($injected || ! str_starts_with(strtolower($query->sql), 'update')
                || ! str_contains($query->sql, 'workflow_compensation_requests')
                || ! str_contains($query->sql, 'recovery_route')) {
                return;
            }
            $injected = true;
            $query->connection->afterCommit(function () use ($scope, &$winner): void {
                $this->assertSame(0, ConfiguredV2Models::connection()->transactionLevel());
                $winner = WorkflowStub::make(RecoveryWorkflow::class, $scope->recoveryInstanceId(), 'client-a');
                $winner->start($scope->toArray());
            });
        });
        try {
            app(ScopeRecovery::class)->request($scope, 'retained-request', 'operator-a', 'cancel');
            $this->fail('An ordinary start was adopted as content-bound recovery.');
        } catch (\LogicException) {
            $this->assertTrue($injected);
            $this->assertInstanceOf(WorkflowStub::class, $winner);
        }
        $task = $this->nextActivity($winner->runId(), PrepareRecoveryActivity::class);
        $this->assertBoundaryRefuses($scope, $task);
        $this->assertSame('requested', app(ScopeRepository::class)->inspect($scope)['status']);
        $this->assertNull(Cache::get('test:scope:undo-log'));
    }

    public static function invalidReceipts(): iterable
    {
        foreach ([
            PrepareRecoveryActivity::class,
            CompensateObligationActivity::class,
            VerifyScopeActivity::class,
        ] as $stage) {
            foreach (['missing-command', 'missing-history', 'missing-both', 'divergent', 'scope', 'owner'] as $change) {
                yield $stage . '/' . $change => [$stage, $change];
            }
        }
    }

    #[DataProvider('invalidReceipts')]
    public function testEveryRecoveryActivityRequiresItsExactAdmission(string $stage, string $change): void
    {
        [$scope] = $this->origin();
        $result = app(ScopeRecovery::class)->request($scope, 'recovery', 'operator-a', 'cancel');
        $task = $this->nextActivity($result['runId'], $stage);
        $this->alterReceipt($result['runId'], $change);
        $this->assertBoundaryRefuses($scope, $task);
    }

    public function testReceiptIsRecheckedAfterUnlockedHostAuthorization(): void
    {
        [$scope] = $this->origin();
        $result = app(ScopeRecovery::class)->request($scope, 'recovery', 'operator-a', 'cancel');
        $task = $this->nextActivity($result['runId'], PrepareRecoveryActivity::class);
        $changed = false;
        $this->authorize(function () use ($result, &$changed): void {
            if (! $changed) {
                $changed = true;
                $this->assertSame(0, ConfiguredV2Models::connection()->transactionLevel());
                $this->alterReceipt($result['runId'], 'owner');
            }
        });
        $this->assertBoundaryRefuses($scope, $task);
        $this->assertTrue($changed);
    }

    private function origin(): array
    {
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $stub = WorkflowStub::make(TestRetainedScopeWorkflow::class, 'receipt-origin', 'client-a');
        $stub->start($scope->toArray(), 'complete');
        for ($count = 0; $count < 24 && ! $stub->refresh()->completed(); ++$count) {
            $this->execute($this->readyTask($stub->runId()));
        }
        $this->assertTrue($stub->refresh()->completed());
        return [$scope, $stub];
    }

    private function nextActivity(string $runId, string $class): WorkflowTask
    {
        for ($count = 0; $count < 24; ++$count) {
            $task = $this->readyTask($runId);
            $execution = $task->task_type === TaskType::Activity
                ? ActivityExecution::query()->find($task->payload['activity_execution_id'])
                : null;
            if ($execution?->activity_type === TypeRegistry::for($class)) {
                return $task;
            }
            $this->execute($task);
        }
        $this->fail('The bounded recovery did not reach ' . $class);
    }

    private function readyTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready)->orderBy('created_at')->firstOrFail();
    }

    private function execute(WorkflowTask $task): void
    {
        $job = $task->task_type === TaskType::Activity
            ? new RunActivityTask($task->id) : new RunWorkflowTask($task->id);
        $this->app->call([$job, 'handle']);
    }

    private function assertBoundaryRefuses(ScopeIdentity $scope, WorkflowTask $task): void
    {
        $repository = app(ScopeRepository::class);
        $before = [$repository->inspect($scope), $repository->journal($scope), Cache::get('test:scope:undo-log')];
        $this->execute($task);
        $messages = WorkflowFailure::query()->where('workflow_run_id', $task->workflow_run_id)->pluck('message')->all();
        $this->assertStringContainsString('scope_recovery_admission_unavailable', implode('\n', $messages));
        $this->assertSame(
            $before,
            [$repository->inspect($scope), $repository->journal($scope), Cache::get('test:scope:undo-log')]
        );
    }

    private function alterReceipt(string $runId, string $change): void
    {
        $command = WorkflowCommand::query()->where('workflow_run_id', $runId)
            ->where('command_type', CommandType::Start)->sole();
        $started = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
            ->where('event_type', HistoryEventType::WorkflowStarted)->sole();
        $context = $command->context;
        $payload = $started->payload;
        $receipt = $context['compensation_recovery'];
        if (in_array($change, ['scope', 'owner'], true)) {
            if ($change === 'scope') {
                $receipt['intent']['binding']['scope']['key'] = 'another-scope';
            } else {
                $receipt['intent']['binding']['original_run_id'] = 'another-original-run';
            }
            $receipt['intent']['binding_fingerprint'] = hash(
                'sha256',
                PortableDescriptor::encode($receipt['intent']['binding'])
            );
            $context['compensation_recovery'] = $payload['compensation_recovery'] = $receipt;
        }
        if (in_array($change, ['missing-command', 'missing-both'], true)) {
            unset($context['compensation_recovery']);
        }
        if (in_array($change, ['missing-history', 'missing-both'], true)) {
            unset($payload['compensation_recovery']);
        }
        if ($change === 'divergent') {
            $payload['compensation_recovery']['request_key'] = 'different-request';
        }
        $command->forceFill([
            'context' => $context,
        ])->save();
        $started->forceFill([
            'payload' => $payload,
        ])->save();
    }

    private function authorize(?\Closure $afterCheck = null): void
    {
        $this->app->instance(ScopeAuthorization::class, new class($afterCheck) implements ScopeAuthorization {
            public function __construct(
                private ?\Closure $afterCheck
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new \LogicException('Scope access refused.');
                }
                if ($operation === 'compensate') {
                    ($this->afterCheck)?->__invoke();
                }
                return 'test-policy-1';
            }
        });
    }
}
