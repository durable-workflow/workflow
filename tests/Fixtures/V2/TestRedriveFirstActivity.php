<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use Workflow\V2\Activity;

final class TestRedriveFirstActivity extends Activity
{
    public function handle(string $name): string
    {
        Cache::increment('test:redrive:first-calls');

        return "Hello, {$name}!";
    }
}
