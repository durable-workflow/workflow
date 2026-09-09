<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Carbon;
use Workflow\V2\Activity;

final class TestClockAdvancingActivity extends Activity
{
    public function handle(): string
    {
        Carbon::setTestNow(now()->addMinute());

        return 'too late';
    }
}
