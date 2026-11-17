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
    public function handle(
        array $identity,
        array $descriptor,
        string $handler = 'test.unit-payment',
        bool $execute = true
    ): array {
        if (! in_array($handler, ['test.unit-payment', 'test.unit-refund'], true)) {
            throw new \LogicException('The unit-money fixture handler is unavailable.');
        }
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'unit-money-worker');
        $registered = $scope->register(new Obligation(
            $handler === 'test.unit-payment' ? 'payment.capture' : 'payment.shortage',
            $handler,
            '1',
            $descriptor
        ));
        return $execute ? $registered->execute(new ActivityOptions(maxAttempts: 1)) : [
            'registered' => true,
        ];
    }
}
