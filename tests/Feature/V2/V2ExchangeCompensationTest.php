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
use DurableWorkflow\Examples\ProviderStateMachines\ExchangeStore;
use DurableWorkflow\Examples\ProviderStateMachines\ReturnStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ExchangeFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-05: one delivered unit exchanged for another size at the same price. A separate native exchange
 * receives and inspects the original unit, reserves the replacement, then books and tracks it under its own
 * identities. Synthetic recorded-task fixture, not installed-package, real-carrier or complete-matrix qualification.
 *
 * Compensation and substitution refusals. Split from V2ExchangeMachineTest so each fits one feature shard.
 */
final class V2ExchangeCompensationTest extends TestCase
{
    use ExchangeFixture;

    private const RETURNED = [...self::DELIVERED, 'request-unit-return', 'restock-units'];

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
