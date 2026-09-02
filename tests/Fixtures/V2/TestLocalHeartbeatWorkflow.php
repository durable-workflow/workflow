<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\localActivity;
use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Workflow;

final class TestLocalHeartbeatWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return localActivity(TestHeartbeatActivity::class, new LocalActivityOptions(heartbeatTimeout: 300));
    }
}
