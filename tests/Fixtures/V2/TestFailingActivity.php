<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Activity;

final class TestFailingActivity extends Activity
{
    public function execute(): string
    {
        throw new RuntimeException('boom');
    }
}
