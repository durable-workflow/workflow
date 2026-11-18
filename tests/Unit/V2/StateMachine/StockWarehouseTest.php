<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\WorkflowFiberContext;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';
require_once __DIR__ . '/../../../../examples/provider-state-machines/StockAllocation.php';
require_once __DIR__ . '/../../../../examples/provider-state-machines/StockWarehouse.php';

final class StockWarehouseTest extends TestCase
{
    public static function damagedOrMissing(): array
    {
        return [['damaged'], ['missing']];
    }

    #[DataProvider('damagedOrMissing')]
    public function testClosingTheOriginalReservationDoesNotMakeUnsafeOrAbsentStockSaleable(string $condition): void
    {
        [$db, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $initial = $warehouse->reserve($reserve, $release);
        $this->assertSame(['held', 'held', 'held'], $initial['allocation']['units']);
        $observed = $warehouse->disposition($reserve, 'original-inspection-1', [1], $condition);
        $this->assertSame($observed, $warehouse->disposition($reserve, 'original-inspection-1', [1], $condition));
        $closed = $warehouse->release($release);
        $this->assertSame([0, 2], $closed['releasedUnits']);
        $this->assertSame($closed, $warehouse->release($release));
        $this->assertSame(2, $warehouse->inventory('shop-a', 'sku-a')['available']);
        $this->assertSame(1, $warehouse->inventory('shop-a', 'sku-a')[$condition]);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM allocation_events')->fetchColumn());
        $this->assertTrue($warehouse->inspect($reserve)['closed']);
        $this->assertSame($closed['revision'], $warehouse->inspect($reserve)['revision']);
    }

    public function testARepeatedPartialReservationCannotConsumeMoreStockOrResetItsOriginalDisposition(): void
    {
        [, $warehouse, $reserve, $release] = $this->setupWarehouse(2);
        $initial = $warehouse->reserve($reserve, $release);
        $this->assertSame(['held', 'held', 'unavailable'], $initial['allocation']['units']);
        $this->assertSame($initial, $warehouse->reserve($reserve, $release));
        $this->assertSame(0, $warehouse->inventory('shop-a', 'sku-a')['available']);
        $observed = $warehouse->disposition($reserve, 'inspection', [0], 'damaged');
        $this->assertSame($observed, $warehouse->reserve($reserve, $release));
        $this->assertSame([1], $warehouse->release($release)['releasedUnits']);
        $this->assertSame(1, $warehouse->inventory('shop-a', 'sku-a')['available']);
        $warehouse->seed('shop-a', 'sku-a', 2);
        $this->assertSame(1, $warehouse->inventory('shop-a', 'sku-a')['available']);
    }

    public function testAGoodsReceiptAllocatesOnlyTheOriginalUnavailableUnitsOnce(): void
    {
        [, $warehouse, $reserve, $release] = $this->setupWarehouse(1);
        $this->assertNull($warehouse->find($reserve));
        $warehouse->reserve($reserve, $release);
        $warehouse->disposition($reserve, 'inspection', [0], 'damaged');
        $this->assertSame(
            ['damaged', 'unavailable', 'unavailable'],
            $warehouse->allocate($reserve)['allocation']['units']
        );
        $receipt = $warehouse->receive('shop-a', 'sku-a', 'delivery-note-1', 1);
        $this->assertSame($receipt, $warehouse->receive('shop-a', 'sku-a', 'delivery-note-1', 1));
        $allocated = $warehouse->allocate($reserve);
        $this->assertSame(['damaged', 'held', 'unavailable'], $allocated['allocation']['units']);
        $this->assertSame($allocated, $warehouse->allocate($reserve));
        $this->assertSame(0, $warehouse->inventory('shop-a', 'sku-a')['available']);
        try {
            $warehouse->receive('shop-a', 'sku-a', 'delivery-note-1', 2);
            $this->fail('A reused delivery note changed its original quantity.');
        } catch (LogicException) {
        }
        $this->assertSame([1], $warehouse->release($release)['releasedUnits']);
        $this->assertSame([
            'initial_quantity' => 2,
            'available' => 1,
            'damaged' => 1,
            'missing' => 0,
        ], $warehouse->inventory('shop-a', 'sku-a'));
        $this->assertSame($warehouse->inspect($reserve), $warehouse->allocate($reserve));
    }

    public function testAReusedObservationCannotMoveToAnotherOriginalUnitOrReservation(): void
    {
        [, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $warehouse->reserve($reserve, $release);
        $warehouse->disposition($reserve, 'one-inspection', [0], 'damaged');
        $this->expectException(LogicException::class);
        $warehouse->disposition($reserve, 'one-inspection', [1], 'damaged');
    }

    public function testACompensationFromAnotherGenerationCannotReleaseTheOriginalStock(): void
    {
        [, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $warehouse->reserve($reserve, $release);
        $foreign = new Request(new Identity(
            'shop-a',
            'purchase-a',
            'other-generation',
            'release'
        ), 'release', $release->terms, new Identity('shop-a', 'purchase-a', 'other-generation', 'reserve'));
        $this->expectException(LogicException::class);
        $warehouse->release($foreign);
    }

    public function testAForeignCompensatorCannotReplaceThePinnedOriginalOperation(): void
    {
        [, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $warehouse->reserve($reserve, $release);
        $other = new Request(new Identity(
            'shop-a',
            'purchase-a',
            'generation-a',
            'replacement-release'
        ), 'release', $release->terms, $reserve->identity);
        $this->expectException(LogicException::class);
        $warehouse->reserve($reserve, $other);
    }

    public function testAnObservationCannotMutateAReleasedReservationOrAnotherOwnersGoods(): void
    {
        [, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $warehouse->reserve($reserve, $release);
        $warehouse->release($release);
        $this->expectException(LogicException::class);
        $warehouse->disposition($reserve, 'late-inspection', [0], 'missing');
    }

    public function testReceiptFailureRollsBackThePhysicalCountersAndClosureTogether(): void
    {
        [$db, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $warehouse->reserve($reserve, $release);
        $warehouse->disposition($reserve, 'inspection', [1], 'damaged');
        $before = $warehouse->inspect($reserve);
        $db->exec("CREATE TRIGGER refuse_closure BEFORE UPDATE ON allocation_reservations
            WHEN NEW.closed = 1 BEGIN SELECT RAISE(ABORT, 'injected_closure_failure'); END");
        try {
            $warehouse->release($release);
            $this->fail('The injected receipt failure was ignored.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('injected_closure_failure', $error->getMessage());
        }
        $this->assertSame($before, $warehouse->inspect($reserve));
        $this->assertSame(0, $warehouse->inventory('shop-a', 'sku-a')['available']);
        $this->assertSame(1, $warehouse->inventory('shop-a', 'sku-a')['damaged']);
        $db->exec('DROP TRIGGER refuse_closure');
        $warehouse->release($release);
        $this->assertSame(2, $warehouse->inventory('shop-a', 'sku-a')['available']);
    }

    public function testMalformedPhysicalCountersCannotBeTreatedAsUnavailableOrReleasedStock(): void
    {
        [$db, $warehouse, $reserve, $release] = $this->setupWarehouse(3);
        $warehouse->reserve($reserve, $release);
        $db->exec('UPDATE allocation_stock SET available = -1');
        $this->expectException(LogicException::class);
        $warehouse->release($release);
    }

    public function testFreshProcessReplaysTheOriginalCommittedReleaseWithoutReturningStockTwice(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'warehouse-recovery-');
        chmod($path, 0600);
        $db = new PDO('sqlite:' . $path);
        $warehouse = new StockWarehouse($db);
        try {
            $warehouse->install();
            $warehouse->seed('shop-a', 'sku-a', 3);
            $reserve = new Request(new Identity('shop-a', 'purchase-a', 'generation-a', 'reserve'), 'reserve', [
                'sku' => 'sku-a',
                'quantity' => 3,
            ]);
            $release = new Request(new Identity(
                'shop-a',
                'purchase-a',
                'generation-a',
                'release'
            ), 'release', $reserve->terms, $reserve->identity);
            $warehouse->reserve($reserve, $release);
            $warehouse->disposition($reserve, 'original-damage', [1], 'damaged');
            $committed = $warehouse->release($release);
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'tests/Support/StockWarehouseRecoveryProcess.php', $path,
            ], dirname(__DIR__, 4), timeout: 30);
            $process->mustRun();
            $recovered = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $recovered['pid']);
            $this->assertSame($committed, $recovered['result']);
            $this->assertSame(2, $recovered['stock']['available']);
            $this->assertSame(1, $recovered['stock']['damaged']);
            $this->assertSame($recovered['stock'], $warehouse->inventory('shop-a', 'sku-a'));
        } finally {
            $warehouse = null;
            $db = null;
            unlink($path);
        }
    }

    public function testReadAndWriteBoundariesRefuseReplayAndCallerTransactionsBeforeCreatingTables(): void
    {
        $db = new PDO('sqlite::memory:');
        $warehouse = new StockWarehouse($db);
        $fiber = new \Fiber(static function () use ($warehouse): void {
            WorkflowFiberContext::enter();
            try {
                foreach (['read', 'write'] as $operation) {
                    try {
                        $operation === 'read' ? $warehouse->inventory('shop-a', 'sku-a') : $warehouse->install();
                        self::fail('A replay frame entered warehouse storage.');
                    } catch (LogicException $error) {
                        self::assertStringContainsString('outside replay', $error->getMessage());
                    }
                }
            } finally {
                WorkflowFiberContext::leave();
            }
        });
        $fiber->start();
        $db->beginTransaction();
        try {
            $warehouse->install();
            $this->fail('A caller-owned transaction entered warehouse storage.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('owned host transaction', $error->getMessage());
        } finally {
            $db->rollBack();
        }
        $this->assertSame(
            0,
            (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")
                ->fetchColumn()
        );
    }

    private function setupWarehouse(int $quantity): array
    {
        $db = new PDO('sqlite::memory:');
        $warehouse = new StockWarehouse($db);
        $warehouse->install();
        $warehouse->seed('shop-a', 'sku-a', $quantity);
        $reserve = new Request(new Identity('shop-a', 'purchase-a', 'generation-a', 'reserve'), 'reserve', [
            'sku' => 'sku-a',
            'quantity' => 3,
        ]);
        $release = new Request(new Identity(
            'shop-a',
            'purchase-a',
            'generation-a',
            'release'
        ), 'release', $reserve->terms, $reserve->identity);
        return [$db, $warehouse, $reserve, $release];
    }
}
