<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class ServiceCallDeadlinePolicy
{
    public static function resolve(?array $policy, DateTimeInterface $acceptedAt): ?CarbonImmutable
    {
        if ($policy === null) {
            return null;
        }

        if (array_keys($policy) === ['timeout_seconds']) {
            $seconds = $policy['timeout_seconds'];
            if (! is_int($seconds) || $seconds < 1 || $seconds > 2_147_483_647) {
                throw new InvalidArgumentException('Service timeout_seconds must be an integer from 1 to 2147483647.');
            }
            $deadline = CarbonImmutable::instance($acceptedAt)->utc()->addSeconds($seconds);
        } elseif (array_keys($policy) === ['deadline_at']) {
            $value = $policy['deadline_at'];
            if (! is_string($value) || preg_match(
                '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/',
                $value,
                $parts,
            ) !== 1) {
                throw new InvalidArgumentException(
                    'Service deadline_at must be offset-bearing RFC3339 with at most six fractional digits.'
                );
            }
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i:s.uP',
                $parts[1] . '.' . str_pad($parts[2], 6, '0') . $parts[3],
                new DateTimeZone('UTC'),
            );
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw new InvalidArgumentException('Service deadline_at must be a valid calendar instant.');
            }
            $deadline = CarbonImmutable::instance($parsed)->utc();
        } else {
            throw new InvalidArgumentException(
                'Service deadline_policy must contain exactly timeout_seconds or deadline_at.'
            );
        }

        if ($deadline->year < 1970 || $deadline->year > 9999) {
            throw new InvalidArgumentException(
                'Service call deadlines must resolve within UTC years 1970 through 9999.'
            );
        }

        return $deadline;
    }
}
