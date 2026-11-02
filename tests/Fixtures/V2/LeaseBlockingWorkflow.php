<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class LeaseBlockingWorkflow extends Workflow
{
    public function handle(float $seconds): array
    {
        return self::activity(LeaseBlockingActivity::class, $seconds);
    }
}
