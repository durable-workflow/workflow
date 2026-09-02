<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestSagaCancelActivity extends Activity
{
    public static array $log = [];

    public static function resetLog(): void
    {
        self::$log = [];
    }

    public function handle(string $service, string $bookingId): string
    {
        self::$log[] = "cancel:{$service}:{$bookingId}";

        return "cancelled-{$bookingId}";
    }
}
