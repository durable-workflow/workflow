<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestForwardAdmissionWorkflow;
use Tests\Fixtures\V2\TestScopedOperation;
use Tests\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Compensation\Activities\ForwardOperationActivity;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\WorkflowStub;

final class V2CompensationForwardAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'workflows.v2.namespaces' => ['client-a'],
            'queue.default' => 'sync',
            'queue.connections.sync.driver' => 'sync',
        ]);
        $this->registry($this->handler());
        $this->app->instance(ScopeAuthorization::class, new class() implements ScopeAuthorization {
            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if (config('workflows.v2.testing.scope_host_probe') === 'authorization') {
                    $depths = Cache::get('test:scope:host-depths', []);
                    $depths[] = DB::transactionLevel();
                    Cache::forever('test:scope:host-depths', $depths);
                    if (DB::transactionLevel() !== 0) {
                        throw new \LogicException('Host authorization was called under native SQL locks.');
                    }
                }
                if ($scope->namespace !== 'client-a' || $principal !== 'operator-a') {
                    throw new \LogicException('Scope access refused.');
                }
                return 'test-policy-1';
            }
        });
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    public static function hostPreparationKinds(): array
    {
        return [
            'authorization' => ['authorization'],
            'input validation' => ['input'],
        ];
    }

    #[DataProvider('hostPreparationKinds')]
    public function testHostPreparationRunsWithoutNativeOrScopeLocks(string $kind): void
    {
        config()->set('workflows.v2.testing.scope_host_probe', $kind);
        [$scope, $workflow] = $this->origin();
        $this->assertTrue($workflow->refresh()->completed(), json_encode(
            WorkflowTask::query()->where('workflow_run_id', $workflow->runId())->limit(3)->get()
                ->map(static fn (WorkflowTask $task): array => [
                    'status' => $task->status->value,
                    'error' => $task->last_error,
                    'claim_error' => $task->last_claim_error,
                ])->all(),
            JSON_THROW_ON_ERROR,
        ));
        $depths = Cache::get('test:scope:host-depths', []);
        $this->assertNotEmpty($depths);
        $this->assertSame([0], array_values(array_unique($depths)));
        $this->assertSame('observed', (new ScopeRepository())->inspect($scope)['obligations'][0]['forward_status']);
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
    }

    public function testMissingPinnedForwardHandlerIsNonRetryableBeforeDispatch(): void
    {
        $this->replaceAfterRegistration(new HandlerRegistry([]));
        [$scope, $workflow] = $this->origin();
        $this->assertRefusedBeforeDispatch($scope, $workflow, 'scope_handler_unavailable');
    }

    public function testIncompatibleForwardInputIsNonRetryableBeforeDispatch(): void
    {
        $this->replaceAfterRegistration(new HandlerRegistry([
            'test.operation' => [
                '1' => $this->handler(false),
            ],
        ]));
        [$scope, $workflow] = $this->origin();
        $this->assertRefusedBeforeDispatch($scope, $workflow, 'scope_input_incompatible');
    }

    public function testForwardRetryRefusalPreservesEarlierUnknownEffect(): void
    {
        [$scope, $workflow] = $this->origin('lost-ack');
        $before = (new ScopeRepository())->inspect($scope)['obligations'][0];
        $this->assertSame('unknown', $before['forward_status']);
        $this->assertSame(1, (int) Cache::get('test:scope:provider-call-count'));
        $this->registry(null);
        $this->driveNativeRetries($workflow->runId());
        $this->assertForwardFailure($workflow, 'scope_handler_unavailable', 2);
        $after = (new ScopeRepository())->inspect($scope)['obligations'][0];
        $this->assertSame('unknown', $after['forward_status']);
        $this->assertSame($before['forward_attempt_id'], $after['forward_attempt_id']);
        $this->assertSame($before['forward_result'], $after['forward_result']);
        $this->assertSame('pending', $after['compensation_status']);
        $this->assertSame(1, (int) Cache::get('test:scope:provider-call-count'));
        $this->registry($this->handler());
        app(ScopeRecovery::class)->request($scope, 'recover', 'operator-a', 'cancel');
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertSame(1, (int) Cache::get('test:scope:compensation-call-count'));
    }

    public function testNonRetryableProviderExceptionAfterDispatchRemainsUnknownAndRetryable(): void
    {
        $this->registry($this->handler(throwAfterEffect: true));
        [$scope, $workflow] = $this->origin();
        $this->driveNativeRetries($workflow->runId());
        $this->assertForwardFailure($workflow, 'scope_forward_unknown', 3, false);
        $row = (new ScopeRepository())->inspect($scope)['obligations'][0];
        $this->assertSame('unknown', $row['forward_status']);
        $this->assertSame(
            'provider-outcome-unknown',
            json_decode($row['forward_result'], true, flags: JSON_THROW_ON_ERROR)['reason']
        );
        $this->assertSame('pending', $row['compensation_status']);
        $this->assertSame(3, (int) Cache::get('test:scope:provider-call-count'));
        $this->assertSame(1, (int) Cache::get('test:scope:effect-count'));
    }

    private function assertRefusedBeforeDispatch(ScopeIdentity $scope, WorkflowStub $workflow, string $reason): void
    {
        $this->assertForwardFailure($workflow, $reason, 1);
        $snapshot = (new ScopeRepository())->inspect($scope);
        $row = $snapshot['obligations'][0];
        $this->assertSame('open', $snapshot['status']);
        $this->assertSame('registered', $row['forward_status']);
        $this->assertNull($row['forward_activity_id']);
        $this->assertNull($row['forward_attempt_id']);
        $this->assertNull($row['forward_result']);
        $this->assertSame('pending', $row['compensation_status']);
        $this->assertNull(Cache::get('test:scope:provider-call-count'));
        $this->assertNull(Cache::get('test:scope:effect-count'));
        $this->registry($this->handler());
        app(ScopeRecovery::class)->request($scope, 'recover', 'operator-a', 'cancel');
        $this->assertSame('verified', (new ScopeRepository())->inspect($scope)['status']);
        $this->assertNull(Cache::get('test:scope:compensation-call-count'));
    }

    private function assertForwardFailure(
        WorkflowStub $workflow,
        string $reason,
        int $attempts,
        bool $nonRetryable = true
    ): void {
        $this->assertSame('failed', $workflow->refresh()->status());
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())
            ->where('activity_class', ForwardOperationActivity::class)->firstOrFail();
        $this->assertSame($attempts, $execution->attempts()->count());
        $failure = WorkflowHistoryEvent::query()->where('workflow_run_id', $workflow->runId())
            ->where('event_type', HistoryEventType::ActivityFailed)->firstOrFail();
        $this->assertSame($reason, $failure->payload['exception']['message']);
        $this->assertSame($nonRetryable, $failure->payload['non_retryable']);
    }

    private function replaceAfterRegistration(HandlerRegistry $replacement): void
    {
        $replaced = false;
        ConfiguredV2Models::connection()->listen(function (QueryExecuted $query) use ($replacement, &$replaced): void {
            if (! $replaced && str_starts_with($query->sql, 'insert')
                && str_contains($query->sql, 'workflow_compensation_obligations')) {
                $replaced = true;
                $this->app->instance(HandlerRegistry::class, $replacement);
            }
        });
    }

    private function registry(?ScopedOperation $handler): void
    {
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry(
            $handler === null ? [] : [
                'test.operation' => [
                    '1' => $handler,
                ],
            ]
        ));
    }

    private function handler(bool $compatible = true, bool $throwAfterEffect = false): ScopedOperation
    {
        return new class($compatible, $throwAfterEffect) implements ScopedOperation {
            public function __construct(
                private bool $compatible,
                private bool $throwAfterEffect
            ) {
            }

            public function assertInput(array $input): void
            {
                if (config('workflows.v2.testing.scope_host_probe') === 'input') {
                    $depths = Cache::get('test:scope:host-depths', []);
                    $depths[] = DB::transactionLevel();
                    Cache::forever('test:scope:host-depths', $depths);
                    if (DB::transactionLevel() !== 0) {
                        throw new \LogicException('Host input validation was called under native SQL locks.');
                    }
                }
                if (! $this->compatible) {
                    throw new \InvalidArgumentException('The input schema changed after registration.');
                }
            }

            public function execute(OperationContext $context): ForwardResult
            {
                Cache::increment('test:scope:provider-call-count');
                $result = (new TestScopedOperation())->execute($context);
                if ($this->throwAfterEffect) {
                    throw new NonRetryableException('provider-acknowledgement-lost');
                }
                return $result;
            }

            public function compensate(OperationContext $context): Resolution
            {
                Cache::increment('test:scope:compensation-call-count');
                return (new TestScopedOperation())->compensate($context);
            }
        };
    }

    private function origin(string $mode = 'complete'): array
    {
        WorkflowStub::fake();
        $scope = new ScopeIdentity('shop', 'client-a', 'order-42', '1');
        $workflow = WorkflowStub::make(TestForwardAdmissionWorkflow::class, 'forward-admission-origin', 'client-a');
        $workflow->start($scope->toArray(), $mode);
        return [$scope, $workflow];
    }

    private function driveNativeRetries(string $runId): void
    {
        for ($step = 0; $step < 20; $step++) {
            if (WorkflowRun::query()->findOrFail($runId)->status->isTerminal()) {
                return;
            }
            $task = WorkflowTask::query()->where('workflow_run_id', $runId)->where('status', 'ready')
                ->orderBy('available_at')
                ->first();
            $this->assertNotNull($task, 'A nonterminal forward run must retain a native retry task.');
            if ($task->available_at !== null && $task->available_at->isFuture()) {
                \Illuminate\Support\Carbon::setTestNow($task->available_at->copy()->addMicrosecond());
            }
            if ($task->task_type->value === 'activity') {
                $this->app->call([new \Workflow\V2\Jobs\RunActivityTask($task->id), 'handle']);
            } else {
                $this->app->call([new \Workflow\V2\Jobs\RunWorkflowTask($task->id), 'handle']);
            }
        }
        $this->fail('Native forward retries did not terminate.');
    }
}
