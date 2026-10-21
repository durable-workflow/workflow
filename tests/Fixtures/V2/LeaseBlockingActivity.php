<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class LeaseBlockingActivity extends Activity
{
    public static int $invocations = 0;

    public static ?int $callbackPid = null;

    public static bool $recordProgress = false;

    public static ?\Closure $beforeWait = null;

    public function handle(float $seconds): array
    {
        self::$invocations++;
        self::$callbackPid = getmypid();
        (self::$beforeWait)?->__invoke();
        if (self::$recordProgress) {
            for ($step = 0; $step < 4; $step++) {
                usleep((int) ($seconds * 250_000));
                $this->heartbeat([
                    'current' => $step + 1,
                    'total' => 4,
                ]);
            }
        } else {
            usleep((int) ($seconds * 1_000_000));
        }

        return [
            'integer' => 9007199254740993,
            'optional' => null,
            'unicode' => 'λ',
        ];
    }
}
