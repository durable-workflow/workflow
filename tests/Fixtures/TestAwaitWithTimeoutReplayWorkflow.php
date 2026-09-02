<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use function Workflow\activity;
use function Workflow\awaitWithTimeout;
use Workflow\Workflow;

final class TestAwaitWithTimeoutReplayWorkflow extends Workflow
{
    public function execute()
    {
        $result = yield awaitWithTimeout(1, static fn (): bool => false);

        yield activity(TestCountActivity::class, $result ? 1 : 0);

        return $result;
    }
}
