<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Carbon;
use Workflow\V2\Activity;

final class TestElapsedLocalCleanupActivity extends Activity
{
    public function handle(): string
    {
        Carbon::setTestNow(now()->addSeconds(11));

        return 'cleaned';
    }
}
