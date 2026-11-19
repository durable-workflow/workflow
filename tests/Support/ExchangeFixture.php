<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\ExchangeStore;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

/** Delivered three-unit Order of PartialReturnFixture plus the same-price exchange schema. */
trait ExchangeFixture
{
    use PartialReturnFixture {
        setUp as private setUpReturns;
        tearDown as private tearDownReturns;
    }

    private const REPLACEMENT_SKU = 'sku-1-size-l';

    protected function setUp(): void
    {
        $this->setUpReturns();
        $schema = require __DIR__ . '/../../examples/provider-state-machines/exchange-schema.php';
        $schema(DB::connection()->getSchemaBuilder());
    }

    protected function tearDown(): void
    {
        DB::connection()->getSchemaBuilder()
            ->dropIfExists(ExchangeStore::TABLE);
        $this->tearDownReturns();
    }

    /**
     * Provider fixture plans only; no command is dispatched.
     *
     * @return array{request: Request, reserve: Request, release: Request, graph: \DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor, refund: UnitRefundCommand}
     */
    protected function planExchange(
        string $exchangeKey,
        int $unit,
        Status $reserve = Status::Confirmed,
        Status $book = Status::Confirmed,
        bool $loseReservationAcknowledgement = false
    ): array {
        $booking = Request::fromArray($this->terms->physicalDescriptor()['booking']);
        $return = UnitReturnTerms::request($booking, ExchangeStore::RETURN_PREFIX . $exchangeKey, [$unit]);
        $this->provider->plan($return, Status::Confirmed);
        $this->provider->plan(UnitReturnTerms::restock($return, [$unit]), Status::Confirmed);
        $replacement = ExchangeStore::replacementCommands($booking, $exchangeKey, self::REPLACEMENT_SKU);
        $this->provider->plan($replacement['reserve'], $reserve, $loseReservationAcknowledgement);
        $this->provider->plan($replacement['release'], Status::Confirmed);
        $this->provider->plan($replacement['graph']->booking, $book);
        $this->provider->plan($replacement['graph']->cancel, Status::Confirmed);
        $refund = new UnitRefundCommand(
            $this->store->authority->unitMoney->original($this->terms),
            'unit-exchange-' . $exchangeKey,
            [$unit]
        );
        $this->unitRefunds->plan($refund, Status::Confirmed);
        return [
            'request' => $return,
            ...$replacement,
            'refund' => $refund,
        ];
    }

    protected function exchange(string $exchangeKey, int $unit, ?int $priceMinor = null): array
    {
        return app(ExchangeStore::class)->request(
            $this->terms->subjectKey(),
            $exchangeKey,
            $unit,
            self::REPLACEMENT_SKU,
            $priceMinor ?? $this->unitAmounts()[$unit]
        );
    }

    protected function exchangeRun(string $exchangeKey): WorkflowRun
    {
        return WorkflowRun::query()->findOrFail($this->exchangeRow($exchangeKey)->native_run);
    }

    protected function exchangeRow(string $exchangeKey): object
    {
        return DB::table(ExchangeStore::TABLE)->where(
            'id',
            ExchangeStore::id($this->terms->subjectKey(), $exchangeKey)
        )->sole();
    }

    protected function exchangeOutcome(string $exchangeKey): array
    {
        $row = $this->exchangeRow($exchangeKey);
        $this->assertNotNull($row->outcome, $this->progress());
        return json_decode($row->outcome, true, flags: JSON_THROW_ON_ERROR);
    }

    protected function hintExchange(WorkflowRun $run, string $requestKey): void
    {
        WorkflowStub::loadRun($run->id, Catalogue::NAMESPACE)->withRequestId($requestKey)->signal(
            'exchange-milestone'
        );
        $this->drain();
    }

    protected function inspectOriginal(array $commands, WorkflowRun $run, int $unit, bool $accepted = true): void
    {
        $this->warehouse($commands['request'], 'received', [$unit]);
        $this->warehouse(
            $commands['request'],
            'inspected',
            [$unit],
            $accepted ? [$unit] : [],
            $accepted ? [$unit] : []
        );
        $this->hintExchange($run, $run->id . '-inspection');
    }

    protected function carrier(array $commands, string $kind): void
    {
        $this->provider->shipments()
            ->record($commands['graph'], 'replacement-' . $kind, $kind, 1);
    }
}
