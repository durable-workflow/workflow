<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../examples/provider-state-machines/bootstrap.php';

/** Original native card/bank fixture, shared only to distribute unchanged cases across bounded source shards. */
trait CommonFundingMachineFixture
{
    use ProviderMachineFixture { tearDown as private tearDownProvider; }

    private ?IncomingPaymentProvider $incoming = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::stopWorkers();
        Queue::fake();
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        config([
            'workflows.v2.namespaces' => [Catalogue::NAMESPACE],
            'workflows.v2.task_dispatch_mode' => 'queue',
            'queue.default' => 'redis',
        ]);
        foreach (['business-state-machines', 'provider-state-machines'] as $example) {
            $schema = require __DIR__ . '/../../examples/' . $example . '/schema.php';
            $schema(DB::connection()->getSchemaBuilder());
        }
        $this->providerPath = tempnam(sys_get_temp_dir(), 'common-funding-');
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->incoming = new IncomingPaymentProvider($this->providerDb, 'shop-a', static fn (): int => now()->getTimestamp());
        $this->provider = $this->incoming->commands;
        $this->provider->install();
        $this->provider->installAuthorizationSupport();
        $this->provider->shipments()
            ->install();
        $this->incoming->install();
    }

    protected function tearDown(): void
    {
        $this->incoming = null;
        $this->tearDownProvider();
    }

    private function startFunding(
        string $recipe,
        Status $stock = Status::Confirmed,
        string $contract = IncomingPaymentJournal::CLOSABLE
    ): WorkflowStub {
        $now = now()
            ->getTimestamp();
        $this->terms = new OrderTerms(
            'shop-a',
            'purchase-funding',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            FundingCatalogue::requiresAuthorization($recipe) ? new AuthorizationTerms(1499, 'USD', $now + 3600) : null,
            $now + 7200,
            $recipe === FundingCatalogue::BANK ? new IncomingPaymentDescriptor(new Identity(
                'shop-a',
                'purchase-funding',
                'g1',
                'incoming'
            ), 'original-bank-reference', 1499, 'USD', $now + 3600) : null,
            $recipe === FundingCatalogue::BANK ? $contract : IncomingPaymentJournal::OPEN
        );
        $authority = new Authority(DB::connection(), 'provider-worker', [$this->terms->subjectKey()], [
            $this->terms->subjectKey() => $recipe,
        ]);
        $this->store = MachineStore::configure($this->app, $authority, $this->provider, $this->incoming);
        $this->store->seed($this->terms);
        $graphs = [$this->terms->descriptor('order'), $this->terms->physicalDescriptor()];
        if ($recipe !== FundingCatalogue::BANK) {
            $graphs[] = FundingCatalogue::requiresAuthorization(
                $recipe
            ) ? $this->terms->financialDescriptor() : $this->terms->descriptor('payment');
        }
        foreach ($graphs as $graph) {
            foreach ($graph as $value) {
                $request = Request::fromArray($value);
                $this->provider->plan($request, $request->action === 'reserve' ? $stock : Status::Confirmed);
            }
        }
        $origin = WorkflowStub::make(
            FundingCheckout::class,
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $origin->start($this->terms->subjectKey());
        $this->pump();
        return $origin;
    }

    private function receive(string $id, int $amount): IncomingPaymentReceipt
    {
        $receipt = new IncomingPaymentReceipt(
            $this->terms->incomingPayment->identity->key(),
            $id,
            $amount,
            'USD',
            now()
                ->getTimestamp()
        );
        $this->incoming->receipts->record($this->terms->incomingPayment, $receipt);
        return $receipt;
    }

    private function wakePayment(string $id): bool
    {
        $payment = WorkflowStub::loadRun($this->child('payment')->id, Catalogue::NAMESPACE)->withRequestId($id);
        $result = $payment->attemptSignal('incoming-observed', [
            'full' => true,
        ]);
        $this->pump();
        return $result->accepted();
    }

    private function recordPhysical(string $kind): void
    {
        $this->provider->shipments()
            ->record(
                ShipmentDescriptor::fromArray($this->terms->physicalDescriptor()),
                'original-' . $kind,
                $kind,
                $this->terms->quantity
            );
        WorkflowStub::loadRun($this->child('shipment')->id, Catalogue::NAMESPACE)->withRequestId(
            'physical-' . $kind
        )->signal('physical-milestone');
        $this->pump();
    }

    private function child(string $role): WorkflowRun
    {
        $actor = DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), $role)
        )->sole();
        return WorkflowRun::query()->findOrFail($actor->native_run);
    }

    private function state(string $role): string
    {
        return $this->store->authority->subject($this->terms->subjectKey(), $role)
->state;
    }

    private function pump(): void
    {
        $through = Carbon::now()->copy()->addSeconds(30);
        $this->drain(
            static fn (): bool => ! WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
                ->where('status', TaskStatus::Ready)->where(
                    static fn ($q) => $q->whereNull('available_at')
                        ->orWhere('available_at', '<=', $through)
                )->exists()
        );
    }
}
