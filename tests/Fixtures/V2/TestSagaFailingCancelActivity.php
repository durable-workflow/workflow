<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Activity;

final class TestSagaFailingCancelActivity extends Activity
{
    public static array $log = [];

    public static function resetLog(): void
    {
        self::$log = [];
    }

    public function handle(string $service, string $bookingId): never
    {
        self::$log[] = "fail-cancel:{$service}:{$bookingId}";

        throw new RuntimeException("Cancel failed for {$service}");
    }
}
