<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\ExchangeStore;
use DurableWorkflow\Examples\ProviderStateMachines\ReturnStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ExchangeFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-05: one delivered unit exchanged for another size at the same price. A separate native exchange
 * receives and inspects the original unit, reserves the replacement, then books and tracks it under its own
 * identities. Synthetic recorded-task fixture, not installed-package, real-carrier or complete-matrix qualification.
 */
final class V2ExchangeMachineTest extends TestCase
{
    use ExchangeFixture;

    private const RETURNED = [...self::DELIVERED, 'request-unit-return', 'restock-units'];

    public function testAcceptedOriginalIsReplacedWithoutMoneyMovementWhileTheOrderStaysFulfilled(): void
    {
        [$origin, $shipment] = $this->delivered();
        $history = $this->history($origin->runId());
        $childHistory = $this->history($shipment->id);
        $commands = $this->planExchange('swap-1', 2);
        $admitted = $this->exchange('swap-1', 2);
        $this->assertFalse($admitted['existing']);
        $this->drain();
        $run = $this->exchangeRun('swap-1');
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects(), $this->progress());
        $duplicate = $this->exchange('swap-1', 2);
        $this->assertTrue($duplicate['existing'], 'The same exchange key and terms are idempotent.');
        $this->assertSame($run->id, $duplicate['runId']);

        $this->warehouse($commands['request'], 'received', [2]);
        $this->hintExchange($run, 'swap-1-receipt');
        $this->assertFalse($run->fresh()->status->isTerminal(), 'Receipt alone is not acceptance.');
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
        $this->warehouse($commands['request'], 'inspected', [2], [2], [2]);
        $this->hintExchange($run, 'swap-1-inspection');
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $this->assertFalse($run->fresh()->status->isTerminal(), 'A booked replacement is not a delivered one.');
        $this->assertNull($this->exchangeRow('swap-1')->outcome);
        $this->carrier($commands, 'handed-over');
        $this->hintExchange($run, 'swap-1-handover');
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->carrier($commands, 'delivered');
        $this->hintExchange($run, 'swap-1-delivery');

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects());
        $this->assertSame([], $this->payouts(), 'A same-price exchange moves no money.');
        $outcome = $this->exchangeOutcome('swap-1');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('replaced', $outcome['resolution']);
        $this->assertSame('fulfilled', $outcome['originalOrderState']);
        foreach (['receivedUnits', 'acceptedUnits', 'saleableUnits', 'restockedUnits'] as $field) {
            $this->assertSame([2], $outcome[$field], $field);
        }
        $this->assertSame([], $outcome['discrepancyUnits']);
        $this->assertSame([], $outcome['refundedUnits']);
        $this->assertSame(0, $outcome['moneyMovementMinor']);
        $this->assertSame(0, $outcome['refundOwedMinor']);
        $this->assertTrue($outcome['replacementHandedOver']);
        $this->assertTrue($outcome['replacementDelivered']);
        $this->assertSame(self::REPLACEMENT_SKU, $outcome['replacementSku']);
        // Original shipment, return and replacement keep separate identities.
        $identities = [
            $outcome['originalBooking'],
            $outcome['returnOperation'],
            $outcome['replacementReservation'],
            $outcome['replacementBooking'],
        ];
        $this->assertSame([
            $this->graph()
                ->booking->identity->key(),
            $commands['request']->identity->key(),
            $commands['reserve']->identity->key(),
            $commands['graph']->booking->identity->key(),
        ], $identities);
        $this->assertCount(4, array_unique($identities));
        $this->assertSame($outcome, WorkflowStub::loadRun($run->id, Catalogue::NAMESPACE)->output());

        // The original Order, its delivery and its full-parcel counters stay historical.
        $this->assertSame('fulfilled', $this->subjectState('order'));
        $this->assertSame('delivered', $this->subjectState('shipment'));
        $facts = $this->physicalFacts();
        $this->assertTrue($facts['original_recipient_delivered']);
        $this->assertSame(3, $facts['deducted_quantity']);
        $this->assertSame(0, $facts['restocked_quantity']);
        $this->assertFalse($facts['return_requested']);
        $this->assertTrue($this->deliveredPublication());
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($childHistory, $this->history($shipment->id));

        $before = $this->requests();
        foreach ([$run->id, $origin->runId(), $shipment->id] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($before, $this->requests());
    }

    public function testRejectedOriginalGetsNoReplacementRestockOrRefund(): void
    {
        $this->delivered();
        $commands = $this->planExchange('swap-rejected', 0);
        $this->exchange('swap-rejected', 0);
        $this->drain();
        $run = $this->exchangeRun('swap-rejected');
        $this->inspectOriginal($commands, $run, 0, accepted: false);

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
        $this->assertSame([], $this->payouts());
        $outcome = $this->exchangeOutcome('swap-rejected');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('original-rejected', $outcome['resolution']);
        $this->assertSame([0], $outcome['receivedUnits']);
        $this->assertSame([], $outcome['acceptedUnits']);
        $this->assertSame([0], $outcome['discrepancyUnits']);
        $this->assertSame([], $outcome['restockedUnits']);
        $this->assertNull($outcome['replacementReservation']);
        $this->assertNull($outcome['replacementBooking']);
        $this->assertSame(0, $outcome['moneyMovementMinor']);
        $this->assertSame(Status::Unknown, $this->provider->lookup($commands['reserve'])->status);
        $this->assertSame('fulfilled', $this->subjectState('order'));
    }

    public function testDefinitivelyUnavailableReplacementRefundsOnlyTheOriginalUnitAmount(): void
    {
        $this->delivered();
        $commands = $this->planExchange('swap-unavailable', 2, reserve: Status::Refused);
        $this->exchange('swap-unavailable', 2);
        $this->drain();
        $run = $this->exchangeRun('swap-unavailable');
        $this->inspectOriginal($commands, $run, 2);

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        $this->assertSame(self::RETURNED, $this->effects());
        $this->assertSame([499], $this->payouts());
        $outcome = $this->exchangeOutcome('swap-unavailable');
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('refunded-replacement-unavailable', $outcome['resolution']);
        $this->assertSame('refused', $outcome['replacementReservationStatus']);
        $this->assertNull($outcome['replacementBooking']);
        $this->assertSame([2], $outcome['restockedUnits']);
        $this->assertSame([2], $outcome['refundedUnits']);
        $this->assertSame(499, $outcome['refundedAmountMinor']);
        $this->assertSame(499, $outcome['moneyMovementMinor']);
        $this->assertSame(Status::Unknown, $this->provider->lookup($commands['graph']->booking)->status);
        $this->assertSame('fulfilled', $this->subjectState('order'));
    }

    public function testUnknownReservationOutcomeIsReconciledAsTheSameOperationNotARefusal(): void
    {
        $this->delivered();
        $commands = $this->planExchange('swap-unknown', 1, loseReservationAcknowledgement: true);
        $this->exchange('swap-unknown', 1);
        $this->drain();
        $run = $this->exchangeRun('swap-unknown');
        $this->inspectOriginal($commands, $run, 1);

        // The lost acknowledgement left one committed reservation; recovery looked it up instead of refunding.
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $this->assertSame([], $this->payouts());
        $this->assertFalse($run->fresh()->status->isTerminal());
        $this->carrier($commands, 'handed-over');
        $this->carrier($commands, 'delivered');
        $this->hintExchange($run, 'swap-unknown-delivery');
        $this->assertSame('replaced', $this->exchangeOutcome('swap-unknown')['resolution']);
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects());
    }

    public function testRefusedReplacementBookingCompensatesOnlyTheExchangeOwnOperations(): void
    {
        [$origin, $shipment] = $this->delivered();
        $history = $this->history($origin->runId());
        $childHistory = $this->history($shipment->id);
        $commands = $this->planExchange('swap-carrier', 2, book: Status::Refused);
        $this->exchange('swap-carrier', 2);
        $this->drain();
        $run = $this->exchangeRun('swap-carrier');
        $this->inspectOriginal($commands, $run, 2);

        $this->assertTrue($run->fresh()->status->isTerminal(), $this->progress());
        // Only the replacement reservation was released; the returned unit stays returned and restocked.
        $this->assertSame([...self::RETURNED, 'reserve', 'release'], $this->effects());
        $this->assertSame([], $this->payouts());
        $outcome = $this->exchangeOutcome('swap-carrier');
        $this->assertSame('compensated', $outcome['status']);
        $this->assertSame('replacement-dispatch-refused', $outcome['resolution']);
        $this->assertSame('refused', $outcome['replacementBookingStatus']);
        $this->assertSame(499, $outcome['refundOwedMinor'], 'The owed unit amount stays explicit, not settled.');
        $this->assertSame(0, $outcome['moneyMovementMinor']);
        $scope = app(ExchangeStore::class)->scope($this->terms->subjectKey(), 'swap-carrier', $run->id);
        $this->assertSame('verified', (new ScopeRepository())->metadata($scope)['status']);
        $this->assertSame('fulfilled', $this->subjectState('order'));
        $this->assertSame('delivered', $this->subjectState('shipment'));
        $this->assertSame(3, $this->physicalFacts()['deducted_quantity']);
        $this->assertSame($history, $this->history($origin->runId()));
        $this->assertSame($childHistory, $this->history($shipment->id));
        $root = (new ScopeRepository())->metadata(
            $this->store->authority->plan($this->terms->subjectKey())
                ->rootScope()
        );
        $this->assertNotSame('verified', $root['status']);
    }

    public function testOldOrderCompensationNeverTargetsTheReplacement(): void
    {
        [$origin] = $this->delivered();
        $key = $this->terms->subjectKey();
        $commands = $this->planExchange('swap-old', 2);
        $this->exchange('swap-old', 2);
        $this->drain();
        $run = $this->exchangeRun('swap-old');
        $this->inspectOriginal($commands, $run, 2);
        $this->carrier($commands, 'handed-over');
        $this->carrier($commands, 'delivered');
        $this->hintExchange($run, 'swap-old-delivery');
        $this->assertSame('replaced', $this->exchangeOutcome('swap-old')['resolution'], $this->progress());
        $effects = $this->effects();
        $replacementFacts = $this->provider->shipments()
            ->inspect($commands['graph'])
            ->toArray();

        // The exchanged original unit can no longer be returned or refunded through a customer unit return.
        try {
            app(ReturnStore::class)->request($key, 'rma-after-exchange', [2]);
            $this->fail('An exchanged unit was admitted to a customer return.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('exchange', $error->getMessage());
        }
        // A later full cancellation of the original Order compensates only its own original graph.
        $this->unitRefunds->plan(new UnitRefundCommand(
            $this->store->authority->unitMoney->original($this->terms),
            'original-order-payment-cancellation',
            [0, 1, 2]
        ), Status::Confirmed);
        app(CancellationStore::class)->request($key, $origin->runId(), 'full-return-after-exchange');
        $this->drain();
        $this->assertSame($effects, $this->effects());
        $this->assertSame([], $this->payouts());
        $this->assertSame($replacementFacts, $this->provider->shipments()->inspect($commands['graph'])->toArray());
        foreach (['cancel', 'return', 'restock'] as $stage) {
            $this->assertSame(Status::Unknown, $this->provider->lookup($commands['graph']->{$stage})->status, $stage);
        }
        $this->assertSame(Status::Unknown, $this->provider->lookup($commands['release'])->status);
        $this->assertSame('fulfilled', $this->subjectState('order'));

        // An original-graph cancellation that names the replacement booking is refused by the provider.
        $original = $this->graph();
        $replacement = $commands['graph']->booking;
        $forged = new Request(new Identity(
            $original->cancel->identity->client,
            $original->cancel->identity->subject,
            $original->cancel->identity->generation,
            'shipment.full.cancel-replacement'
        ), 'ship-cancel-booking', $replacement->terms, $replacement->identity);
        try {
            $this->provider->plan($forged, Status::Confirmed);
            $this->provider->attempt($forged);
            $this->fail('An original cancellation targeted the replacement booking.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($effects, $this->effects());
        $this->assertSame([], $this->payouts());
    }

    public function testSubstitutedExchangeUnitSkuBookingOrRunIsRefusedBeforeProviderAccess(): void
    {
        $this->delivered();
        $key = $this->terms->subjectKey();
        $commands = $this->planExchange('swap-1', 2);
        $this->exchange('swap-1', 2);
        $this->drain();
        $run = $this->exchangeRun('swap-1');
        $this->inspectOriginal($commands, $run, 2);
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $exchanges = app(ExchangeStore::class);
        $scope = $exchanges->scope($key, 'swap-1', $run->id);
        $rows = (new ScopeRepository())->inspect($scope)['obligations'];
        $row = static fn (string $name): array => array_values(array_filter(
            $rows,
            static fn (array $value): bool => $value['descriptor']['key'] === $name
        ))[0];
        $booking = Request::fromArray($this->terms->physicalDescriptor()['booking']);
        $other = ExchangeStore::replacementCommands($booking, 'swap-1', 'sku-1-size-xl');
        $foreignBooking = Request::fromArray([
            ...$booking->toArray(),
            'identity' => [
                ...$booking->identity->toArray(),
                'generation' => 'foreign-generation',
            ],
        ]);
        $foreign = ExchangeStore::replacementCommands($foreignBooking, 'swap-1', self::REPLACEMENT_SKU);
        $request = static fn (Request $value): Obligation => new Obligation(
            'exchange.request',
            ExchangeStore::REQUEST_HANDLER,
            '1',
            [
                'version' => 1,
                'request' => $value->toArray(),
            ]
        );
        $reserve = static fn (array $value): Obligation => new Obligation(
            'exchange.reserve',
            ExchangeStore::RESERVE_HANDLER,
            '1',
            [
                'forward' => $value['reserve']->toArray(),
                'compensation' => $value['release']->toArray(),
            ]
        );
        $book = static fn (ShipmentDescriptor $graph): Obligation => new Obligation(
            'exchange.book',
            ExchangeStore::BOOK_HANDLER,
            '1',
            $graph->toArray()
        );
        $original = $this->graph();
        $cases = [
            'other unit' => [
                $scope,
                $request(UnitReturnTerms::request($booking, 'exchange.swap-1', [1])),
                'exchange.request',
                $run->id,
            ],
            'other exchange key' => [
                $scope,
                $request(UnitReturnTerms::request($booking, 'exchange.swap-2', [2])),
                'exchange.request',
                $run->id,
            ],
            'other replacement SKU' => [$scope, $reserve($other), 'exchange.reserve', $run->id],
            'other replacement booking' => [$scope, $book($other['graph']), 'exchange.book', $run->id],
            'foreign booking owner' => [$scope, $book($foreign['graph']), 'exchange.book', $run->id],
            'original booking' => [$scope, $book($original), 'exchange.book', $run->id],
            'refund before refusal' => [$scope, new Obligation('exchange.refund', ExchangeStore::REFUND_HANDLER, '1', [
                'version' => 1,
                'refund' => $commands['refund']->toArray(),
            ]), 'exchange.reserve', $run->id],
            'other refund unit' => [$scope, new Obligation('exchange.refund', ExchangeStore::REFUND_HANDLER, '1', [
                'version' => 1,
                'refund' => (new UnitRefundCommand(
                    $commands['refund']->original,
                    'unit-exchange-swap-1',
                    [1]
                ))->toArray(),
            ]), 'exchange.reserve', $run->id],
            'other native run' => [$scope, $reserve($commands), 'exchange.reserve', $this->shipment()->id],
            'other exchange scope' => [
                $exchanges->scope($key, 'swap-unassigned', $run->id),
                $reserve($commands),
                'exchange.reserve',
                $run->id,
            ],
        ];
        $before = $this->requests();
        foreach ($cases as $name => [$candidate, $obligation, $slot, $actor]) {
            try {
                $exchanges->operation(new OperationContext(
                    $candidate,
                    $obligation,
                    $row($slot)['id'],
                    $actor,
                    'untrusted-activity',
                    'untrusted-attempt',
                    false
                ), 'forward');
                $this->fail('A substituted exchange was authorized: ' . $name);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $admissions = [
            'changed SKU' => static fn () => $exchanges->request($key, 'swap-1', 2, 'sku-1-size-xl', 499),
            'changed price' => fn () => $this->exchange('swap-2', 1, 499),
            'original SKU' => static fn () => $exchanges->request($key, 'swap-3', 1, 'sku-1', 500),
            'unit already exchanged' => fn () => $this->exchange('swap-4', 2),
            'reserved return key' => static fn () => app(ReturnStore::class)->request($key, 'exchange.swap-5', [1]),
            'unknown unit' => fn () => $this->exchange('swap-6', 3, 499),
        ];
        foreach ($admissions as $name => $admit) {
            try {
                $admit();
                $this->fail('A substituted exchange admission was accepted: ' . $name);
            } catch (LogicException|\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->requests());
        $this->assertSame(1, DB::table(ExchangeStore::TABLE)->count());
        $this->assertSame([...self::RETURNED, 'reserve', 'ship-book'], $this->effects());
    }
}
