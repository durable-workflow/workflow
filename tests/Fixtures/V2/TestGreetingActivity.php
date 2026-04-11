<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestGreetingActivity extends Activity
{
    public function handle(string $name): string
    {
        return "Hello, {$name}!";
    }
}
