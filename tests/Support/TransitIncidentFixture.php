<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use DurableWorkflow\Examples\ProviderStateMachines\TransitPolicy;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

/**
 * Handed-over three-unit authorization-after-stock Order with explicit unit money and a trusted transit
 * remedy policy. Test names containing "Replacement" select the replacement policy; others select refund.
 */
trait TransitIncidentFixture
{
    use PartialReturnFixture {
        setUp as private setUpReturns;
        tearDown as private tearDownReturns;
    }

    protected function setUp(): void
    {
        $this->setUpReturns();
        $schema = require __DIR__ . '/../../examples/provider-state-machines/incident-schema.php';
        $schema(DB::connection()->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        DB::connection()->getSchemaBuilder()
            ->dropIfExists(IncidentStore::TABLE);
        $this->tearDownReturns();
    }

    protected function transitPolicy(): ?string
    {
        return str_contains($this->name(), 'Replacement') ? TransitPolicy::REPLACE : TransitPolicy::REFUND;
    }

    /**
     * Provider fixture plans only; no command is dispatched.
     */
    protected function planIncident(
        string $kind,
        Status $reserve = Status::Confirmed,
        Status $claim = Status::Confirmed,
        array $saleable = []
    ): array {
        $commands = app(IncidentStore::class)->commands($this->terms->subjectKey(), $kind);
        $this->unitRefunds->plan($commands['refund'], Status::Confirmed);
        $this->provider->plan($commands['reserve'], $reserve);
        $this->provider->plan($commands['release'], Status::Confirmed);
        $this->provider->plan($commands['graph']->booking, Status::Confirmed);
        $this->provider->plan($commands['graph']->cancel, Status::Confirmed);
        $this->provider->plan($commands['claim'], $claim);
        $this->provider->plan($commands['recover'], Status::Confirmed);
        if ($saleable !== []) {
            $this->provider->plan(UnitReturnTerms::restock($commands['recover'], $saleable), Status::Confirmed);
        }
        return $commands;
    }

    /**
     * @return array{WorkflowStub, WorkflowRun}
     */
    protected function handedOver(): array
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-handover');
        $this->current();
        $this->assertSame('in-transit', $this->subjectState('shipment'), $this->progress());
        return [$origin, $shipment];
    }

    protected function incidentRow(): ?object
    {
        return DB::table(IncidentStore::TABLE)->where('id', $this->terms->subjectKey())->first();
    }

    protected function incidentRun(): WorkflowRun
    {
        return WorkflowRun::query()->findOrFail($this->incidentRow()?->native_run);
    }

    protected function incidentOutcome(): array
    {
        $row = $this->incidentRow();
        $this->assertNotNull($row?->outcome, $this->progress());
        return json_decode($row->outcome, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function hintIncident(string $requestKey): void
    {
        WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->withRequestId($requestKey)->signal(
            'incident-milestone'
        );
        $this->current();
    }

    protected function replacementCarrier(array $commands, string $kind): void
    {
        $this->provider->shipments()
            ->record($commands['graph'], 'replacement-' . $kind, $kind, $this->terms->quantity);
    }
}
