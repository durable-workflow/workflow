<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

final class TestRedriveSecondActivity extends Activity
{
    public function handle(string $greeting): string
    {
        if (Cache::increment('test:redrive:second-calls') === 1) {
            throw new NonRetryableException('The first attempt failed.');
        }

        return $greeting . ' Redrive completed.';
    }
}
