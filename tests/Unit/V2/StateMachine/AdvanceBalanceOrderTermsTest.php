<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvanceBalanceFixture;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Original host assignment only; native mixed-payment execution is not qualified by this contract. */
final class AdvanceBalanceOrderTermsTest extends TestCase
{
    use AdvanceBalanceFixture;

    public function testOriginalAdvanceAndCarrierBalanceSurviveTheFullOrderWire(): void
    {
        $terms = $this->order();
        $copy = OrderTerms::fromArray($terms->toArray());
        $this->assertSame($terms->toArray(), $copy->toArray());
        $this->assertSame($terms->subjectKey(), $copy->subjectKey());
        $this->assertSame($terms->physicalDescriptor(), $copy->advanceBalance->balance->shipment->toArray());
        $this->assertSame($this->originalParts()->toArray(), $copy->advanceBalanceDescriptor());
        $this->assertSame(500, $copy->advanceBalance->advance->amountMinor);
        $this->assertSame(999, $copy->advanceBalance->balance->amountMinor);
        $this->expectException(InvalidArgumentException::class);
        $copy->descriptor('payment');
    }

    public function testHistoricalOrderWireDoesNotAcquireAnAdvanceAssignment(): void
    {
        $old = new OrderTerms('shop-a', 'purchase-portions', 'g1', 1499, 'USD', 'sku-1', 2);
        $wire = $old->toArray();
        $this->assertSame(
            ['client', 'subject', 'generation', 'amountMinor', 'currency', 'sku', 'quantity'],
            array_keys($wire)
        );
        $this->assertSame($wire, OrderTerms::fromArray($wire)->toArray());
        $this->expectException(InvalidArgumentException::class);
        $old->advanceBalanceDescriptor();
    }

    public function testPairedMoneyCannotUseAnIncompatibleRecipeOrMissingProvidersBeforeStorage(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transactionLevel')
            ->willReturn(0);
        $db->expects($this->never())
            ->method('transaction');
        $db->expects($this->never())
            ->method('table');
        $terms = $this->order();
        foreach ([Catalogue::RESERVE_FIRST, Catalogue::PAYMENT_FIRST,
            FinancialCatalogue::AFTER_STOCK, FinancialCatalogue::AFTER_DELIVERY,
            ...FundingCatalogue::recipes()] as $recipe) {
            $authority = new Authority($db, 'worker-original', [$terms->subjectKey()], [
                $terms->subjectKey() => $recipe,
            ]);
            try {
                (new MachineStore($authority))->seed($terms);
                $this->fail('Paired money requires its exact recipe and original provider assembly.');
            } catch (LogicException $error) {
                $this->assertSame(
                    'The selected native recipe does not implement the original advance and balance.',
                    $error->getMessage()
                );
            }
        }
    }

    public static function substitutions(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'client', 'subject', 'generation', 'money', 'currency', 'sku', 'quantity',
            'card', 'bank', 'carrier', 'null', 'string',
        ]);
    }

    #[DataProvider('substitutions')]
    public function testAnotherOwnerMoneyShipmentOrPaymentModeCannotReplaceTheOriginalAssignment(string $case): void
    {
        $wire = $this->order()
            ->toArray();
        match ($case) {
            'client', 'subject', 'generation', 'sku' => $wire[$case] = 'another-original',
            'money' => ++$wire['amountMinor'],
            'currency' => $wire['currency'] = 'EUR',
            'quantity' => ++$wire['quantity'],
            'card' => $wire['authorization'] = (new AuthorizationTerms(1499, 'USD', 1100))->toArray(),
            'null' => $wire['advanceBalance'] = null,
            'string' => $wire['advanceBalance'] = 'replace-original-portions',
            default => null,
        };
        if ($case === 'bank') {
            $wire['incomingPayment'] = $wire['advanceBalance']['advance'];
            $wire['incomingAdmissionContract'] = $wire['advanceBalance']['advanceAdmissionContract'];
        } elseif ($case === 'carrier') {
            $wire['codPayment'] = $wire['advanceBalance']['balance'];
            $wire['codAdmissionContract'] = $wire['advanceBalance']['balanceAdmissionContract'];
        }
        $this->expectException(InvalidArgumentException::class);
        OrderTerms::fromArray($wire);
    }

    private function order(): OrderTerms
    {
        return new OrderTerms(
            'shop-a',
            'purchase-portions',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            fulfillmentDeadline: 3000,
            advanceBalance: $this->originalParts()
        );
    }
}
