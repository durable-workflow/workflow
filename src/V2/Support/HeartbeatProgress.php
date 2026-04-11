<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

final class HeartbeatProgress
{
    private const MAX_MESSAGE_LENGTH = 280;

    private const MAX_UNIT_LENGTH = 64;

    private const MAX_DETAIL_ENTRIES = 20;

    private const MAX_DETAIL_STRING_LENGTH = 191;

    private const DETAIL_KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * @param array<string, mixed> $progress
     * @return array<string, mixed>|null
     */
    public static function normalizeForWrite(array $progress): ?array
    {
        return self::normalize($progress, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromStored(mixed $progress): ?array
    {
        if (! is_array($progress)) {
            return null;
        }

        return self::normalize($progress, false);
    }

    /**
     * @param array<string, mixed> $progress
     * @return array<string, mixed>|null
     */
    private static function normalize(array $progress, bool $strict): ?array
    {
        if ($progress === []) {
            return null;
        }

        if ($strict) {
            $unknownKeys = array_diff(array_keys($progress), ['message', 'current', 'total', 'unit', 'details']);

            if ($unknownKeys !== []) {
                throw new LogicException(sprintf(
                    'Heartbeat progress only supports [message, current, total, unit, details]; unknown keys: [%s].',
                    implode(', ', $unknownKeys),
                ));
            }
        }

        $message = self::messageValue($progress['message'] ?? null, $strict);
        $current = self::numberValue($progress['current'] ?? null, 'current', $strict);
        $total = self::numberValue($progress['total'] ?? null, 'total', $strict);
        $unit = self::unitValue($progress['unit'] ?? null, $strict);
        $details = self::detailsValue($progress['details'] ?? null, $strict);

        if ($unit !== null && $current === null && $total === null) {
            if ($strict) {
                throw new LogicException('Heartbeat progress [unit] requires [current] or [total].');
            }

            $unit = null;
        }

        $normalized = array_filter([
            'message' => $message,
            'current' => $current,
            'total' => $total,
            'unit' => $unit,
            'details' => $details === [] ? null : $details,
        ], static fn (mixed $value): bool => $value !== null);

        return $normalized === [] ? null : $normalized;
    }

    private static function messageValue(mixed $value, bool $strict): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return self::invalid('[message] must be a string.', $strict);
        }

        $value = trim($value);

        if ($value === '') {
            return self::invalid('[message] must be a non-empty string.', $strict);
        }

        if (mb_strlen($value) > self::MAX_MESSAGE_LENGTH) {
            return self::invalid(sprintf(
                '[message] must be %d characters or fewer.',
                self::MAX_MESSAGE_LENGTH,
            ), $strict);
        }

        return $value;
    }

    private static function unitValue(mixed $value, bool $strict): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return self::invalid('[unit] must be a string.', $strict);
        }

        $value = trim($value);

        if ($value === '') {
            return self::invalid('[unit] must be a non-empty string.', $strict);
        }

        if (mb_strlen($value) > self::MAX_UNIT_LENGTH) {
            return self::invalid(sprintf(
                '[unit] must be %d characters or fewer.',
                self::MAX_UNIT_LENGTH,
            ), $strict);
        }

        return $value;
    }

    private static function numberValue(mixed $value, string $field, bool $strict): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && is_numeric($value)) {
            $value = str_contains($value, '.')
                ? (float) $value
                : (int) $value;
        }

        if (! is_int($value) && ! is_float($value)) {
            return self::invalid(sprintf('[%s] must be numeric.', $field), $strict);
        }

        if (is_float($value) && ! is_finite($value)) {
            return self::invalid(sprintf('[%s] must be finite.', $field), $strict);
        }

        if ($value < 0) {
            return self::invalid(sprintf('[%s] must be zero or greater.', $field), $strict);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function detailsValue(mixed $value, bool $strict): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            return self::invalid('[details] must be an object-like array.', $strict, []);
        }

        if ($strict && array_is_list($value)) {
            throw new LogicException('[details] must use string keys.');
        }

        if ($strict && count($value) > self::MAX_DETAIL_ENTRIES) {
            throw new LogicException(sprintf('[details] supports at most %d entries.', self::MAX_DETAIL_ENTRIES));
        }

        $details = [];

        foreach ($value as $key => $detailValue) {
            if (! is_string($key) || ! preg_match(self::DETAIL_KEY_PATTERN, $key)) {
                if ($strict) {
                    throw new LogicException(sprintf(
                        'Heartbeat progress detail key [%s] must match %s.',
                        (string) $key,
                        trim(self::DETAIL_KEY_PATTERN, '/'),
                    ));
                }

                continue;
            }

            if ($detailValue === null || is_bool($detailValue) || is_int($detailValue)) {
                $details[$key] = $detailValue;

                continue;
            }

            if (is_float($detailValue)) {
                if (! is_finite($detailValue)) {
                    if ($strict) {
                        throw new LogicException(sprintf('Heartbeat progress detail [%s] must be finite.', $key));
                    }

                    continue;
                }

                $details[$key] = floor($detailValue) === $detailValue
                    ? (int) $detailValue
                    : $detailValue;

                continue;
            }

            if (is_string($detailValue)) {
                $detailValue = trim($detailValue);

                if ($detailValue === '') {
                    if ($strict) {
                        throw new LogicException(sprintf(
                            'Heartbeat progress detail [%s] must be a non-empty string when provided.',
                            $key,
                        ));
                    }

                    continue;
                }

                if (mb_strlen($detailValue) > self::MAX_DETAIL_STRING_LENGTH) {
                    if ($strict) {
                        throw new LogicException(sprintf(
                            'Heartbeat progress detail [%s] must be %d characters or fewer.',
                            $key,
                            self::MAX_DETAIL_STRING_LENGTH,
                        ));
                    }

                    continue;
                }

                $details[$key] = $detailValue;

                continue;
            }

            if ($strict) {
                throw new LogicException(sprintf('Heartbeat progress detail [%s] must be scalar or null.', $key));
            }
        }

        ksort($details);

        return $details;
    }

    /**
     * @template T
     * @param T|null $fallback
     * @return T|null
     */
    private static function invalid(string $message, bool $strict, mixed $fallback = null): mixed
    {
        if ($strict) {
            throw new LogicException('Heartbeat progress ' . $message);
        }

        return $fallback;
    }
}
