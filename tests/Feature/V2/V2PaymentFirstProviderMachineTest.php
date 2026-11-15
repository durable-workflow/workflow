<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Booking;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\CheckoutOrder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2PaymentFirstProviderMachineTest extends TestCase
{
    use ProviderMachineFixture;

    public function testPaymentIsConfirmedBeforeReservationAndBooking(): void
    {
        $stub = $this->start();
        $this->assertSame(
            'booked',
            $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state,
            $this->progress()
        );
        $this->assertSame(['capture', 'reserve', 'book'], $this->effects());
        $requests = $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        foreach (WorkflowRun::query()->where('namespace', Catalogue::NAMESPACE)->get() as $run) {
            app(WorkflowReplayer::class)->replay($run);
        }
        $this->assertSame($requests, $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')->fetchColumn());
        $this->assertSame($stub->runId(), $this->store->authority->plan($this->terms->subjectKey())->rootRun);
    }

    public function testDefinitiveStockRefusalConfirmsOriginalRefundWithoutBookingOrFictitiousRelease(): void
    {
        $stub = $this->start(reservation: Status::Refused);
        $key = $this->terms->subjectKey();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(['capture', 'refund'], $this->effects());
        $this->assertSame(0, WorkflowRun::query()->where('workflow_class', Booking::class)->count());
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($this->store->authority->plan($key)->rootScope())['status']
        );
        $publication = DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($key, 'order')
        )->get()
            ->map(static fn (object $row): array => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))
            ->firstWhere('transitionKey', 'cancel');
        $this->assertSame('refused', $publication['stockRefusal']['status']);
        $this->assertTrue($publication['stockRefusal']['admissionClosed']);
        $this->assertSame($stub->runId(), $publication['stockRefusal']['originalRunId']);
        $refund = Request::fromArray($this->terms->descriptor('payment')['compensation']);
        $this->assertSame(Status::Confirmed, $this->provider->lookup($refund)->status);
        $this->assertSame($this->terms->descriptor('payment')['forward']['identity'], $refund->target->toArray());
    }

    public function testProcessingRefundCannotSettleRefusedPurchaseUntilConfirmedOriginalRecovery(): void
    {
        $stub = $this->start(refund: Status::Processing, reservation: Status::Refused);
        $key = $this->terms->subjectKey();
        $cancel = app(CancellationStore::class);
        $this->assertSame('received', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame(['capture'], $this->effects());
        $this->assertSame('pending', $cancel->finish($key, $stub->runId(), 'stock-refused')['status']);
        $history = $this->history($stub->runId());
        $this->provider->resolve(
            Request::fromArray($this->terms->descriptor('payment')['compensation']),
            Status::Confirmed
        );
        $cancel->request($key, $stub->runId(), 'late-stock-refund-confirmed');
        $this->drain();
        $this->assertSame('started', $cancel->finish($key, $stub->runId(), 'stock-refused')['status']);
        $this->drain();
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(['capture', 'refund'], $this->effects());
        $this->assertSame($history, $this->history($stub->runId()));
        $intent = hash('sha256', PortableDescriptor::encode([$key, $stub->runId(), 'stock-refused']));
        $this->assertSame(
            $stub->runId(),
            DB::table('provider_machine_cancellations')->where('id', $intent)->sole()->root_run
        );
    }

    public function testPaymentFirstBookingCancellationStillRefundsBeforeReleasingStock(): void
    {
        $stub = $this->start();
        $key = $this->terms->subjectKey();
        app(CancellationStore::class)->request($key, $stub->runId(), 'cancel-common-checkout');
        $this->drain();
        $this->assertSame(['capture', 'reserve', 'book', 'cancel-booking', 'refund', 'release'], $this->effects());
        $this->assertSame('cancelled', $this->store->authority->subject($key, 'order')->state, $this->progress());
    }

    public function testDeferredReservationNeverDispatchesWhilePaymentIsUnconfirmed(): void
    {
        $stub = $this->start(capture: Status::Processing);
        $key = $this->terms->subjectKey();
        $this->assertSame([], $this->effects());
        $this->assertSame(0, $this->reservationCommands());
        $plan = $this->store->authority->plan($key);
        $effect = array_values(array_filter(
            (new ScopeRepository())->inspect($plan->rootScope())['obligations'],
            static fn (array $row): bool => $row['descriptor']['key'] === 'effect'
        ))[0];
        $this->assertSame('registered', $effect['forward_status']);
        $history = $this->history($stub->runId());
        $this->provider->resolve(Request::fromArray($this->terms->descriptor('payment')['forward']), Status::Confirmed);
        $cancel = app(CancellationStore::class);
        $cancel->request($key, $stub->runId(), 'reconcile-original-payment');
        $this->drain();
        $this->assertSame(['capture', 'refund'], $this->effects());
        $this->assertSame(0, $this->reservationCommands());
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame($history, $this->history($stub->runId()));
    }

    public function testProcessingReservationReconcilesWithoutRefundOrAnotherCharge(): void
    {
        $stub = $this->start(reservation: Status::Processing);
        $key = $this->terms->subjectKey();
        $this->assertSame('received', $this->store->authority->subject($key, 'order')->state);
        $this->assertFalse((bool) $this->store->authority->subject($key, 'order')->cancellation_requested);
        $this->assertSame(['capture'], $this->effects());
        $stub->signal('reconcile-stock');
        $this->drain();
        $this->assertSame('received', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame(['capture'], $this->effects());
        $this->provider->resolve(Request::fromArray($this->terms->descriptor('order')['forward']), Status::Confirmed);
        $stub->signal('reconcile-stock');
        $this->drain();
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(['capture', 'reserve', 'book'], $this->effects());
        $this->assertSame(1, $this->reservationCommands());
    }

    public function testUnknownReservationUsesOriginalUncertainAttemptAfterAuthoritativeReconciliation(): void
    {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $this->terms->descriptor($role);
            $this->provider->plan(
                Request::fromArray($descriptor['forward']),
                $role === 'order' ? Status::Unknown : Status::Confirmed
            );
            $this->provider->plan(Request::fromArray($descriptor['compensation']), Status::Confirmed);
        }
        $key = $this->terms->subjectKey();
        $forward = Request::fromArray($this->terms->descriptor('order')['forward']);
        $stub = WorkflowStub::make(CheckoutOrder::class, 'provider-order-' . $key, Catalogue::NAMESPACE);
        $stub->start($key);
        $this->drain(function () use ($forward): bool {
            $query = $this->providerDb->prepare('SELECT COUNT(*) FROM provider_operations WHERE id = ? AND status = ?');
            $query->execute([$forward->identity->key(), Status::Unknown->value]);
            return (int) $query->fetchColumn() === 1;
        });
        $this->assertSame(['capture'], $this->effects());
        $this->assertSame('received', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame(0, DB::table('provider_machine_cancellations')->count());
        $prefix = $this->history($stub->runId());
        $this->provider->resolve($forward, Status::Confirmed);
        $this->drain();
        $this->assertSame('booked', $this->store->authority->subject($key, 'order')->state, $this->progress());
        $this->assertSame(['capture', 'reserve', 'book'], $this->effects());
        $this->assertSame(1, $this->reservationCommands());
        $this->assertSame($prefix, array_slice($this->history($stub->runId()), 0, count($prefix)));
    }

    public function testAnotherWorkerRecipeCannotRebindOriginalGenerationBeforeProtectedTermsDecode(): void
    {
        $key = $this->terms->subjectKey();
        DB::table('provider_machine_assignments')->where('id', $key)->update([
            'terms' => '{invalid protected terms',
        ]);
        $wrong = new Authority(DB::connection(), 'provider-worker', [$key]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The original subject authority or selected policy is unavailable.');
        $wrong->assignment($key);
    }

    protected function recipe(): string
    {
        return Catalogue::PAYMENT_FIRST;
    }

    private function reservationCommands(): int
    {
        $query = $this->providerDb->prepare(
            'SELECT COUNT(*) FROM provider_requests WHERE operation_id = ? AND kind = ?'
        );
        $query->execute([Request::fromArray($this->terms->descriptor('order')['forward'])->identity->key(), 'command']);
        return (int) $query->fetchColumn();
    }
}
