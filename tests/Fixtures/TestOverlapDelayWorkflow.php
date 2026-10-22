<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Workflow;

final class TestOverlapDelayWorkflow extends Workflow
{
    public function execute()
    {
        yield from [];

        return 'completed';
    }
}
