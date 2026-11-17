<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

/** Native payment-adapter control; common Order/Shipment fulfillment is qualified separately. */
final class TestUnitPaymentScopeWorkflow extends Workflow
{
    public function handle(array $identity, array $descriptor): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'unit-money-worker');
        return $scope->execute(
            new Obligation('payment.capture', 'test.unit-payment', '1', $descriptor),
            new ActivityOptions(maxAttempts: 1)
        );
    }
}
