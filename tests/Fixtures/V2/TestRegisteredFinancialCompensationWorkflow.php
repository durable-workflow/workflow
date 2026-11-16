<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestRegisteredFinancialCompensationWorkflow extends Workflow
{
    public function handle(array $identity, array $descriptor, string $mode): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'financial-worker');
        $authorization = $scope->register(
            new Obligation('payment.authorize', 'test.financial.authorize', '1', $descriptor)
        );
        $money = $scope->register(new Obligation('payment.capture', 'test.financial.capture', '1', $descriptor));
        $one = new ActivityOptions(maxAttempts: 1);
        $authorization->execute($one);
        if ($mode !== 'unused') {
            $money->execute($one);
        }
        if ($mode === 'out-of-order') {
            $authorization->compensate($one);
        }
        $money->compensate($one);
        $money->compensate($one);
        $authorization->compensate($one);
        // Automatic callbacks retain responsibility and observe these same original verified rows.
        $this->compensate();
        return $scope->verifyCompensation();
    }
}
