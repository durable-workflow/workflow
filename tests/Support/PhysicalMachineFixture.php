<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\AuthorizationCheckout;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

trait PhysicalMachineFixture
{
    use ProviderMachineFixture { setUp as private setUpProvider; }

    protected function setUp(): void
    {
        $this->setUpProvider();
        $this->provider->installAuthorizationSupport();
        $this->provider->shipments()
            ->install();
    }

    protected function recipe(): string
    {
        return FinancialCatalogue::AFTER_STOCK;
    }

    protected function orderTerms(): OrderTerms
    {
        $now = Carbon::now()->getTimestamp();
        return new OrderTerms(
            'shop-a',
            'purchase-physical',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            new AuthorizationTerms(1499, 'USD', $now + 3600),
            $now + 7200
        );
    }

    protected function providerClock(): ?\Closure
    {
        return static fn (): int => Carbon::now()->getTimestamp();
    }

    protected function startPhysical(): WorkflowStub
    {
        $origin = $this->beginPhysical();
        $this->drain(fn (): bool => $this->subjectState('order') === 'ready');
        $origin->withRequestId('request-original-fulfillment')
            ->signal('fulfill');
        $this->current();
        return $origin;
    }

    /**
     * Plans every original provider outcome and starts the Order without running any of its tasks.
     */
    protected function beginPhysical(): WorkflowStub
    {
        foreach ([
            $this->terms->descriptor('order'),
            $this->terms->financialDescriptor(),
            $this->terms->physicalDescriptor(),
        ] as $graph) {
            foreach ($graph as $value) {
                $this->provider->plan(Request::fromArray($value), Status::Confirmed);
            }
        }
        $origin = WorkflowStub::make(
            AuthorizationCheckout::class,
            'provider-order-' . $this->terms->subjectKey(),
            Catalogue::NAMESPACE
        );
        $origin->start($this->terms->subjectKey());
        return $origin;
    }

    protected function current(): void
    {
        $through = Carbon::now()->copy()->addSeconds(30);
        $this->drain(
            static fn (): bool => ! WorkflowTask::query()->whereRelation('run', 'namespace', Catalogue::NAMESPACE)
                ->where('status', TaskStatus::Ready)
                ->where(static fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', $through))
                ->exists()
        );
    }

    protected function graph(): ShipmentDescriptor
    {
        return ShipmentDescriptor::fromArray($this->terms->physicalDescriptor());
    }

    protected function record(string $kind): void
    {
        $this->provider->shipments()
            ->record($this->graph(), 'original-' . $kind, $kind, $this->terms->quantity);
    }

    protected function hint(WorkflowRun $shipment, string $key): void
    {
        WorkflowStub::loadRun($shipment->id, Catalogue::NAMESPACE)->withRequestId($key)->signal('physical-milestone');
    }

    protected function shipment(): WorkflowRun
    {
        $actor = DB::table('provider_machine_actors')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), 'shipment')
        )->sole();
        return WorkflowRun::query()->findOrFail($actor->native_run);
    }

    protected function subjectState(string $role): string
    {
        return $this->store->authority->subject($this->terms->subjectKey(), $role)
->state;
    }

    protected function deliveredPublication(): bool
    {
        return $this->published('shipment', 'delivered');
    }

    protected function published(string $role, string $state): bool
    {
        return DB::table('example_machine_publications')->where(
            'subject_id',
            Authority::subjectId($this->terms->subjectKey(), $role)
        )
            ->where('payload', 'like', '%"state":"' . $state . '"%')
            ->exists();
    }

    protected function physicalFacts(): array
    {
        return $this->provider->shipments()
            ->inspect($this->graph())
            ->toArray()['facts'];
    }
}
