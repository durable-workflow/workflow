<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Activity;

final class TestNonRetryableActivity extends Activity
{
    public function handle(): string
    {
        throw new NonRetryableException('Payment permanently declined');
    }
}
