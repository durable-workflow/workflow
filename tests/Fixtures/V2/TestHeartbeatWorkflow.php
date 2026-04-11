<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\activity;
use Workflow\V2\Workflow;

#[Type('test-heartbeat-workflow')]
final class TestHeartbeatWorkflow extends Workflow
{
    public function handle(): array
    {
        return activity(TestHeartbeatActivity::class);
    }
}
