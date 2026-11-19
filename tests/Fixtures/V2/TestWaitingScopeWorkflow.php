<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

final class TestWaitingScopeWorkflow extends Workflow
{
    public function handle(array $identity): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        try {
            $scope->execute(new Obligation('first', 'test.operation', '1', []));
            $scope->execute(new Obligation('second', 'test.operation', '1', []));
            self::await(static fn (): bool => false, conditionKey: 'business-result');
            return [
                'status' => 'unexpected-completion',
            ];
        } catch (Throwable) {
            $this->compensate();
            return $scope->verifyCompensation();
        }
    }
}
