<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Support\LocalActivityOptions;
use Workflow\V2\Workflow;
use function Workflow\V2\localActivity;

final class TestLocalHeartbeatWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        return localActivity(TestHeartbeatActivity::class, new LocalActivityOptions(
            heartbeatTimeout: 300,
        ));
    }
}
