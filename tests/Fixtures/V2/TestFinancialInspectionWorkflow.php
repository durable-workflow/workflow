<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use DurableWorkflow\Examples\ProviderStateMachines\FinancialInspectionActivity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

/** Native observation qualification; no stock/delivery trigger or full Order composition is supplied here. */
final class TestFinancialInspectionWorkflow extends Workflow
{
    public function handle(array $identity, array $descriptor, bool $paired = true): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'financial-worker');
        $authorization = $scope->register(
            new Obligation('payment.authorize', 'test.financial.authorize', '1', $descriptor)
        );
        if ($paired) {
            $scope->register(new Obligation('payment.capture', 'test.financial.capture', '1', $descriptor));
        }
        $before = self::activity(FinancialInspectionActivity::class, $identity, $descriptor);
        $authorization->execute();
        return [
            'before' => $before,
            'after' => self::activity(FinancialInspectionActivity::class, $identity, $descriptor),
        ];
    }
}
