<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

final class TestForwardAdmissionWorkflow extends Workflow
{
    public function handle(array $identity, string $mode): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        return $scope->execute(new Obligation('first', 'test.operation', '1', [
            'mode' => $mode,
        ]));
    }
}
