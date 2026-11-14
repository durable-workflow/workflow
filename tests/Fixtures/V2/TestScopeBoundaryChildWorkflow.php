<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Signal('finish')]
final class TestScopeBoundaryChildWorkflow extends Workflow
{
    public function handle(array $identity): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        try {
            $scope->execute(new Obligation('child', 'test.operation', '1', []));
            signal('finish');
            return [
                'status' => 'done',
            ];
        } catch (Throwable) {
            $this->compensate();
            return $scope->verifyCompensation();
        }
    }
}
