<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;
use Workflow\Workflow;

final class TestProbeChildFailureWorkflow extends Workflow
{
    public function execute(string $child)
    {
        throw new RuntimeException("child failed: {$child}");
    }
}
