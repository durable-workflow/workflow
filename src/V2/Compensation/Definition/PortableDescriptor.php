<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Definition;

use InvalidArgumentException;
use JsonException;

/**
 * Canonical, bounded data only. This format never resolves classes or callables.
 *
 * @internal
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class PortableDescriptor
{
    public const MAX_BYTES = 16_384;

    public const MAX_DEPTH = 8;

    public const MAX_VALUES = 512;

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

    public static function identifier(string $value, string $name): void
    {
        if ($value === '' || strlen($value) > 128 || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:\/-]*\z/D', $value)) {
            throw new InvalidArgumentException("Invalid compensation {$name}.");
        }
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
            foreach ($value as $key => $child) {
                $value[$key] = self::normalize($child, $depth + 1, $count);
            }
            return $value;
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
