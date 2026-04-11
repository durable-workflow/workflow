<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('test-execute-compatibility-activity')]
final class TestExecuteCompatibilityActivity extends Activity
{
    public function execute(string $name): string
    {
        return "Hello, {$name}!";
    }
}
