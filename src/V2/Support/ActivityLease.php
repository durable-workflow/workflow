<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ActivityLease
{
    public const DURATION_MINUTES = 5;

    public const DURATION_SECONDS = self::DURATION_MINUTES * 60;

    public static function expiresAt(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes(self::DURATION_MINUTES);
    }
}
