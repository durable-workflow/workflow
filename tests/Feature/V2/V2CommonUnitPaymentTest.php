<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\CheckoutOrder;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Payment;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** The existing common Order and genuine Payment/Shipment children, with explicit unit-money policy. */
final class V2CommonUnitPaymentTest extends TestCase
{
    use ProviderMachineFixture;

    public function testCommonCancellationRepaysOnlyUnitsNotAlreadyRepaidByTheOriginalProvider(): void
    {
        $stub = $this->start();
        $key = $this->terms->subjectKey();
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $plan = $this->store->authority->plan($key);
        $payment = (new ScopeRepository())->inspect($plan->childScope('payment'));
        $history = $this->history($payment['original_run_id']);
        $command = $this->command();
        $partial = new UnitRefundCommand($command->original, 'original-provider-shortage-repayment', [2]);
        $this->unitRefunds->plan($partial, Status::Confirmed);
        $this->assertSame(499, $this->unitRefunds->attempt($partial)['ownPayoutMinor']);
        $this->unitRefunds->plan($command, Status::Confirmed);
        app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-original-unit-order');
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame('verified', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(1000, $this->unitRefunds->lookup($command)['ownPayoutMinor']);
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking', 'release'], $this->effects());
        $this->assertSame($history, $this->history($payment['original_run_id']));
        $requests = $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($stub->runId()));
        $this->assertSame($requests, $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')->fetchColumn());
        $this->assertSame(1499, $this->paidBack());
    }

    public function testUnknownUnitRefundBlocksOriginalParentSettlementAndStockRelease(): void
    {
        $stub = $this->start();
        $key = $this->terms->subjectKey();
        $command = $this->command();
        $this->unitRefunds->plan($command, Status::Processing);
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $stub->runId(), 'original-unit-refund-processing');
        $this->drain();
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame(
            'pending',
            $cancellation->finish($key, $stub->runId(), 'original-unit-refund-processing')['status']
        );
        $this->assertSame(['reserve', 'capture', 'book', 'cancel-booking'], $this->effects());
        $this->assertSame(0, $this->paidBack());
        $history = $this->history($stub->runId());
        $this->unitRefunds->resolve($command, Status::Confirmed);
        $cancellation->request(
            $key,
            $stub->runId(),
            'original-unit-refund-processing',
            'original-provider-now-confirmed'
        );
        $this->drain();
        $this->assertSame(
            'started',
            $cancellation->finish($key, $stub->runId(), 'original-unit-refund-processing')['status'],
            $this->progress()
        );
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(1499, $this->paidBack());
        $this->assertSame($history, $this->history($stub->runId()));
    }

    public function testAnotherOriginalUnitQuoteIsRefusedBeforeProtectedTermsDecode(): void
    {
        $key = $this->terms->subjectKey();
        DB::table('provider_machine_assignments')->where('id', $key)->update([
            'terms' => '{invalid',
        ]);
        $other = new Authority(
            DB::connection(),
            'provider-worker',
            [$key],
            [
                $key => $this->recipe(),
            ],
            unitAmounts: [
                $key => [499, 500, 500],
            ]
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('original subject authority');
        $other->assignment($key);
    }

    public function testForeignBusinessMoneyDescriptorIsRefusedBeforeProviderDispatch(): void
    {
        $this->start();
        $key = $this->terms->subjectKey();
        $scope = $this->store->authority->plan($key)
            ->childScope('payment');
        $ledger = (new ScopeRepository())->inspect($scope);
        $expected = $this->store->authority->unitMoney->obligation($key);
        $input = $expected->input;
        $input['refund']['original']['capture']['identity']['generation'] = 'foreign-generation';
        $context = new OperationContext(
            $scope,
            new Obligation('effect', 'provider.unit-payment', '1', $input),
            $ledger['obligations'][0]['id'],
            $ledger['original_run_id'],
            'untrusted-activity',
            'untrusted-attempt',
            false
        );
        $before = $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        try {
            $this->store->authority->unitMoney->operation($context, 'forward');
            $this->fail('A foreign original money descriptor was authorized.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('another original business', $error->getMessage());
        }
        $this->assertSame($before, $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')->fetchColumn());
    }

    public function testPaymentFirstUsesTheSameCommonCheckoutAndGenuinePaymentChild(): void
    {
        $other = new OrderTerms('shop-a', 'purchase-payment-first', 'business-generation-1', 1499, 'USD', 'sku-1', 3);
        $key = $other->subjectKey();
        $firstKey = $this->terms->subjectKey();
        $authority = new Authority(DB::connection(), 'provider-worker', [$firstKey, $key], [
            $firstKey => Catalogue::COMMON_RESERVE_FIRST,
            $key => Catalogue::PAYMENT_FIRST,
        ], unitAmounts: [
            $firstKey => $this->unitAmounts(),
            $key => $this->unitAmounts(),
        ]);
        $this->store = MachineStore::configure(
            $this->app,
            $authority,
            $this->provider,
            unitRefunds: $this->unitRefunds
        );
        $this->store->seed($other);
        foreach (['order', 'payment', 'shipment'] as $role) {
            foreach ($other->descriptor($role) as $request) {
                $this->provider->plan(Request::fromArray($request), Status::Confirmed);
            }
        }
        $stub = WorkflowStub::make(CheckoutOrder::class, 'provider-order-' . $key, Catalogue::NAMESPACE);
        $stub->start($key);
        $this->drain();
        $this->assertSame(['capture', 'reserve', 'book'], $this->effects());
        $this->assertSame('booked', $authority->subject($key, 'order')->state, $this->progress());
        $paymentRun = (new ScopeRepository())->metadata(
            $authority->plan($key)
                ->childScope('payment')
        )['original_run_id'];
        $this->assertSame(Payment::class, WorkflowRun::query()->findOrFail($paymentRun)->workflow_class);
        $command = UnitRefundCommand::fromObligation($authority->unitMoney->obligation($key)->input);
        $this->unitRefunds->plan($command, Status::Confirmed);
        app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-payment-first-original-units');
        $this->drain();
        $this->assertSame('cancelled', $authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(1499, $this->paidBack());
    }

    public function testUnitMoneyDoesNotSilentlyChangeTheLegacyOrderLifecycle(): void
    {
        $key = $this->terms->subjectKey();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('existing direct-capture grant');
        new Authority(
            DB::connection(),
            'provider-worker',
            [$key],
            [
                $key => Catalogue::RESERVE_FIRST,
            ],
            unitAmounts: [
                $key => $this->unitAmounts(),
            ]
        );
    }

    public function testCorrectMoneyDescriptorWithAnotherRegisteredOperationIdIsRefused(): void
    {
        $this->start();
        $key = $this->terms->subjectKey();
        $scope = $this->store->authority->plan($key)
            ->childScope('payment');
        $ledger = (new ScopeRepository())->inspect($scope);
        $owner = array_values(array_filter($ledger['obligations'], static fn (array $row): bool =>
            $row['descriptor']['key'] === 'owner'))[0];
        $context = new OperationContext(
            $scope,
            $this->store->authority->unitMoney->obligation($key),
            $owner['id'],
            $ledger['original_run_id'],
            'untrusted-activity',
            'untrusted-attempt',
            false
        );
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exact original registered Payment obligation');
        $this->store->authority->unitMoney->operation($context, 'forward');
    }

    protected function recipe(): string
    {
        return Catalogue::COMMON_RESERVE_FIRST;
    }

    protected function orderTerms(): OrderTerms
    {
        return new OrderTerms('shop-a', 'purchase-1', 'business-generation-1', 1499, 'USD', 'sku-1', 3);
    }

    protected function unitAmounts(): array
    {
        return [500, 500, 499];
    }

    private function command(): UnitRefundCommand
    {
        return UnitRefundCommand::fromObligation(
            $this->store->authority->unitMoney->obligation($this->terms->subjectKey())
->input
        );
    }

    private function paidBack(): int
    {
        return (int) $this->providerDb->query('SELECT COALESCE(SUM(amount_minor), 0) FROM unit_refund_payouts')
            ->fetchColumn();
    }
}
