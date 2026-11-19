<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

/**
 * Registers a `dw.` handler type and forwards an obligation. Its replay fixture was recorded on 2.31.0,
 * before the `dw.compensation.registration-checks` patch, so neither check applies on replay.
 */
final class TestPrePatchScopeWorkflow extends Workflow
{
    public function handle(array $identity): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        try {
            $scope->register(new Obligation('legacy', 'dw.legacy', '1', []));
        } catch (InvalidArgumentException) {
            return [
                'registered' => false,
                'value' => null,
            ];
        }
        return [
            'registered' => true,
            'value' => $scope->execute(new Obligation('forward', 'test.operation', '1', [])),
        ];
    }
}
