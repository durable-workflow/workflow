<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Activity;

final class TestFractionalRetryActivity extends Activity
{
    public static int $calls = 0;

    public function handle(int $failures): string
    {
        if (++self::$calls <= $failures) {
            throw new RuntimeException('Retry this activity.');
        }

        return 'completed';
    }
}
