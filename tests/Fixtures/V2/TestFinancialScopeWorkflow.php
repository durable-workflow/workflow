<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

/** Scope/adapter qualification only; capture selection here is not a stock or physical-delivery policy. */
final class TestFinancialScopeWorkflow extends Workflow
{
    public function handle(array $identity, array $descriptor, bool $capture, bool $paired = true): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'financial-worker');
        $authorization = $scope->register(
            new Obligation('payment.authorize', 'test.financial.authorize', '1', $descriptor)
        );
        $money = $paired ? $scope->register(
            new Obligation('payment.capture', 'test.financial.capture', '1', $descriptor)
        ) : null;
        $permission = $authorization->execute(new ActivityOptions(maxAttempts: 1));
        $charge = $capture && $money !== null ? $money->execute(new ActivityOptions(maxAttempts: 1)) : null;
        return [
            'authorization' => $permission,
            'capture' => $charge,
        ];
    }
}
