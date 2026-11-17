<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\StockAllocation;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';
require_once __DIR__ . '/../../../../examples/provider-state-machines/StockAllocation.php';

final class StockAllocationTest extends TestCase
{
    public static function partialPolicies(): array
    {
        return [
            [StockAllocation::PARTIAL, 'fulfill', [0, 1], [2], []],
            [StockAllocation::WAIT, 'wait', [], [], []],
            [StockAllocation::CANCEL, 'cancel', [], [0, 1, 2], [0, 1]],
        ];
    }

    #[DataProvider('partialPolicies')]
    public function testThreeRequestedUnitsAndTwoHeldHaveThreeDistinctPolicies(
        string $policy,
        string $action,
        array $fulfill,
        array $refund,
        array $release,
    ): void {
        $original = $this->reservation();
        $allocation = new StockAllocation($original, ['held', 'held', 'unavailable']);
        $decision = $allocation->decide($original, $policy);
        $this->assertSame('planned', $decision['status']);
        $this->assertSame(3, $decision['requestedQuantity']);
        $this->assertSame($action, $decision['action']);
        $this->assertSame($fulfill, $decision['fulfillUnits']);
        $this->assertSame($refund, $decision['requiredRefundUnits']);
        $this->assertSame($release, $decision['releaseUnits']);
        $this->assertSame([0, 1], $decision['heldUnits']);
        $this->assertSame([2], $decision['unavailableUnits']);
    }

    public static function unsafeStock(): array
    {
        return [
            ['damaged', StockAllocation::PARTIAL], ['damaged', StockAllocation::WAIT], [
                'damaged',
                StockAllocation::CANCEL,
            ],
            ['missing', StockAllocation::PARTIAL], ['missing', StockAllocation::WAIT], [
                'missing',
                StockAllocation::CANCEL,
            ],
        ];
    }

    #[DataProvider('unsafeStock')]
    public function testDamagedOrMissingReservedGoodsStopFulfillmentWithoutBecomingSaleable(
        string $state,
        string $policy
    ): void {
        $original = $this->reservation();
        $allocation = new StockAllocation($original, ['held', $state, 'held']);
        $decision = $allocation->decide($original, $policy);
        $this->assertSame('cancel', $decision['action']);
        $this->assertSame([], $decision['fulfillUnits']);
        $this->assertSame([0, 2], $decision['releaseUnits']);
        $this->assertSame([0, 1, 2], $decision['requiredRefundUnits']);
        $this->assertSame([1], $decision[$state === 'damaged' ? 'damagedUnits' : 'missingUnits']);
    }

    public function testUnitOrdinalsAndOriginalReservationSurviveCanonicalSerialization(): void
    {
        $allocation = new StockAllocation($this->reservation(), ['unavailable', 'held', 'held']);
        $restored = StockAllocation::fromArray(
            json_decode(PortableDescriptor::encode($allocation->toArray()), true, flags: JSON_THROW_ON_ERROR)
        );
        $this->assertSame($allocation->fingerprint(), $restored->fingerprint());
        $decision = $restored->decide($this->reservation(), StockAllocation::PARTIAL);
        $this->assertSame([1, 2], $decision['fulfillUnits']);
        $this->assertSame([0], $decision['requiredRefundUnits']);
    }

    public function testAnotherOriginalReservationCannotAdoptTheAllocation(): void
    {
        $allocation = new StockAllocation($this->reservation(), ['held', 'held', 'unavailable']);
        $foreign = new Request(new Identity('shop-a', 'purchase-a', 'generation-b', 'reserve'), 'reserve', [
            'sku' => 'sku-a',
            'quantity' => 3,
        ]);
        $this->expectException(LogicException::class);
        $allocation->decide($foreign, StockAllocation::PARTIAL);
    }

    public static function invalidUnits(): array
    {
        return [[[]], [['held']], [['held', 'held', 'held', 'held']], [['held', 'unknown', 'held']], [[
            'held', true, 'held'],
        ], [[
            1 => 'held',
            2 => 'held',
            3 => 'held',
        ]]];
    }

    #[DataProvider('invalidUnits')]
    public function testMissingAmbiguousOrReindexedUnitsAreRefused(array $units): void
    {
        $original = $this->reservation();
        $this->expectException(InvalidArgumentException::class);
        new StockAllocation($original, $units);
    }

    public function testNoPolicyIsInventedForPartialAvailability(): void
    {
        $original = $this->reservation();
        $allocation = new StockAllocation($original, ['held', 'held', 'unavailable']);
        $this->expectException(InvalidArgumentException::class);
        $allocation->decide($original, 'automatic');
    }

    public static function malformedRecords(): array
    {
        return [['version'], ['extra'], ['missing'], ['reservation'], ['units']];
    }

    #[DataProvider('malformedRecords')]
    public function testUnselectedOrMalformedStoredShapesAreRefused(string $change): void
    {
        $record = (new StockAllocation($this->reservation(), ['held', 'held', 'unavailable']))->toArray();
        if ($change === 'version') {
            $record['version'] = true;
        } elseif ($change === 'extra') {
            $record['confirmed'] = true;
        } elseif ($change === 'missing') {
            unset($record['reservation']);
        } else {
            $record[$change] = 'untyped';
        }
        $this->expectException(InvalidArgumentException::class);
        StockAllocation::fromArray($record);
    }

    public static function unsupportedQuantities(): array
    {
        return [[0], [-1], [1001]];
    }

    #[DataProvider('unsupportedQuantities')]
    public function testTheDetailedUnitBoundNeverTruncatesTheOriginalQuantity(int $quantity): void
    {
        $original = new Request(new Identity('shop-a', 'purchase-a', 'generation-a', 'reserve'), 'reserve', [
            'sku' => 'sku-a',
            'quantity' => $quantity,
        ]);
        $units = array_fill(0, max(0, $quantity), 'held');
        $this->expectException(InvalidArgumentException::class);
        new StockAllocation($original, $units);
    }

    private function reservation(): Request
    {
        return new Request(new Identity('shop-a', 'purchase-a', 'generation-a', 'reserve'), 'reserve', [
            'sku' => 'sku-a',
            'quantity' => 3,
        ]);
    }
}
