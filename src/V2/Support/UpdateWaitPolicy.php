<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Validation\ValidationException;

final class UpdateWaitPolicy
{
    public const COMPLETION_TIMEOUT_SECONDS = 10;

    public const POLL_INTERVAL_MILLISECONDS = 50;

    public static function completionTimeoutSeconds(): int
    {
        return self::configuredPositiveInt(
            'workflows.v2.update_wait.completion_timeout_seconds',
            self::COMPLETION_TIMEOUT_SECONDS,
        );
    }

    public static function pollIntervalMilliseconds(): int
    {
        return self::configuredPositiveInt(
            'workflows.v2.update_wait.poll_interval_milliseconds',
            self::POLL_INTERVAL_MILLISECONDS,
        );
    }

    /**
     * @return array{completion_timeout_seconds: int, poll_interval_milliseconds: int}
     */
    public static function snapshot(): array
    {
        return [
            'completion_timeout_seconds' => self::completionTimeoutSeconds(),
            'poll_interval_milliseconds' => self::pollIntervalMilliseconds(),
        ];
    }

    public static function requestedTimeoutSeconds(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 1) {
            return (int) $value;
        }

        throw ValidationException::withMessages([
            'wait_timeout_seconds' => ['The wait_timeout_seconds field must be a positive integer.'],
        ]);
    }

    private static function configuredPositiveInt(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }
}
