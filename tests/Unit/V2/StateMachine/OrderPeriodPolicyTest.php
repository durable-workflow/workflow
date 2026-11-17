<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class OrderPeriodPolicyTest extends TestCase
{
    public static function invalidGrants(): array
    {
        return [['service-only'], ['wrong-recipe'], ['missing-physical-budget'],
            ['shared-payment-physical-deadline'], ['duplicate-period'], ['unassigned-order']];
    }

    #[DataProvider('invalidGrants')]
    public function testOrderAuthorityRejectsAnUnboundOrAmbiguousGrantBeforeAnySql(string $case): void
    {
        $period = $this->period();
        if ($case !== 'service-only') {
            $deadline = match ($case) {
                'missing-physical-budget' => null,
                'shared-payment-physical-deadline' => $period->paymentDeadline,
                default => $period->endsAt + 3600,
            };
            $value = $period->toArray();
            $value['order'] = (new OrderTerms(
                'shop-a',
                'original-order',
                $period->ownerGeneration(),
                1499,
                'USD',
                'sku-1',
                2,
                fulfillmentDeadline: $deadline
            ))->toArray();
            $period = BillingPeriod::fromArray($value);
        }
        $key = $period->order?->subjectKey();
        $subjects = $key === null || $case === 'unassigned-order' ? [] : [$key];
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('transactionLevel')
            ->willReturn(0);
        $db->expects($this->never())
            ->method('table');
        $this->expectException(LogicException::class);
        new Authority($db, 'period-worker', $subjects, $subjects === [] ? [] : [
            $key => $case === 'wrong-recipe' ? FundingCatalogue::AFTER_DELIVERY : FundingCatalogue::IMMEDIATE,
        ], orderPeriods: $case === 'duplicate-period' ? [$period, $period] : [$period]);
    }

    public function testAChangedImmutableAccessPolicyCannotReuseTheOriginalOrderGrant(): void
    {
        $period = $this->period();
        $value = $period->toArray();
        $value['order'] = (new OrderTerms(
            'shop-a',
            'original-order',
            $period->ownerGeneration(),
            1499,
            'USD',
            'sku-1',
            2,
            fulfillmentDeadline: $period->endsAt + 3600
        ))->toArray();
        $original = BillingPeriod::fromArray($value);
        $value['accessDuringGrace'] = false;
        $replacement = BillingPeriod::fromArray($value);
        $key = $original->order->subjectKey();
        $db = $this->createStub(ConnectionInterface::class);
        $db->method('transactionLevel')
            ->willReturn(0);
        $authority = static fn (BillingPeriod $grant): Authority => new Authority(
            $db,
            'period-worker',
            [$key],
            [
                $key => FundingCatalogue::IMMEDIATE,
            ],
            orderPeriods: [$grant]
        );
        $this->assertNotSame(
            $authority($original)
                ->grantFor($key, 'order'),
            $authority($replacement)
                ->grantFor($key, 'order')
        );
        $this->assertSame(
            $authority($original)
                ->grantFor($key, 'order'),
            $authority(BillingPeriod::fromArray($original->toArray()))->grantFor($key, 'order')
        );
    }

    private function period(): BillingPeriod
    {
        return new BillingPeriod(
            'shop-a',
            'service-a',
            'subscription-g1',
            '2026-01',
            1499,
            'USD',
            1000,
            3000000,
            865000,
            BillingPeriod::GRACE,
            true
        );
    }
}
