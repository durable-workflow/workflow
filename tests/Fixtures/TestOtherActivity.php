<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Workflow\Activity;

final class TestOtherActivity extends Activity
{
    public function execute(Application $app, $string)
    {
        if (! $app->runningInConsole()) {
            throw new RuntimeException('Test activities must run in console.');
        }

        return $string;
    }
}
