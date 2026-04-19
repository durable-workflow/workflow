<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Workflow;

final class TestActivityArgumentObjectWorkflow extends Workflow
{
    public function handle(): string
    {
        return activity(TestActivityArgumentObjectActivity::class, new TestActivityArgumentObject('hello', 3));
    }
}
