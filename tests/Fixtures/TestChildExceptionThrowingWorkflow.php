<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Exception;
use Workflow\Workflow;

class TestChildExceptionThrowingWorkflow extends Workflow
{
    public function execute()
    {
        throw new Exception('child failed');
    }
}
