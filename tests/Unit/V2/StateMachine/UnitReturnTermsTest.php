<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\OriginalUnitAmounts;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitCaptureOperation;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitReturnTerms;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class UnitReturnTermsTest extends TestCase
{
    public static function invalidUnits(): array
    {
        return [
            'empty' => [[]],
            'unsorted' => [[2, 0]],
            'repeated' => [[1, 1]],
            'outside the delivered quantity' => [[3]],
            'negative' => [[-1]],
            'coerced' => [['2']],
        ];
    }

    #[DataProvider('invalidUnits')]
    public function testReturnNamesExplicitAscendingDeliveredUnitOrdinals(array $units): void
    {
        $this->expectException(InvalidArgumentException::class);
        UnitReturnTerms::request($this->booking(), 'rma-1', $units);
    }

    public function testReturnAndRestockIdentitiesDeriveFromTheBookingAndReturnKey(): void
    {
        $booking = $this->booking();
        $return = UnitReturnTerms::request($booking, 'rma-1', [0, 2]);
        $restock = UnitReturnTerms::restock($return, [2]);
        $this->assertTrue($return->identity->sameOwner($booking->identity));
        $this->assertSame($booking->identity->toArray(), $return->target?->toArray());
        $this->assertSame($return->identity->toArray(), $restock->target?->toArray());
        $this->assertSame('shipment.full.return-units.rma-1', $return->identity->operation);
        $this->assertNotSame(
            $return->identity->key(),
            UnitReturnTerms::request($booking, 'rma-2', [0, 2])->identity->key()
        );
        $this->assertSame(
            $return->fingerprint(),
            Request::fromArray($return->toArray())->fingerprint(),
            'The portable descriptor round-trips without reinterpretation.'
        );
        $this->expectException(InvalidArgumentException::class);
        UnitReturnTerms::restock($return, [1]);
    }

    public function testUnitCaptureRetainsItsOriginalAuthorizedCaptureAndEveryUnit(): void
    {
        $terms = new OrderTerms(
            'shop-a',
            'purchase-1',
            'g1',
            1499,
            'USD',
            'sku-1',
            3,
            new AuthorizationTerms(1499, 'USD', 1_900_000_000),
            1_900_000_100
        );
        $financial = $terms->financialDescriptor();
        $original = new OriginalUnitAmounts(Request::fromArray($financial['capture']), [500, 500, 499]);
        $valid = [
            'version' => 1,
            'financial' => $financial,
            'refund' => (new UnitRefundCommand($original, 'original-order-payment-cancellation', [0, 1, 2]))->toArray(),
        ];
        $this->assertSame($original->fingerprint(), UnitCaptureOperation::decode($valid)[1]->original->fingerprint());
        $partial = [
            ...$valid,
            'refund' => (new UnitRefundCommand($original, 'original-order-payment-cancellation', [2]))->toArray(),
        ];
        $foreign = new OriginalUnitAmounts(Request::fromArray([
            ...$financial['capture'],
            'identity' => [
                ...$financial['capture']['identity'],
                'generation' => 'g2',
            ],
            'target' => [
                ...$financial['capture']['target'],
                'generation' => 'g2',
            ],
        ]), [500, 500, 499]);
        $substituted = [
            ...$valid,
            'refund' => (new UnitRefundCommand($foreign, 'original-order-payment-cancellation', [0, 1, 2]))->toArray(),
        ];
        foreach ([$partial, $substituted] as $input) {
            try {
                UnitCaptureOperation::decode($input);
                $this->fail('A partial or substituted unit capture descriptor was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function booking(): Request
    {
        return new Request(new Identity('shop-a', 'purchase-1', 'g1', 'shipment.full.book'), 'ship-book', [
            'sku' => 'sku-1',
            'quantity' => 3,
            'carrier' => 'synthetic-carrier',
        ]);
    }
}
