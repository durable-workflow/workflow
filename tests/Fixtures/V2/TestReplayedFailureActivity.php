<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestReplayedFailureActivity extends Activity
{
    public function execute(string $orderId): never
    {
        throw new TestReplayedDomainException($orderId, 'api');
    }
}
