<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-replayed-failure-child-workflow')]
final class TestReplayedFailureChildWorkflow extends Workflow
{
    public function handle(string $orderId): string
    {
        activity(TestReplayedFailureActivity::class, $orderId);

        return 'never';
    }
}
