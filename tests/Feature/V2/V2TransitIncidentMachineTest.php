<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\IncidentStore;
use DurableWorkflow\Examples\ProviderStateMachines\TransitPolicy;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Support\TransitIncidentFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-07/SHP-08: the carrier confirms loss or transport damage of the original parcel before recipient
 * handover. A separate native incident settles the trusted remedy, files the carrier claim and recovers
 * damaged goods. Synthetic recorded-task fixture, not installed-package, real-carrier or warehouse qualification.
 */
final class V2TransitIncidentMachineTest extends TestCase
{
    use TransitIncidentFixture;

    public function testLostParcelIsRefundedOnceWithoutRestockWhileTheClaimIsSeparate(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('lost', claim: Status::Refused);
        $deadline = $this->terms->fulfillmentDeadline;

        // A tracking delay alone is not a confirmed loss: no incident, effect or remedy.
        $this->hint($shipment, 'tracking-delay');
        $this->current();
        $this->assertNull($this->incidentRow());
        $this->assertSame(self::DELIVERED, $this->effects());
        $this->assertSame('ready', $this->subjectState('order'));

        $this->record('lost');
        $this->hint($shipment, 'original-loss');
        $this->current();
        $this->assertLessThan($deadline, Carbon::now()->getTimestamp(), 'The remedy never waits for the deadline.');
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame('in-transit', $this->subjectState('shipment'));
        $this->assertSame('completed', $shipment->fresh()->status->value, $this->progress());
        $this->assertSame('lost', $shipment->fresh()->workflowOutput()['businessOutcome']);
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );
        // Lost goods are never restocked or returned; the refund repays every original unit exactly once.
        $this->assertSame(self::DELIVERED, $this->effects());
        $this->assertSame([1499], $this->payouts());
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertTrue($this->physicalFacts()['carrier_loss_confirmed']);
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame([0, 1, 2], $outcome['refundedUnits']);
        $this->assertSame(1499, $outcome['refundedAmountMinor']);
        // The carrier refused the claim; the customer was refunded anyway.
        $this->assertSame('refused', $outcome['claimStatus']);
        $this->assertSame($commands['claim']->identity->key(), $outcome['claim']);
        $this->assertSame([], $outcome['restockedUnits']);
        $this->assertSame($outcome, WorkflowStub::loadRun($this->incidentRun()->id, Catalogue::NAMESPACE)->output());

        // A later business cancellation is refused explicitly: it neither repays nor restocks the lost units.
        $history = $this->history($origin->runId());
        $this->assertCancellationRefused('late-cancellation', 'settled');
        $this->drain();
        $this->assertSame([1499], $this->payouts());
        $this->assertNotContains('restock', $this->effects());
        $this->assertNotContains('request-return', $this->effects());
        $this->assertSame('remedied', $this->subjectState('order'));
        $this->assertSame($history, $this->history($origin->runId()));

        $requests = $this->requests();
        foreach ([$this->incidentRun()->id, $origin->runId(), $shipment->id] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($requests, $this->requests());
    }

    public function testLostParcelReplacementIsReservedBookedAndDeliveredUnderItsOwnIdentities(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $this->assertSame('ready', $this->subjectState('order'), 'A booked replacement is not a settled remedy.');
        $this->replacementCarrier($commands, 'handed-over');
        $this->hintIncident('replacement-handover');
        $this->assertSame('ready', $this->subjectState('order'));
        $this->replacementCarrier($commands, 'delivered');
        $this->hintIncident('replacement-delivery');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame([], $this->payouts(), 'A delivered replacement moves no money.');
        $outcome = $this->incidentOutcome();
        $this->assertSame('replaced', $outcome['resolution']);
        $this->assertSame('confirmed', $outcome['claimStatus']);
        $identities = [$outcome['originalBooking'], $outcome['replacementReservation'], $outcome['replacementBooking'],
            $outcome['claim']];
        $this->assertSame([
            $this->graph()
                ->booking->identity->key(),
            $commands['reserve']->identity->key(),
            $commands['graph']->booking->identity->key(),
            $commands['claim']->identity->key(),
        ], $identities);
        $this->assertCount(4, array_unique($identities));
        // The carrier decides the claim later; the settled customer outcome does not change.
        $this->provider->shipments()
            ->recordClaimOutcome($commands['claim'], 'accepted', 1499);
        $claim = app(IncidentStore::class)->claim($this->terms->subjectKey());
        $this->assertSame('accepted', $claim['carrierDecision']['outcome']);
        $this->assertSame($outcome, $this->incidentOutcome());
        $this->assertFalse($this->physicalFacts()['original_recipient_delivered']);
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );
    }

    public function testUnavailableReplacementFallsBackToRefundAndSubstitutionIsRefusedBeforeProviderIo(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $key = $this->terms->subjectKey();
        $commands = $this->planIncident('lost', reserve: Status::Refused);
        $this->record('lost');
        $this->hint($shipment, 'original-loss');
        $this->current();

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([...self::DELIVERED, 'carrier-claim'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded-replacement-unavailable', $outcome['resolution']);
        $this->assertSame($commands['reserve']->identity->key(), $outcome['replacementReservation']);
        $this->assertNull($outcome['replacementBooking']);

        $incidents = app(IncidentStore::class);
        $run = $this->incidentRun();
        $scope = $incidents->scope($key, $run->id);
        $rows = (new ScopeRepository())->inspect($scope)['obligations'];
        $operation = static fn (string $name): string => array_values(array_filter(
            $rows,
            static fn (array $value): bool => $value['descriptor']['key'] === $name
        ))[0]['id'];
        $original = $this->store->authority->unitMoney->original($this->terms);
        $damaged = $incidents->commands($key, 'damaged');
        $cases = [
            'booking after refused reservation' => [$scope, new Obligation(
                'incident.book',
                IncidentStore::BOOK_HANDLER,
                '1',
                $commands['graph']->toArray()
            ), 'incident.reserve', $run->id],
            'original booking as replacement' => [$scope, new Obligation(
                'incident.book',
                IncidentStore::BOOK_HANDLER,
                '1',
                $this->graph()
                    ->toArray()
            ), 'incident.reserve', $run->id],
            'other refunded units' => [$scope, new Obligation('incident.refund', IncidentStore::REFUND_HANDLER, '1', [
                'version' => 1,
                'refund' => (new UnitRefundCommand($original, 'transit-lost', [0]))->toArray(),
            ]), 'incident.refund', $run->id],
            'claim for another incident' => [$scope, new Obligation('incident.claim', IncidentStore::CLAIM_HANDLER, '1', [
                'version' => 1,
                'request' => $damaged['claim']->toArray(),
            ]), 'incident.claim', $run->id],
            'recovery of lost goods' => [$scope, new Obligation('incident.recover', IncidentStore::RECOVER_HANDLER, '1', [
                'version' => 1,
                'request' => $damaged['recover']->toArray(),
            ]), 'incident.claim', $run->id],
            'other native run' => [$scope, new Obligation('incident.refund', IncidentStore::REFUND_HANDLER, '1', [
                'version' => 1,
                'refund' => $commands['refund']->toArray(),
            ]), 'incident.refund', $shipment->id],
            'foreign scope' => [$incidents->scope($key, $shipment->id), new Obligation(
                'incident.refund',
                IncidentStore::REFUND_HANDLER,
                '1',
                [
                    'version' => 1,
                    'refund' => $commands['refund']->toArray(),
                ]
            ), 'incident.refund', $run->id],
        ];
        $before = $this->requests();
        foreach ($cases as $name => [$candidate, $obligation, $slot, $actor]) {
            try {
                $incidents->operation(new OperationContext(
                    $candidate,
                    $obligation,
                    $operation($slot),
                    $actor,
                    'untrusted-activity',
                    'untrusted-attempt',
                    false
                ), 'forward');
                $this->fail('A substituted incident operation was authorized: ' . $name);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $refusals = [
            'Order actor admits an incident' => static fn () => $incidents->admit(
                WorkflowRun::query()->findOrFail($origin->runId())
            ),
            'Shipment actor reads the Order remedy' => static fn () => $incidents->remedy($shipment),
            'substituted remedy policy' => fn () => (new Authority(
                $this->store->authority->db,
                'provider-worker',
                [$key],
                [
                    $key => FinancialCatalogue::AFTER_STOCK,
                ],
                unitAmounts: [
                    $key => $this->unitAmounts(),
                ],
                transitPolicies: [
                    $key => TransitPolicy::REFUND,
                ]
            ))->subject($key, 'order'),
        ];
        foreach ($refusals as $name => $refused) {
            try {
                $refused();
                $this->fail('A substituted incident boundary was accepted: ' . $name);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->requests());
        $this->assertSame([1499], $this->payouts());
    }

    public function testDamagedParcelIsRefundedBeforeItsRecoveredGoodsAreInspectedAndOnlySaleableUnitsRestocked(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('damaged', saleable: [0]);
        $this->record('damaged');
        $this->hint($shipment, 'original-damage');
        $this->current();

        // The remedy settled while the damaged goods are still with the carrier.
        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([1499], $this->payouts());
        $recovered = [...self::DELIVERED, 'carrier-claim', 'recover-damaged-units'];
        $this->assertSame($recovered, $this->effects());
        $this->assertNull($this->incidentRow()->outcome);
        $this->warehouse($commands['recover'], 'received', [0, 1, 2]);
        $this->hintIncident('recovery-receipt');
        $this->assertSame($recovered, $this->effects(), 'Receipt alone does not make goods saleable.');
        $this->warehouse($commands['recover'], 'inspected', [0, 1, 2], [0, 1], [0]);
        $this->hintIncident('recovery-inspection');

        $this->assertSame([...$recovered, 'restock-units'], $this->effects(), $this->progress());
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('damaged', $outcome['incident']);
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame([0, 1, 2], $outcome['receivedUnits']);
        $this->assertSame([0, 1], $outcome['acceptedUnits']);
        $this->assertSame([0], $outcome['saleableUnits']);
        $this->assertSame([0], $outcome['restockedUnits']);
        $this->assertSame($commands['recover']->identity->key(), $outcome['recovery']);
        $this->assertTrue($this->physicalFacts()['transport_damage_confirmed']);
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertTrue($this->incidentRun()->status->isTerminal());
        // A later cancellation is refused, so the full-parcel return cannot restock the recovered units again.
        $this->assertCancellationRefused('late-cancellation', 'settled');
        $this->drain();
        $this->assertSame([...$recovered, 'restock-units'], $this->effects());
        $this->assertSame([1499], $this->payouts());
    }

    public function testCancellationDuringAPendingReplacementIsRefusedAndTheReplacementStillSettles(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($shipment, 'original-loss');
        $this->current();
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book'], $this->effects(), $this->progress());
        $shipmentHistory = $this->history($shipment->id);

        // The booked replacement is the pending remedy: the Order's cancellation route stays closed.
        $this->assertCancellationRefused('cancel-pending-replacement', 'pending');
        $this->drain();
        $this->assertSame('ready', $this->subjectState('order'), $this->progress());
        $this->assertFalse(WorkflowRun::query()->findOrFail($origin->runId())->status->isTerminal());
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'delivered');
        $this->hintIncident('replacement-delivery');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );
        $this->assertSame('replaced', $this->incidentOutcome()['resolution']);
        $this->assertSame([], $this->payouts(), 'The delivered replacement is the only remedy.');
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame($shipmentHistory, $this->history($shipment->id));
        $this->assertCancellationRefused('cancel-after-replacement', 'settled');
        $this->assertReplayQuiet($origin->runId(), $shipment->id);
    }

    public function testCancellationDuringAProcessingRefundIsRefusedAndTheRefundPaysOnce(): void
    {
        [$origin, $shipment] = $this->handedOver();
        $commands = $this->planIncident('lost', refund: Status::Processing);
        $this->record('lost');
        $this->hint($shipment, 'original-loss');
        $this->current();
        // The refund is processing: the incident keeps waiting for its outcome and files no claim yet.
        $this->assertSame(self::DELIVERED, $this->effects(), $this->progress());
        $this->assertSame([], $this->payouts());
        $this->assertNull($this->incidentRow()->remedy);
        $this->assertSame('ready', $this->subjectState('order'));
        $shipmentHistory = $this->history($shipment->id);

        $this->assertCancellationRefused('cancel-processing-refund', 'pending');
        $this->drain();
        $this->assertSame([], $this->payouts());
        $this->unitRefunds->resolve($commands['refund'], Status::Confirmed);
        $this->hintIncident('refund-confirmed');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded', $outcome['resolution']);
        $this->assertSame(1499, $outcome['refundPayoutMinor']);
        $this->assertSame([...self::DELIVERED, 'carrier-claim'], $this->effects());
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
        $this->assertSame($shipmentHistory, $this->history($shipment->id));
        $this->assertCancellationRefused('cancel-after-refund', 'settled');
        $this->assertSame([1499], $this->payouts());
        $this->assertReplayQuiet($origin->runId(), $shipment->id);
    }

    public function testRefusedReplacementBookingReleasesTheReservationAndRefundsInstead(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost', book: Status::Refused);
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        // The unused reservation is released before the refund; no replacement parcel exists.
        $this->assertSame([...self::DELIVERED, 'reserve', 'release', 'carrier-claim'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('settled', $outcome['status']);
        $this->assertSame('refunded-replacement-failed', $outcome['resolution']);
        $this->assertSame('booking-refused', $outcome['replacementFailure']);
        $this->assertSame($commands['reserve']->identity->key(), $outcome['replacementReservation']);
        $this->assertSame($commands['graph']->booking->identity->key(), $outcome['replacementBooking']);
        $this->assertSame(
            'remedied',
            WorkflowRun::query()->findOrFail($origin->runId())->workflowOutput()['businessOutcome']
        );
        $this->assertReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testFailedReplacementDeliveryRefundsWithoutReleasingTheShippedReplacementStock(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();
        $this->replacementCarrier($commands, 'handed-over');
        $this->replacementCarrier($commands, 'lost');
        $this->hintIncident('replacement-loss');

        $this->assertSame('remedied', $this->subjectState('order'), $this->progress());
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $this->assertSame([1499], $this->payouts());
        $outcome = $this->incidentOutcome();
        $this->assertSame('refunded-replacement-failed', $outcome['resolution']);
        $this->assertSame('lost', $outcome['replacementFailure']);
        $this->assertReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testUnresolvedReplacementAndRefusedRefundEndAsExplicitlyOwedWithoutFailingTheOrder(): void
    {
        [$origin] = $this->handedOver();
        $commands = $this->planIncident('lost');
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();
        $this->replacementCarrier($commands, 'handed-over');
        // Carrier progress without delivery or a confirmed failure, past the bounded recorded wait.
        for ($wake = 0; $wake < 32; ++$wake) {
            $this->hintIncident('replacement-progress-' . $wake);
        }

        $outcome = $this->incidentOutcome();
        $this->assertSame('owed', $outcome['status']);
        $this->assertSame('replacement-unresolved', $outcome['resolution']);
        $this->assertSame([], $this->payouts(), 'A replacement that may still arrive is not refunded automatically.');
        $this->assertTrue($this->incidentRun()->status->isTerminal());
        $this->assertSame([...self::DELIVERED, 'reserve', 'ship-book', 'carrier-claim'], $this->effects());
        $order = WorkflowRun::query()->findOrFail($origin->runId());
        $this->assertSame('completed', $order->status->value, $this->progress());
        $this->assertSame('unsettled', $order->workflowOutput()['businessOutcome']);
        $this->assertSame('pending', $order->workflowOutput()['transition']['status']);
        $this->assertSame('ready', $this->subjectState('order'));
        $this->assertCancellationRefused('cancel-owed-remedy', 'owed');
        $this->assertReplayQuiet($origin->runId(), $this->shipment()->id);
    }

    public function testRefusedRefundEndsAsExplicitlyOwed(): void
    {
        [$origin] = $this->handedOver();
        $this->planIncident('lost', refund: Status::Refused);
        $this->record('lost');
        $this->hint($this->shipment(), 'original-loss');
        $this->current();

        $outcome = $this->incidentOutcome();
        $this->assertSame('owed', $outcome['status']);
        $this->assertSame('refund-refused', $outcome['resolution']);
        $this->assertSame([], $this->payouts());
        $order = WorkflowRun::query()->findOrFail($origin->runId());
        $this->assertSame('completed', $order->status->value, $this->progress());
        $this->assertSame('unsettled', $order->workflowOutput()['businessOutcome']);
        $this->assertSame('ready', $this->subjectState('order'));
    }

    private function assertReplayQuiet(string ...$runs): void
    {
        $requests = $this->requests();
        foreach ([$this->incidentRun()->id, ...$runs] as $id) {
            app(WorkflowReplayer::class)->replay(WorkflowRun::query()->findOrFail($id));
        }
        $this->assertSame($requests, $this->requests());
    }
}
