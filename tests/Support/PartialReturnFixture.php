<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use DurableWorkflow\Examples\ProviderStateMachines\ReturnStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

/** Delivered three-unit authorization-after-stock Order with explicit unit money and the return schema. */
trait PartialReturnFixture
{
    use PhysicalMachineFixture {
        setUp as private setUpPhysical;
        tearDown as private tearDownProvider;
    }

    private const DELIVERED = ['authorize', 'reserve', 'capture-authorized', 'ship-book', 'deduct-stock'];

    protected function setUp(): void
    {
        $this->setUpPhysical();
        $schema = require __DIR__ . '/../../examples/provider-state-machines/return-schema.php';
        $schema(DB::connection()->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        DB::connection()->getSchemaBuilder()
            ->dropIfExists('provider_machine_returns');
        $this->tearDownProvider();
    }

    protected function orderTerms(): OrderTerms
    {
        $now = Carbon::now()->getTimestamp();
        return new OrderTerms(
            'shop-a',
            'purchase-partial-return',
            'g1',
            1499,
            'USD',
            'sku-1',
            3,
            new AuthorizationTerms(1499, 'USD', $now + 3600),
            $now + 7200
        );
    }

    protected function unitAmounts(): array
    {
        return [500, 500, 499];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun}
     */
    protected function delivered(): array
    {
        $origin = $this->startPhysical();
        $shipment = $this->shipment();
        $this->record('handed-over');
        $this->hint($shipment, 'original-handover');
        $this->current();
        $this->record('delivered');
        $this->hint($shipment, 'original-delivery');
        $this->current();
        $this->assertSame('fulfilled', $this->subjectState('order'), $this->progress());
        $this->assertTrue($shipment->fresh()->status->isTerminal());
        $this->assertSame(self::DELIVERED, $this->effects());
        return [$origin, $shipment->fresh()];
    }

    protected function open(string $returnKey, array $units, ?array $saleable = null): Request
    {
        $key = $this->terms->subjectKey();
        $return = $this->planReturn($returnKey, $units, $saleable);
        $admitted = app(ReturnStore::class)->request($key, $returnKey, $units);
        $this->assertFalse($admitted['existing']);
        $this->drain();
        $this->assertSame(
            $return->fingerprint(),
            app(ReturnStore::class)->returnRequest($key, $returnKey)->fingerprint()
        );
        return $return;
    }

    /**
     * Provider fixture plans only; no command is dispatched. The default plans settle every requested unit.
     */
    protected function planReturn(string $returnKey, array $units, ?array $saleable = null): Request
    {
        $saleable ??= $units;
        $booking = Request::fromArray($this->terms->physicalDescriptor()['booking']);
        $return = UnitReturnTerms::request($booking, $returnKey, $units);
        $this->provider->plan($return, Status::Confirmed);
        if ($saleable !== []) {
            $this->provider->plan(UnitReturnTerms::restock($return, $saleable), Status::Confirmed);
            $this->unitRefunds->plan(new UnitRefundCommand(
                $this->store->authority->unitMoney->original($this->terms),
                'unit-return-' . $returnKey,
                $saleable
            ), Status::Confirmed);
        }
        return $return;
    }

    protected function warehouse(
        Request $return,
        string $kind,
        array $units,
        array $accepted = [],
        array $saleable = []
    ): void {
        $this->provider->shipments()
            ->recordUnitReturn(
                $return,
                $return->identity->operation . '.' . $kind,
                $kind,
                $units,
                $accepted,
                $saleable
            );
    }

    protected function hintReturn(WorkflowRun $run, string $requestKey): void
    {
        WorkflowStub::loadRun($run->id, Catalogue::NAMESPACE)->withRequestId($requestKey)->signal('return-milestone');
        $this->drain();
    }

    protected function returnRun(string $returnKey): WorkflowRun
    {
        $row = DB::table('provider_machine_returns')->where(
            'id',
            ReturnStore::id($this->terms->subjectKey(), $returnKey)
        )->sole();
        return WorkflowRun::query()->findOrFail($row->native_run);
    }

    protected function outcome(string $returnKey): array
    {
        $row = DB::table('provider_machine_returns')->where(
            'id',
            ReturnStore::id($this->terms->subjectKey(), $returnKey)
        )->sole();
        $this->assertNotNull($row->outcome, $this->progress());
        return json_decode($row->outcome, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function payouts(): array
    {
        return array_map('intval', $this->providerDb->query(
            'SELECT amount_minor FROM unit_refund_payouts ORDER BY rowid'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    protected function requests(): int
    {
        return (int) $this->providerDb->query('SELECT COUNT(*) FROM provider_requests')
            ->fetchColumn();
    }
}
