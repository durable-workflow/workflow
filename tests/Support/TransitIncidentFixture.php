<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use DurableWorkflow\Examples\ProviderStateMachines\TransitPolicy;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
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

    protected const TRANSIT_WAKES = 4;

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
     * A small recorded wake bound keeps the bounded incident waits and their histories short.
     */
    protected function transitWakes(): int
    {
        return self::TRANSIT_WAKES;
    }

    /**
     * Provider fixture plans only; no command is dispatched.
     */
    protected function planIncident(
        string $kind,
        Status $reserve = Status::Confirmed,
        Status $claim = Status::Confirmed,
        array $saleable = [],
        Status $book = Status::Confirmed,
        Status $refund = Status::Confirmed
    ): array {
        $commands = app(IncidentStore::class)->commands($this->terms->subjectKey(), $kind);
        $this->unitRefunds->plan($commands['refund'], $refund);
        $this->provider->plan($commands['reserve'], $reserve);
        $this->provider->plan($commands['release'], Status::Confirmed);
        $this->provider->plan($commands['graph']->booking, $book);
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

    /**
     * A refused business cancellation records no intent, fence or compensation request.
     */
    protected function assertCancellationRefused(string $requestKey, string $remedy): void
    {
        $origin = DB::table('provider_machine_assignments')->where('id', $this->terms->subjectKey())->sole();
        $result = app(CancellationStore::class)->request($this->terms->subjectKey(), $origin->root_run, $requestKey);
        $this->assertSame('refused', $result['status']);
        $this->assertSame('transit_incident_owns_remedy', $result['reason']);
        $this->assertSame($remedy, $result['remedy']);
        $this->assertFalse(DB::table('provider_machine_cancellations')->where('root_key', $this->terms->subjectKey())
            ->exists());
        $this->assertFalse((bool) DB::table('example_machine_subjects')->where(
            'id',
            Authority::subjectId($this->terms->subjectKey(), 'order')
        )->value('cancellation_requested'));
    }

    protected function replacementCarrier(array $commands, string $kind): void
    {
        $this->provider->shipments()
            ->record($commands['graph'], 'replacement-' . $kind, $kind, $this->terms->quantity);
    }

    /**
     * Replaying the incident and the given runs issues no provider request.
     */
    protected function assertIncidentReplayQuiet(string ...$runs): void
    {
        $requests = $this->requests();
        foreach ([$this->incidentRun()->id, ...$runs] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($requests, $this->requests());
    }
}
