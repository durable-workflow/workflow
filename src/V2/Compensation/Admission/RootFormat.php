<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use InvalidArgumentException;

/** @internal Closed data decoding. No class resolution or storage access. */
final class RootFormat
{
    public static function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('Unexpected or missing root reservation fields.');
        }
    }

    public static function strings(array $value, array $required, array $nullable = []): void
    {
        foreach ($required as $key) {
            if (! is_string($value[$key] ?? null)) {
                throw new InvalidArgumentException('Root reservation fields must have their declared types.');
            }
        }
        foreach ($nullable as $key) {
            if (! array_key_exists($key, $value) || ($value[$key] !== null && ! is_string($value[$key]))) {
                throw new InvalidArgumentException('Root reservation fields must have their declared nullable types.');
            }
        }
    }
}
