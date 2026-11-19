<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Definition;

use InvalidArgumentException;
use JsonException;

/**
 * @internal Compensation format or engine implementation.
 *
 * Canonical, bounded data only. This format never resolves classes or callables.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class PortableDescriptor
{
    public const MAX_BYTES = 16_384;

    public const MAX_DEPTH = 8;

    public const MAX_VALUES = 512;

    /**
     * Limits on an obligation's own input, below the descriptor limit so envelope growth never shrinks them.
     */
    public const USER_INPUT_MAX_BYTES = 15_360;

    public const USER_INPUT_MAX_VALUES = 500;

    public const USER_INPUT_MAX_DEPTH = 7;

    /**
     * Limits on a forward result's own value, leaving room for 32 evidence references.
     */
    public const USER_VALUE_MAX_BYTES = 11_264;

    public const USER_VALUE_MAX_VALUES = 400;

    public const USER_VALUE_MAX_DEPTH = 7;

    public static function encode(array $value): string
    {
        $count = 0;
        $normalized = self::normalize($value, 0, $count);
        try {
            $json = json_encode(
                $normalized,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                'Compensation data must be valid portable UTF-8 data.',
                previous: $error
            );
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Compensation data exceeds the byte limit.');
        }
        return $json;
    }

    /**
     * Validate caller data against its own documented limits, independent of the envelope it travels in.
     */
    public static function userData(array $value, string $name, int $maxBytes, int $maxValues, int $maxDepth): void
    {
        $count = 0;
        self::measure($value, 0, $count, $maxValues, $maxDepth, $name);
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new InvalidArgumentException(
                "Compensation {$name} must be valid portable UTF-8 data.",
                previous: $error
            );
        }
        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException("Compensation {$name} exceeds {$maxBytes} bytes.");
        }
    }

    public static function identifier(string $value, string $name): void
    {
        if ($value === '' || strlen($value) > 128 || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:\/-]*\z/D', $value)) {
            throw new InvalidArgumentException("Invalid compensation {$name}.");
        }
    }

    /**
     * @template T of array
     * @param T $value
     * @return T
     */
    public static function snapshot(array $value): array
    {
        self::encode($value);
        $count = 0;
        /** @var T $snapshot */
        $snapshot = self::normalize($value, 0, $count);
        return $snapshot;
    }

    private static function measure(
        mixed $value,
        int $depth,
        int &$count,
        int $maxValues,
        int $maxDepth,
        string $name
    ): void {
        if (++$count > $maxValues || $depth > $maxDepth) {
            throw new InvalidArgumentException(
                "Compensation {$name} exceeds {$maxValues} values or depth {$maxDepth}."
            );
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::measure($child, $depth + 1, $count, $maxValues, $maxDepth, $name);
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) {
            return;
        }
        throw new InvalidArgumentException(
            'Compensation data cannot contain objects, resources or non-finite numbers.'
        );
    }

    private static function normalize(mixed $value, int $depth, int &$count): mixed
    {
        if (++$count > self::MAX_VALUES || $depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Compensation data exceeds the structural limit.');
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::normalize($child, $depth + 1, $count);
            }
            return $copy;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new InvalidArgumentException(
            'Compensation data cannot contain objects, resources or non-finite numbers.'
        );
    }
}
