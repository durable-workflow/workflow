<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class LeaseBlockingActivity extends Activity
{
    public static int $invocations = 0;

    public static ?int $callbackPid = null;

    public function handle(float $seconds): array
    {
        self::$invocations++;
        self::$callbackPid = getmypid();
        usleep((int) ($seconds * 1_000_000));

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}
