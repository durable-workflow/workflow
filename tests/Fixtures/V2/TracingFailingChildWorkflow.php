<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Workflow;

final class TracingFailingChildWorkflow extends Workflow
{
    public function handle(): never
    {
        throw new RuntimeException('deliberate terminal child failure');
    }
}
