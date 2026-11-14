<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

final class TestRetainedScopeWorkflow extends Workflow
{
    public function handle(array $identity, string $mode, ?array $child = null): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        try {
            $scope->execute(new Obligation('first', 'test.operation', '1', [
                'mode' => $mode,
            ]), new ActivityOptions(maxAttempts: 1));
            if ($child !== null) {
                $scope->participate('delivery', ScopeIdentity::fromArray($child));
            }
            $scope->execute(new Obligation('second', 'test.operation', '1', []));
            return [
                'status' => 'done',
            ];
        } catch (Throwable) {
            $this->compensate();
            return $scope->verifyCompensation();
        }
    }
}
