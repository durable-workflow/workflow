<?php

declare(strict_types=1);

namespace Tests\Support;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;

trait AdvanceBalanceFixture
{
    private function originalParts(int $total = 1499, int $advance = 500): AdvanceBalanceTerms
    {
        $order = new OrderTerms('shop-a', 'purchase-portions', 'g1', $total, 'USD', 'sku-1', 2);
        return new AdvanceBalanceTerms(
            new IncomingPaymentDescriptor(
                new Identity('shop-a', 'purchase-portions', 'g1', 'bank-advance'),
                'advance-original',
                $advance,
                'USD',
                1100
            ),
            new CodDescriptor(
                new Identity('shop-a', 'purchase-portions', 'g1', 'carrier-balance'),
                ShipmentDescriptor::fromArray($order->physicalDescriptor()),
                'balance-original',
                'point-original',
                $total - $advance,
                'USD',
                1200,
                2000
            ),
            $total,
            'USD',
            IncomingPaymentJournal::CLOSABLE,
            CodJournal::CLOSABLE
        );
    }
}
