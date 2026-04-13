<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestSagaBookingActivity extends Activity
{
    public static array $log = [];

    public static function resetLog(): void
    {
        self::$log = [];
    }

    public function handle(string $service): string
    {
        self::$log[] = "book:{$service}";

        return "{$service}-id-" . random_int(100, 999);
    }
}
