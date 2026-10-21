<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Container\Container;

final class ActivityLease
{
    public const DURATION_MINUTES = 5;

    public const DURATION_SECONDS = self::DURATION_MINUTES * 60;

    public const CONFIG_KEY = 'workflows.v2.activity_task_lease_seconds';

    public const MAX_SECONDS = 86_400;

    public static function seconds(): int
    {
        $value = Container::getInstance()->bound('config')
            ? config(self::CONFIG_KEY, self::DURATION_SECONDS)
            : self::DURATION_SECONDS;
        $seconds = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => self::MAX_SECONDS,
            ],
        ]) : false;

        return $seconds === false ? self::DURATION_SECONDS : $seconds;
    }

    public static function expiresAt(): CarbonInterface
    {
        return now()->addSeconds(self::seconds());
    }
}
