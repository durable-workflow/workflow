<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\ReturnStore;
use LogicException;
use Tests\Support\PartialReturnFixture;
use Tests\TestCase;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/**
 * SHP-04: three delivered units, one returned and accepted. A separate native return refunds exactly that
 * unit's original amount and restocks only it; the fulfilled Order and its delivery evidence stay unchanged.
 * Synthetic recorded-task fixture, not installed-package, real-carrier or complete-matrix qualification.
 *
 * Substitution, repeated returns and cancellation. Split from V2PartialReturnMachineTest so each fits one feature shard.
 */
final class V2PartialReturnCancellationTest extends TestCase
{
    use PartialReturnFixture;

    public function testSubstitutedReturnUnitsBookingCaptureOrRunAreRefusedBeforeProviderAccess(): void
    {
        $this->delivered();
        $key = $this->terms->subjectKey();
        $return = $this->open('rma-1', [2]);
        $run = $this->returnRun('rma-1');
        $returns = app(ReturnStore::class);
        $scope = $returns->scope($key, 'rma-1', $run->id);
        $row = (new ScopeRepository())->inspect($scope)['obligations'][0];
        $booking = Request::fromArray($this->terms->physicalDescriptor()['booking']);
        $foreignBooking = Request::fromArray([
            ...$booking->toArray(),
            'identity' => [
                ...$booking->identity->toArray(),
                'generation' => 'foreign-generation',
            ],
        ]);
        $original = $this->store->authority->unitMoney->original($this->terms);
        $foreignCapture = Request::fromArray([
            ...$original->capture->toArray(),
            'identity' => [
                ...$original->capture->identity->toArray(),
                'operation' => 'payment.other-capture',
            ],
        ]);
        $request = static fn (Request $value): Obligation => new Obligation('return.request', ReturnStore::REQUEST_HANDLER, '1', [
            'version' => 1,
            'request' => $value->toArray(),
        ]);
        $cases = [
            'other units' => [$scope, $request(UnitReturnTerms::request($booking, 'rma-1', [1])), $run->id],
            'other return key' => [$scope, $request(UnitReturnTerms::request($booking, 'rma-2', [2])), $run->id],
            'other booking' => [$scope, $request(UnitReturnTerms::request($foreignBooking, 'rma-1', [2])), $run->id],
            'other capture' => [$scope, new Obligation('return.refund', ReturnStore::REFUND_HANDLER, '1', [
                'version' => 1,
                'refund' => (new UnitRefundCommand(
                    new \DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts(
                        $foreignCapture,
                        [500, 500, 499]
                    ),
                    'unit-return-rma-1',
                    [2]
                ))->toArray(),
            ]), $run->id],
            'other native run' => [$scope, $request($return), $this->shipment()->id],
            'other return scope' => [
                $returns->scope($key, 'rma-unassigned', $run->id),
                $request($return),
                $run->id,
            ],
        ];
        $before = $this->requests();
        foreach ($cases as $name => [$candidate, $obligation, $actor]) {
            try {
                $returns->operation(new OperationContext(
                    $candidate,
                    $obligation,
                    $row['id'],
                    $actor,
                    'untrusted-activity',
                    'untrusted-attempt',
                    false
                ), 'forward');
                $this->fail('A substituted return was authorized: ' . $name);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $returns->request($key, 'rma-1', [1]);
            $this->fail('An existing return changed its units.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('cannot change its units', $error->getMessage());
        }
        $this->assertSame($before, $this->requests());
        $this->assertSame([...self::DELIVERED, 'request-unit-return'], $this->effects());
    }

    public function testLaterReturnsAndFullCancellationNeverRestockOrRefundTheSameUnitTwice(): void
    {
        [$origin] = $this->delivered();
        $key = $this->terms->subjectKey();
        $this->settle('rma-1', [2]);
        $this->settle('rma-2', [0]);
        $this->assertSame([499, 500], $this->payouts());
        try {
            app(ReturnStore::class)->request($key, 'rma-3', [2]);
            $this->fail('An already returned unit was admitted to another return.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('another return', $error->getMessage());
        }
        $effects = $this->effects();
        $this->assertSame(
            [...self::DELIVERED, 'request-unit-return', 'restock-units', 'request-unit-return', 'restock-units'],
            $effects
        );

        // Selected policy: after a unit return, full-parcel cancellation refuses its physical return and stays
        // unsettled. It is never reported cancelled, restocks nothing twice and pays no further refund.
        $this->unitRefunds->plan($this->cancellationRefund(), Status::Confirmed);
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $origin->runId(), 'full-return-after-unit-return');
        $this->drain();
        $this->assertSame($effects, $this->effects());
        $this->assertSame([499, 500], $this->payouts());
        $this->assertSame('fulfilled', $this->subjectState('order'));
        $this->assertNotSame(
            'verified',
            (new ScopeRepository())->metadata($this->store->authority->plan($key)->rootScope())['status']
        );
        $this->assertSame(
            Status::Refused->value,
            $this->provider->lookup(Request::fromArray($this->terms->physicalDescriptor()['return']))
                ->status->value
        );
        $this->assertSame(0, $this->physicalFacts()['restocked_quantity']);
    }

    public function testAuthorizedCaptureCancellationRepaysOnlyOutstandingOriginalUnits(): void
    {
        $origin = $this->startPhysical();
        $key = $this->terms->subjectKey();
        $this->assertSame('booked', $this->subjectState('shipment'), $this->progress());
        $fullRefund = Request::fromArray($this->terms->financialDescriptor()['refund']);
        try {
            $this->provider->attempt($fullRefund);
            $this->fail('A unit-accounted authorized capture accepted a full-amount refund.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('unit refund gateway', $error->getMessage());
        }
        $command = $this->cancellationRefund();
        $prior = new UnitRefundCommand($command->original, 'original-provider-goodwill', [2]);
        $this->unitRefunds->plan($prior, Status::Confirmed);
        $this->assertSame(499, $this->unitRefunds->attempt($prior)['ownPayoutMinor']);
        $this->unitRefunds->plan($command, Status::Confirmed);
        $cancellation = app(CancellationStore::class);
        $cancellation->request($key, $origin->runId(), 'cancel-before-handover');
        $this->drain();
        if ($this->subjectState('order') !== 'cancelled') {
            $cancellation->finish($key, $origin->runId(), 'cancel-before-handover');
            $this->drain();
        }
        $this->assertSame('cancelled', $this->subjectState('order'), $this->progress());
        $this->assertSame(
            ['authorize', 'reserve', 'capture-authorized', 'ship-book', 'ship-cancel-booking', 'release'],
            $this->effects()
        );
        $this->assertSame([499, 1000], $this->payouts());
        $this->assertSame(1000, $this->unitRefunds->lookup($command)['ownPayoutMinor']);
    }

    private function settle(string $returnKey, array $units): void
    {
        $return = $this->open($returnKey, $units);
        $run = $this->returnRun($returnKey);
        $this->warehouse($return, 'received', $units);
        $this->warehouse($return, 'inspected', $units, $units, $units);
        $this->hintReturn($run, $returnKey . '-inspection');
        $this->assertSame('settled', $this->outcome($returnKey)['status'], $this->progress());
    }

    private function cancellationRefund(): UnitRefundCommand
    {
        $original = $this->store->authority->unitMoney->original($this->terms);
        return new UnitRefundCommand($original, 'original-order-payment-cancellation', [0, 1, 2]);
    }
}
