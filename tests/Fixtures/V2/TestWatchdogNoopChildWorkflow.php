<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-watchdog-noop-child-workflow')]
final class TestWatchdogNoopChildWorkflow extends Workflow
{
    public function handle(string $label): string
    {
        return sprintf('child:%s', $label);
    }
}
