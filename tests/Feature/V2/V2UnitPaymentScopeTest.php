<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitPaymentOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PDO;
use Tests\Fixtures\V2\TestUnitPaymentScopeWorkflow;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Compensation\Recovery\ScopeRecovery;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/configured-state-machines/providers/bootstrap.php';

final class V2UnitPaymentScopeTest extends TestCase
{
    private PDO $moneyDb;

    private Journal $provider;

    private UnitRefundGateway $refunds;

    private OriginalUnitAmounts $original;

    private UnitRefundCommand $cancellation;

    private ?string $originalRun = null;

    private int $authorizations = 0;

    private bool $refundOnly = false;

    private bool $failBeforeProvider = false;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => ['unit-money-tests'],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        $this->moneyDb = new PDO('sqlite::memory:');
        $this->provider = new Journal($this->moneyDb, 'shop-a');
        $this->provider->install();
        $this->refunds = new UnitRefundGateway($this->moneyDb, 'shop-a');
        $this->refunds->install();
        $capture = new Request(new Identity('shop-a', 'purchase-1', 'g1', 'capture'), 'capture', [
            'amountMinor' => 1499,
            'currency' => 'USD',
        ]);
        $this->original = new OriginalUnitAmounts($capture, [500, 500, 499]);
        $this->cancellation = new UnitRefundCommand($this->original, 'original-payment-cancellation', [0, 1, 2]);
        $scope = $this->identity();
        $this->app->instance(ScopeAuthorization::class, new class($scope) implements ScopeAuthorization {
            public function __construct(
                private readonly ScopeIdentity $expected
            ) {
            }

            public function assertAllowed(ScopeIdentity $scope, string $principal, string $operation): string
            {
                if ($scope->toArray() !== $this->expected->toArray() || $principal !== 'unit-money-worker') {
                    throw new LogicException('The original unit-payment scope/principal is unavailable.');
                }
                return 'unit-money-host-v1';
            }
        });
        $refund = new UnitRefundOperation($this->provider, $this->refunds, $this->authorize(...));
        $injectedFailure = new class($refund, fn (): bool => $this->failBeforeProvider) implements ScopedOperation {
            public function __construct(
                private readonly ScopedOperation $delegate,
                private readonly \Closure $fail
            ) {
            }

            public function assertInput(array $input): void
            {
                $this->delegate->assertInput($input);
            }

            public function execute(OperationContext $context): ForwardResult
            {
                if (($this->fail)()) {
                    throw new \RuntimeException('Injected failure after native admission before provider dispatch.');
                }
                return $this->delegate->execute($context);
            }

            public function compensate(OperationContext $context): Resolution
            {
                return $this->delegate->compensate($context);
            }
        };
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry([
            'test.unit-payment' => [
                '1' => new UnitPaymentOperation($this->provider, $this->refunds, $this->authorize(...)),
            ],
            'test.unit-refund' => [
                '1' => $injectedFailure,
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->forgetInstance(HandlerRegistry::class);
        unset($this->refunds, $this->provider, $this->moneyDb);
        parent::tearDown();
    }

    public function testPartialRefundThenNativePaymentRecoveryPaysOnlyRemainingOriginalUnits(): void
    {
        $origin = $this->start(Status::Confirmed);
        $this->assertSame('completed', $origin->refresh()->status());
        $history = $this->history();
        $shortage = new UnitRefundCommand($this->original, 'original-shortage', [2]);
        $this->refunds->plan($shortage, Status::Confirmed);
        $this->assertSame(499, $this->refunds->attempt($shortage)['ownPayoutMinor']);
        $this->refunds->plan($this->cancellation, Status::Confirmed);
        $this->recover('cancel-original-payment');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(1000, $this->refunds->lookup($this->cancellation)['ownPayoutMinor']);
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(2, $this->payoutCount());
        $this->assertSame($history, $this->history());
        $calls = $this->authorizations;
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame($calls, $this->authorizations);
        $this->assertSame(2, $this->payoutCount());
    }

    public function testNativeRecoveryReconcilesOneRefundAfterLostAcknowledgement(): void
    {
        $this->start(Status::Confirmed);
        $history = $this->history();
        $this->refunds->plan($this->cancellation, Status::Confirmed, true);
        $this->recover('refund-acknowledgement-lost', fn (): bool => $this->payoutCount() === 1);
        $this->assertSame('requested', $this->ledger()['status']);
        $this->assertSame(1499, $this->paidBack());
        $this->drain();
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(1, $this->payoutCount());
        $this->assertSame($history, $this->history());
        $this->assertSame(1, WorkflowRun::query()->where('namespace', 'unit-money-tests')
            ->where('id', '<>', $this->originalRun)
            ->count());
    }

    public function testUnknownOriginalCaptureBlocksRefundUntilItsOwnLateOutcome(): void
    {
        $this->start(Status::Processing);
        $this->refunds->plan($this->cancellation, Status::Confirmed);
        $this->recover('capture-still-unknown');
        $this->assertSame('requested', $this->ledger()['status']);
        $this->assertSame(0, $this->payoutCount());
        $this->provider->resolve($this->original->capture, Status::Confirmed);
        $this->recover('confirmed-original-capture');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(1499, $this->paidBack());
    }

    public function testUnknownRefundDoesNotSettleItsNativeObligationBeforeProviderCompletion(): void
    {
        $this->start(Status::Confirmed);
        $this->refunds->plan($this->cancellation, Status::Processing);
        $this->recover('original-refund-processing');
        $this->assertSame('requested', $this->ledger()['status']);
        $this->assertSame(0, $this->payoutCount());
        $this->refunds->resolve($this->cancellation, Status::Confirmed);
        $this->recover('original-refund-confirmed');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(1, $this->payoutCount());
        $this->assertSame(1499, $this->paidBack());
    }

    public function testARefusedOriginalCaptureIsFencedWithoutInventingARefund(): void
    {
        $this->start(Status::Refused);
        $this->recover('original-capture-refused');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(0, $this->payoutCount());
        $this->assertSame(Status::Refused, $this->provider->attempt($this->original->capture)->status);
    }

    public function testAnotherOriginalRepaymentCanSettleWithoutResurrectingARefusedCommand(): void
    {
        $this->start(Status::Confirmed);
        $this->refunds->plan($this->cancellation, Status::Refused);
        $this->recover('original-cancellation-refused');
        $this->assertSame('requested', $this->ledger()['status']);
        $remedy = new UnitRefundCommand($this->original, 'separately-authorized-original-remedy', [0, 1, 2]);
        $this->refunds->plan($remedy, Status::Confirmed);
        $this->assertSame(1499, $this->refunds->attempt($remedy)['ownPayoutMinor']);
        $this->recover('verify-original-money-repaid');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame('refused', $this->refunds->lookup($this->cancellation)['status']);
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(1, $this->payoutCount());
    }

    public function testAnotherProviderConnectionIsRefusedBeforeProviderTablesExist(): void
    {
        $otherDb = new PDO('sqlite::memory:');
        $other = new UnitRefundGateway($otherDb, 'shop-a');
        try {
            new UnitPaymentOperation($this->provider, $other, static function (): void {
            });
            $this->fail('Unrelated provider assemblies were accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('same original provider connection', $error->getMessage());
        }
        $this->assertSame(
            0,
            (int) $otherDb->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")
                ->fetchColumn()
        );
    }

    public function testNativeShortageCompensationRetainsTheOriginalCustomerRepayment(): void
    {
        $this->startRefund(Status::Confirmed);
        $history = $this->history();
        $this->assertSame(499, $this->paidBack());
        $this->recover('retain-original-shortage-refund');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(499, $this->paidBack());
        $this->assertSame(1, $this->payoutCount());
        $this->assertSame($history, $this->history());
    }

    public function testNativeShortageScopeWaitsForItsOriginalInFlightRefund(): void
    {
        $this->startRefund(Status::Processing);
        $this->recover('shortage-refund-still-processing');
        $this->assertSame('requested', $this->ledger()['status']);
        $this->assertSame(0, $this->payoutCount());
        $this->refunds->resolve($this->cancellation, Status::Confirmed);
        $this->recover('shortage-refund-confirmed');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame(499, $this->paidBack());
        $this->assertSame(1, $this->payoutCount());
    }

    public function testNativeCancellationClosesAnUnstartedResponsibilityWithoutProviderDispatch(): void
    {
        $this->startRefund(Status::Confirmed, false);
        $this->assertSame(0, $this->payoutCount());
        $calls = $this->authorizations;
        $this->recover('shortage-not-admitted');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame($calls, $this->authorizations);
        $this->assertSame(0, (int) $this->moneyDb->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
        $this->assertSame(0, $this->payoutCount());
        $this->assertSame(Status::Confirmed, $this->provider->lookup($this->original->capture)->status);
    }

    public function testNativeRecoveryFencesFailureAfterForwardAdmissionBeforeProviderDispatch(): void
    {
        $this->failBeforeProvider = true;
        $origin = $this->startRefund(Status::Confirmed);
        $this->assertSame('failed', $origin->refresh()->status());
        $this->assertNotSame('registered', $this->ledger()['obligations'][0]['forward_status']);
        $this->assertSame(0, (int) $this->moneyDb->query('SELECT COUNT(*) FROM unit_refund_commands')->fetchColumn());
        $this->recover('fence-original-uncertain-refund');
        $this->assertSame('verified', $this->ledger()['status']);
        $this->assertSame('not-executed', $this->refunds->attempt($this->cancellation)['status']);
        $this->assertSame(0, $this->payoutCount());
    }

    private function authorize(OperationContext $context, string $mode): void
    {
        if ($context->scope->toArray() !== $this->identity()->toArray() || $context->originalRunId !== $this->originalRun
            || ! in_array($mode, ['forward', 'compensate'], true)
            || $context->obligation->fingerprint() !== $this->obligation()
                ->fingerprint()) {
            throw new LogicException('The host refused the original unit-payment scope/run/descriptor.');
        }
        $ledger = $this->ledger();
        if ($ledger['original_run_id'] !== $this->originalRun || count($ledger['obligations']) !== 1
            || $ledger['obligations'][0]['id'] !== $context->operationId
            || (int) $ledger['obligations'][0]['position'] !== 1
            || $ledger['obligations'][0]['fingerprint'] !== $this->obligation()->fingerprint()
            || Obligation::fromArray($ledger['obligations'][0]['descriptor'])->fingerprint() !== $this->obligation()
                ->fingerprint()) {
            throw new LogicException('The original native unit-payment obligation was substituted.');
        }
        ++$this->authorizations;
    }

    private function identity(): ScopeIdentity
    {
        return new ScopeIdentity('shop-a', 'unit-money-tests', 'purchase-1.payment', 'g1');
    }

    private function obligation(): Obligation
    {
        return new Obligation($this->refundOnly ? 'payment.shortage' : 'payment.capture', $this->refundOnly ? 'test.unit-refund' : 'test.unit-payment', '1', [
            'version' => 1,
            'refund' => $this->cancellation->toArray(),
        ]);
    }

    private function ledger(): array
    {
        return (new ScopeRepository())->inspect($this->identity());
    }

    private function start(Status $status): WorkflowStub
    {
        $this->provider->plan($this->original->capture, $status);
        return $this->startObligation();
    }

    private function startRefund(Status $status, bool $execute = true): WorkflowStub
    {
        $this->refundOnly = true;
        $this->cancellation = new UnitRefundCommand($this->original, 'original-shortage-refund', [2]);
        $this->refunds->register($this->original);
        $this->provider->plan($this->original->capture, Status::Confirmed);
        $this->provider->attempt($this->original->capture);
        $this->refunds->plan($this->cancellation, $status);
        return $this->startObligation($execute);
    }

    private function startObligation(bool $execute = true): WorkflowStub
    {
        $stub = WorkflowStub::make(TestUnitPaymentScopeWorkflow::class, 'unit-money-origin', 'unit-money-tests');
        $stub->start(
            $this->identity()
                ->toArray(),
            $this->obligation()
->input,
            $this->obligation()
->handlerType,
            $execute
        );
        $this->originalRun = $stub->runId();
        $this->drain();
        return $stub;
    }

    private function recover(string $request, ?\Closure $stop = null): void
    {
        app(ScopeRecovery::class)->request(
            $this->identity(),
            $request,
            'unit-money-worker',
            'source-original-unit-refund'
        );
        $this->drain($stop);
    }

    private function drain(?\Closure $stop = null): void
    {
        for ($taskCount = 0; $taskCount < 150; ++$taskCount) {
            if ($stop !== null && $stop()) {
                return;
            }
            $task = WorkflowTask::query()->whereRelation('run', 'namespace', 'unit-money-tests')
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
        $this->fail('The original unit-payment task inventory did not settle within its finite bound.');
    }

    private function history(): array
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $this->originalRun)->orderBy('sequence')->get()
            ->map(static function (WorkflowHistoryEvent $event): array {
                $payload = PortableDescriptor::encode($event->payload);
                return [$event->event_type->value, $payload];
            })->all();
    }

    private function payoutCount(): int
    {
        return (int) $this->moneyDb->query('SELECT COUNT(*) FROM unit_refund_payouts')
            ->fetchColumn();
    }

    private function paidBack(): int
    {
        return (int) $this->moneyDb->query('SELECT COALESCE(SUM(amount_minor), 0) FROM unit_refund_payouts')
            ->fetchColumn();
    }
}
