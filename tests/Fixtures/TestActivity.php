<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use AssertionError;
use Illuminate\Contracts\Foundation\Application;
use Workflow\Activity;

class TestActivity extends Activity
{
    public function execute(Application $app)
    {
        if (! $app->runningInConsole()) {
            throw new AssertionError('Test activities must run in console.');
        }

        return 'activity';
    }
}
