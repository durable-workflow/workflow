<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\FinancialOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use Tests\Fixtures\V2\TestFinancialScopeWorkflow;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

trait FinancialScopeFixture
{
    private string $providerPath;

    private PDO $providerDb;

    private Journal $provider;

    private ?string $originalRun = null;

    private int $providerTime = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => ['financial-tests'],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        $this->providerPath = tempnam(sys_get_temp_dir(), 'native-financial-provider-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->provider = new Journal($this->providerDb, 'shop-a', fn (): int => $this->providerTime);
        $this->provider->install();
        $this->provider->installAuthorizationSupport();
        $this->configureFinancialHandlers();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(HandlerRegistry::class);
        unset($this->provider, $this->providerDb);
        if (isset($this->providerPath)) {
            unlink($this->providerPath);
        }
        parent::tearDown();
    }

    private function configureFinancialHandlers(): void
    {
        $scope = $this->financialScope();
        $this->app->instance(ScopeAuthorization::class, new class($scope) implements ScopeAuthorization {
            public function __construct(
                private readonly ScopeIdentity $expected
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->toArray() !== $this->expected->toArray() || $principal !== 'financial-worker') {
                    throw new LogicException('The original financial scope/principal is unavailable.');
                }
                return 'financial-host-v1';
            }
        });
        $handlers = [];
        foreach (['authorize', 'capture'] as $stage) {
            $handlers['test.financial.' . $stage] = [
                '1' => new FinancialOperation(
                    $this->provider,
                    fn (OperationContext $context, string $mode) => $this->authorizeFinancialContext(
                        $context,
                        $mode,
                        $stage
                    ),
                    $stage
                ),
            ];
        }
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry($handlers));
    }

    private function authorizeFinancialContext(OperationContext $context, string $mode, string $stage): void
    {
        if ($context->scope->toArray() !== $this->financialScope()->toArray()
            || $context->originalRunId !== $this->originalRun || ! in_array($mode, ['forward', 'compensate'], true)
            || $context->obligation->fingerprint() !== $this->financialObligation($stage)
                ->fingerprint()) {
            throw new LogicException('The host refused the original financial scope/run/descriptor.');
        }
        // Real native rows, after host authority, before any provider query or effect.
        $ledger = (new ScopeRepository())->inspect($context->scope);
        if ($ledger['original_run_id'] !== $context->originalRunId || count($ledger['obligations']) !== 2) {
            throw new LogicException('Both original financial obligations must be registered before dispatch.');
        }
        foreach (['authorize', 'capture'] as $position => $key) {
            $row = $ledger['obligations'][$position];
            $expected = $this->financialObligation($key);
            if (Obligation::fromArray($row['descriptor'])->fingerprint() !== $expected->fingerprint()
                || $row['fingerprint'] !== $expected->fingerprint()
                || (int) $row['position'] !== $position + 1) {
                throw new LogicException('The original paired financial owner or ordering was substituted.');
            }
            if ($key === $stage && $row['id'] !== $context->operationId) {
                throw new LogicException('Financial recovery cannot substitute the original native operation.');
            }
        }
    }

    private function financialObligation(string $stage): Obligation
    {
        return new Obligation('payment.' . $stage, 'test.financial.' . $stage, '1', $this->financialDescriptor());
    }

    private function financialDescriptor(): array
    {
        $descriptor = [];
        foreach (['authorize', 'capture', 'void', 'refund'] as $key) {
            $descriptor[$key] = $this->financialRequest($key)->toArray();
        }
        return $descriptor;
    }

    private function financialRequest(string $key): Request
    {
        $id = static fn (string $operation): Identity => new Identity('shop-a', 'purchase-1', 'g1', $operation);
        $action = match ($key) {
            'authorize' => 'authorize', 'capture' => 'capture-authorized',
            'void' => 'void-authorization', 'refund' => 'refund-authorized',
        };
        $target = match ($key) {
            'authorize' => null, 'capture', 'void' => $id('authorize'), 'refund' => $id('capture'),
        };
        $terms = [
            'amountMinor' => 1375,
            'currency' => 'USD',
        ];
        if ($key !== 'refund') {
            $terms['expiresAt'] = 1001;
        }
        return new Request($id($key), $action, $terms, $target);
    }

    private function financialScope(): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'financial-tests', 'purchase-1.payment', 'g1');
    }

    private function startFinancial(bool $capture = false, bool $paired = true, ?\Closure $stop = null): WorkflowStub
    {
        $stub = WorkflowStub::make(TestFinancialScopeWorkflow::class, 'financial-origin', 'financial-tests');
        $stub->start($this->financialScope()->toArray(), $this->financialDescriptor(), $capture, $paired);
        $this->originalRun = $stub->runId();
        $this->drainFinancialTasks($stop);
        return $stub;
    }

    private function drainFinancialTasks(?\Closure $stop = null): void
    {
        for ($i = 0; $i < 150; ++$i) {
            if ($stop !== null && $stop()) {
                return;
            }
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', 'financial-tests')
                ->where('status', TaskStatus::Ready)->orderBy('available_at')->orderBy('created_at')->orderBy(
                    'id'
                )->first();
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
        $this->fail('The bounded financial scope task inventory did not settle.');
    }

    private function financialEffects(): array
    {
        return $this->providerDb->query('SELECT json_extract(o.request, \'$.action\')
            FROM provider_effects e JOIN provider_operations o ON o.id = e.operation_id ORDER BY e.sequence')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function financialHistory(string $run): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $run)->orderBy('sequence')->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                $event->event_type->value, PortableDescriptor::encode($event->payload),
            ])->all();
    }
}
