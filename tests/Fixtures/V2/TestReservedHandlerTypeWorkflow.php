<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

/** Registers an obligation under the engine-reserved `dw.` handler prefix. */
final class TestReservedHandlerTypeWorkflow extends Workflow
{
    public function handle(array $identity): array
    {
        $scope = new DurableScope($this, ScopeIdentity::fromArray($identity), 'operator-a');
        try {
            $scope->register(new Obligation('engine-owned', 'dw.custom', '1', []));
        } catch (InvalidArgumentException) {
            return [
                'registered' => false,
            ];
        }
        return [
            'registered' => true,
        ];
    }
}
