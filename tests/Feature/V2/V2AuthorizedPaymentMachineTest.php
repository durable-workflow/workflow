<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\AuthorizationCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ProviderMachineFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Stock-trigger preparation through real machines; full physical Shipment and delivery capture remain unfinished. */
final class V2AuthorizedPaymentMachineTest extends TestCase
{
    use ProviderMachineFixture {
        setUp as private setUpProvider;
    }

    protected function setUp(): void
    {
        $this->setUpProvider();
        $this->provider->installAuthorizationSupport();
    }

    public function testOriginalStockCaptureUsesBusinessMilestonesWhilePaymentIsStillOpen(): void
    {
        $origin = $this->startAuthorized(fn (): bool => $this->publicationExists('authorized')
            && WorkflowSignal::query()->where('signal_name', 'financial-milestone')->exists());
        $this->assertSame(['authorize'], $this->effects(), $this->progress());
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $actor = DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), 'payment')
        )->sole();
        $payment = WorkflowRun::query()->findOrFail($actor->native_run);
        $this->assertFalse($payment->status->isTerminal());
        $pair = (new ScopeRepository())->inspect($plan->childScope('payment'));
        $this->assertCount(3, $pair['obligations']);
        $ids = array_column($pair['obligations'], 'id');
        $instance = $payment->workflow_instance_id;
        $this->drain(static fn (): bool => DB::table('provider_machine_milestones')->count() === 1, $instance);
        $this->assertSame(['authorize', 'reserve'], $this->effects(), $this->progress());
        $this->assertSame('authorized', $this->store->authority->subject($this->terms->subjectKey(), 'payment')->state);
        $this->drain(fn (): bool => $this->publicationExists('captured'));
        $this->assertTrue($this->publicationExists('captured'), $this->progress());
        $this->assertFalse($payment->fresh()->status->isTerminal());
        $this->drain(fn (): bool => $this->rootState() === 'ready', $instance);
        $this->assertSame('ready', $this->rootState(), $this->progress());
        $this->assertFalse($payment->fresh()->status->isTerminal());
        $this->assertSame(['authorize', 'reserve', 'capture-authorized'], $this->effects());
        $this->assertSame(
            $ids,
            array_column((new ScopeRepository())->inspect($plan->childScope('payment'))['obligations'], 'id')
        );
        $this->assertFalse(WorkflowRun::query()->findOrFail($origin->runId())->status->isTerminal());
        $requests = (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
        $history = $this->history($origin->runId());
        app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($origin->runId()));
        $this->assertSame(
            $requests,
            (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
                ->fetchColumn()
        );
        $this->assertSame($history, $this->history($origin->runId()));
    }

    public function testCancellationBeforeTheStockTriggerVoidsTheOriginalPermissionWithoutCapture(): void
    {
        $origin = $this->startAuthorized(fn (): bool => $this->publicationExists('authorized'));
        app(CancellationStore::class)->request(
            $this->terms->subjectKey(),
            $origin->runId(),
            'cancel-authorized-before-stock'
        );
        $this->drain();
        $plan = $this->store->authority->plan($this->terms->subjectKey());
        $root = (new ScopeRepository())->inspect($plan->rootScope());
        $this->assertSame('verified', $root['status'], $this->progress());
        $this->assertSame(['authorize', 'void-authorization'], $this->effects(), $this->progress());
        $this->assertSame('cancelled', $this->rootState(), $this->progress());
    }

    public function testLostAuthorizationNotificationAtTheOriginalDeadlineCannotDispatchStock(): void
    {
        $this->startAuthorized(fn (): bool => $this->publicationExists('authorized'));
        $key = $this->terms->subjectKey();
        $actor = DB::table('provider_machine_actors')->where('id', Authority::subjectId($key, 'payment'))->sole();
        $payment = WorkflowRun::query()->findOrFail($actor->native_run);
        $this->drain(
            fn (): bool => (bool) $this->store->authority->subject($key, 'order')
->cancellation_requested,
            $payment->workflow_instance_id
        );
        $this->assertTrue(
            (bool) $this->store->authority->subject($key, 'order')
->cancellation_requested,
            $this->progress()
        );
        $this->assertSame(['authorize'], $this->effects());
        $this->assertGreaterThanOrEqual($this->terms->authorization->expiresAt, Carbon::now()->getTimestamp());
        $plan = $this->store->authority->plan($key);
        $ledger = (new ScopeRepository())->inspect($plan->rootScope());
        $stock = array_values(array_filter($ledger['obligations'], static fn (array $row): bool =>
            $row['descriptor']['key'] === 'effect'))[0];
        $this->assertSame('registered', $stock['forward_status']);
        $this->assertFalse($payment->fresh()->status->isTerminal());
        $this->assertNotSame('cancelled', $this->rootState());
    }

    public function testDeliveryCaptureCannotUseTheBookingOnlyAssembly(): void
    {
        $terms = OrderTerms::fromArray([
            ...$this->terms->toArray(),
            'generation' => 'delivery-generation',
        ]);
        $key = $terms->subjectKey();
        $store = new MachineStore(new Authority(DB::connection(), 'provider-worker', [$key], [
            $key => FinancialCatalogue::AFTER_DELIVERY,
        ]));
        try {
            $store->seed($terms);
            $this->fail('Delivery capture requires the actual full Shipment producer.');
        } catch (LogicException $error) {
            $this->assertSame('The complete physical Shipment producer is not assembled yet.', $error->getMessage());
        }
        $this->assertFalse(DB::table('provider_machine_assignments')->where('id', $key)->exists());
    }

    public function testARehashedForeignStockReceiptCannotAuthorizeTheOriginalCapture(): void
    {
        $this->startAuthorized(fn (): bool => $this->publicationExists('authorized')
            && WorkflowSignal::query()->where('signal_name', 'financial-milestone')->exists());
        $key = $this->terms->subjectKey();
        $actor = DB::table('provider_machine_actors')->where('id', Authority::subjectId($key, 'payment'))->sole();
        $payment = WorkflowRun::query()->findOrFail($actor->native_run);
        $this->drain(
            static fn (): bool => DB::table('provider_machine_milestones')->count() === 1,
            $payment->workflow_instance_id
        );
        $row = DB::table('provider_machine_milestones')->sole();
        $value = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $value['stockEffect'] = 'another-stock-effect';
        $payload = json_encode($value, JSON_THROW_ON_ERROR);
        DB::table('provider_machine_milestones')->where('id', $row->id)->update([
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
        ]);
        $this->drain(static fn (): bool => $payment->fresh()->status->isTerminal());
        $this->assertTrue($payment->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame(['authorize', 'reserve'], $this->effects());
        $scope = $this->store->authority->plan($key)
            ->childScope('payment');
        $ledger = (new ScopeRepository())->inspect($scope);
        $this->assertSame('registered', $ledger['obligations'][2]['forward_status']);
        $this->assertFalse($this->publicationExists('captured'));
    }

    public function testProcessingStockUsesOneOriginalCommandAndASignalCannotAuthorizeCapture(): void
    {
        $stock = Request::fromArray($this->terms->descriptor('order')['forward']);
        $origin = $this->startAuthorized(function () use ($stock): bool {
            $q = $this->providerDb->prepare('SELECT COUNT(*) FROM provider_operations WHERE id = ? AND status = ?');
            $q->execute([$stock->identity->key(), Status::Processing->value]);
            return (int) $q->fetchColumn() === 1;
        }, reservation: Status::Processing);
        $this->drainCurrent();
        $this->assertSame(['authorize'], $this->effects());
        $this->assertSame('received', $this->rootState());
        $origin->withRequestId('unproven-stock-hint')
            ->signal('reconcile-stock', true);
        $this->drainCurrent();
        $this->assertSame(['authorize'], $this->effects());
        $this->assertSame(0, DB::table('provider_machine_milestones')->count());
        $this->provider->resolve($stock, Status::Confirmed);
        $origin->withRequestId('original-stock-confirmed')
            ->signal('reconcile-stock');
        $this->drainCurrent();
        $this->assertSame('ready', $this->rootState(), $this->progress());
        $this->assertSame(['authorize', 'reserve', 'capture-authorized'], $this->effects());
        $q = $this->providerDb->prepare('SELECT COUNT(*) FROM provider_requests WHERE operation_id = ? AND kind = ?');
        $q->execute([$stock->identity->key(), 'command']);
        $this->assertSame(1, (int) $q->fetchColumn());
    }

    public function testDefinitiveStockRefusalVoidsPermissionWithoutCaptureRefundOrFictitiousRelease(): void
    {
        $this->startAuthorized(reservation: Status::Refused);
        $this->assertSame('cancelled', $this->rootState(), $this->progress());
        $this->assertSame(['authorize', 'void-authorization'], $this->effects());
        $this->assertSame('verified', (new ScopeRepository())->inspect(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        )['status']);
    }

    public function testClosedCapturedPaymentMustConfirmRefundBeforeReservationRelease(): void
    {
        $origin = $this->startAuthorized(refund: Status::Unknown);
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $this->assertSame('ready', $this->rootState(), $this->progress());
        $actor = DB::table('provider_machine_actors')->where('id', Authority::subjectId($key, 'payment'))->sole();
        $this->assertTrue(WorkflowRun::query()->findOrFail($actor->native_run)->status->isTerminal());
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $origin->runId(), 'original-refund-unknown');
        $this->drain();
        $this->assertSame('requested', (new ScopeRepository())->metadata($plan->rootScope())['status']);
        $this->assertSame(['authorize', 'reserve', 'capture-authorized'], $this->effects());
        $this->assertNotSame('cancelled', $this->rootState());
        $history = $this->history($origin->runId());
        $this->provider->resolve(Request::fromArray($this->terms->financialDescriptor()['refund']), Status::Confirmed);
        $cancellation->request($key, $origin->runId(), 'original-refund-now-confirmed');
        $this->drain();
        $this->assertSame(
            'verified',
            (new ScopeRepository())->metadata($plan->rootScope())['status'],
            $this->progress()
        );
        $this->assertSame(
            ['authorize', 'reserve', 'capture-authorized', 'refund-authorized', 'release'],
            $this->effects()
        );
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame(
            'started',
            $cancellation->finish($key, $origin->runId(), 'original-refund-now-confirmed')['status']
        );
        $this->drain();
        $this->assertSame('cancelled', $this->rootState(), $this->progress());
    }

    protected function recipe(): string
    {
        return FinancialCatalogue::AFTER_STOCK;
    }

    protected function orderTerms(): OrderTerms
    {
        return new OrderTerms(
            'shop-a',
            'purchase-1',
            'business-generation-1',
            1499,
            'USD',
            'sku-1',
            2,
            new AuthorizationTerms(1499, 'USD', Carbon::now()->getTimestamp() + 3600)
        );
    }

    protected function providerClock(): ?\Closure
    {
        return static fn (): int => Carbon::now()->getTimestamp();
    }

    private function drainCurrent(): void
    {
        // Advance ordinary short task scheduling, while keeping the original one-hour business timers pending.
        $through = Carbon::now()->copy()->addSeconds(30);
        $this->drain(static fn (): bool => ! WorkflowTask::query()
            ->whereRelation('run', 'namespace', Catalogue::NAMESPACE)->where('status', TaskStatus::Ready)
            ->where(static fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $through))
            ->exists());
    }

    private function startAuthorized(
        ?\Closure $stop = null,
        Status $reservation = Status::Confirmed,
        Status $refund = Status::Confirmed
    ): WorkflowStub {
        foreach ($this->terms->descriptor('order') as $stage => $request) {
            $this->provider->plan(
                Request::fromArray($request),
                $stage === 'forward' ? $reservation : Status::Confirmed
            );
        }
        foreach ($this->terms->financialDescriptor() as $stage => $request) {
            $this->provider->plan(Request::fromArray($request), $stage === 'refund' ? $refund : Status::Confirmed);
        }
        $origin = WorkflowStub::make(
            AuthorizationCheckout::class,
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $origin->start($this->terms->subjectKey());
        $this->drain($stop);
        return $origin;
    }

    private function publicationExists(string $state): bool
    {
        return DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), 'payment')
        )->get()
            ->contains(
                static fn (object $row): bool => json_decode(
                    $row->payload,
                    true,
                    flags: JSON_THROW_ON_ERROR
                )['state'] === $state
            );
    }

    private function rootState(): string
    {
        return $this->store->authority->subject($this->terms->subjectKey(), 'order')
->state;
    }
}
