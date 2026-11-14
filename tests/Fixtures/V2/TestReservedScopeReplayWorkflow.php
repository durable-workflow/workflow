<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\Workflow;

final class TestReservedScopeReplayWorkflow extends Workflow
{
    public function handle(array $parent, array $child, array $binding): array
    {
        return (new DurableScope($this, ScopeIdentity::fromArray($parent), 'owner'))->reserveChild(
            'child',
            ScopeIdentity::fromArray($child),
            BindingIdentity::fromArray($binding),
        )->toArray();
    }
}
