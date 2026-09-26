<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Persist schedule instants as UTC in timezone-free database columns. */
final class UtcScheduleTimestamp implements CastsAttributes
{
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = Carbon::parse($value, date_default_timezone_get());
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException("{$key} must be a date-time value.");
        }

        return self::databaseValue($value);
    }

    public static function databaseValue(DateTimeInterface $value): string
    {
        return Carbon::instance($value)->utc()->format('Y-m-d H:i:s.u');
    }
}
