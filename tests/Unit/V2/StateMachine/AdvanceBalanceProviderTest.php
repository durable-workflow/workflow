<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodRefundTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentReceipt;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingRefundTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvanceBalanceFixture;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Provider composition only; no native split-payment business admission is claimed. */
final class AdvanceBalanceProviderTest extends TestCase
{
    use AdvanceBalanceFixture;

    private PDO $db;

    private AdvanceBalanceProvider $provider;

    private AdvanceBalanceTerms $parts;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->provider = new AdvanceBalanceProvider($this->db, 'shop-a', static fn (): int => 1000);
        $this->provider->install();
        $this->parts = $this->originalParts();
        $this->provider->incoming->receipts->register($this->parts->advance, $this->parts->advanceAdmissionContract);
        $this->provider->cod->collections->register($this->parts->balance, $this->parts->balanceAdmissionContract);
    }

    public function testBankAdvanceDoesNotBypassTheOriginalCarrierBalanceHandoverGuard(): void
    {
        $this->assertSame($this->provider->commands, $this->provider->incoming->commands);
        $this->assertSame($this->provider->commands, $this->provider->cod->commands);
        $this->receiveAdvance();
        $this->prepareCarrier();
        $graph = $this->parts->balance->shipment;
        try {
            $this->provider->incoming->commands->shipments()
                ->record($graph, 'original-delivery', 'delivered', 2);
            $this->fail('The incoming-money interface must preserve the original carrier handover guard.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('complete on-time payer collection', $error->getMessage());
        }
        $this->assertTrue(
            $this->provider->incoming->receipts->inspect($this->parts->advance)['requiredAmountReceivedBeforeDeadline']
        );
        $this->assertFalse($this->provider->cod->inspect($this->parts->balance)['facts']['payer_collection_confirmed']);
        $this->collectBalance();
        $this->provider->incoming->commands->shipments()
            ->record($graph, 'original-delivery', 'delivered', 2);
        $facts = $this->provider->cod->inspect($this->parts->balance)['facts'];
        $this->assertTrue($facts['payer_collection_confirmed']);
        $this->assertFalse($facts['merchant_receipt_confirmed']);
        $this->assertSame(999, $facts['outstanding_remittance_minor']);
    }

    public function testOriginalAdvanceAndBalanceRefundsKeepSeparateTargetsAndDoNotRepeatEffects(): void
    {
        $this->receiveAdvance();
        $this->prepareCarrier();
        $this->collectBalance();
        $advance = new Request(
            new Identity('shop-a', 'purchase-portions', 'g1', 'refund-advance'),
            'refund-incoming',
            (new IncomingRefundTerms($this->parts->advance, 'bank-advance-original', 500))->toArray(),
            $this->parts->advance->identity
        );
        $balance = new Request(
            new Identity('shop-a', 'purchase-portions', 'g1', 'refund-balance'),
            'refund-cod',
            (new CodRefundTerms($this->parts->balance, 'carrier-balance-original', 999))->toArray(),
            $this->parts->balance->identity
        );
        foreach ([$advance, $balance] as $request) {
            $this->provider->commands->plan($request, Status::Confirmed);
            $first = $this->provider->commands->attempt($request);
            $this->assertSame(Status::Confirmed, $first->status);
            $this->assertSame($first->effectId, $this->provider->commands->attempt($request)->effectId);
        }
        $row = $this->db->query("SELECT COUNT(*) AS count, SUM(json_extract(o.request,'$.terms.amountMinor')) AS total
            FROM provider_effects e JOIN provider_operations o ON o.id=e.operation_id
            WHERE json_extract(o.request,'$.action') IN ('refund-incoming','refund-cod')")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $row['count']);
        $this->assertSame(1499, (int) $row['total']);
        $this->assertSame(
            500,
            $this->provider->incoming->receipts->inspect($this->parts->advance)['receivedAmountMinor']
        );
        $this->assertSame(999, $this->provider->cod->inspect($this->parts->balance)['facts']['collected_amount_minor']);
    }

    public static function foreignContexts(): array
    {
        return [['connection'], ['client'], ['clock'], ['transaction']];
    }

    #[DataProvider('foreignContexts')]
    public function testSharingAnotherProviderConnectionClientOrClockIsRefused(string $case): void
    {
        $db = new PDO('sqlite::memory:');
        $clock = static fn (): int => 1000;
        $commands = new Journal($db, 'shop-a', $clock);
        $candidate = $case === 'connection' ? new PDO('sqlite::memory:') : $db;
        if ($case === 'transaction') {
            $db->beginTransaction();
        }
        try {
            new IncomingPaymentProvider(
                $candidate,
                $case === 'client' ? 'shop-b' : 'shop-a',
                $case === 'clock' ? static fn (): int => 1000 : $clock,
                $commands
            );
            $this->fail('Sharing a foreign original payment context must refuse.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('original provider context', $error->getMessage());
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
        $this->assertSame(
            0,
            (int) $candidate->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")
                ->fetchColumn()
        );
    }

    private function prepareCarrier(): void
    {
        $graph = $this->parts->balance->shipment;
        $this->provider->commands->shipments()
            ->register($graph);
        foreach ([$graph->booking, $graph->deduction] as $request) {
            if ($request === $graph->deduction) {
                $this->provider->commands->shipments()
                    ->record($graph, 'original-handover', 'handed-over', 2);
            }
            $this->provider->commands->plan($request, Status::Confirmed);
            $this->assertSame(Status::Confirmed, $this->provider->commands->attempt($request)->status);
        }
    }

    private function receiveAdvance(): void
    {
        $this->provider->incoming->receipts->record($this->parts->advance, new IncomingPaymentReceipt(
            $this->parts->advance->identity->key(),
            'bank-advance-original',
            500,
            'USD',
            1000
        ));
    }

    private function collectBalance(): void
    {
        $this->provider->cod->collections->record($this->parts->balance, new CodReceipt(
            $this->parts->balance->identity->key(),
            'carrier-balance-original',
            CodReceipt::COLLECTION,
            999,
            'USD',
            1000,
            'point-original'
        ));
    }
}
