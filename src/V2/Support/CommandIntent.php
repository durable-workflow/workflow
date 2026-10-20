<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Enums\CommandType;

/**
 * Canonical intent digest for keyed signal and update admissions.
 *
 * Two admissions that reuse one caller key are the same command only when
 * their command name, typed arguments and explicit run target match. The
 * digest is stable across SDKs: map entries are ordered by key bytes, integers
 * and floats keep their types, strings compare by bytes and Unicode is never
 * normalized. A protected payload (client-side encryption) must contribute
 * its authenticated plaintext intent through {@see PROTECTED_INTENT_KEY}
 * instead of its ciphertext bytes.
 *
 * @api
 */
final class CommandIntent
{
    public const VERSION = 'v1';

    /**
     * A map carrying only this key is a protected value whose plaintext
     * intent was digested by the caller. Its value replaces the ciphertext.
     */
    public const PROTECTED_INTENT_KEY = '__dw_intent';

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function digest(
        CommandType $type,
        string $name,
        array $arguments,
        string $targetScope = 'instance',
        ?string $requestedRunId = null,
    ): string {
        $canonical = self::canonical([
            'arguments' => $arguments,
            'name' => $name,
            'target' => [
                'run' => $targetScope === 'run' ? $requestedRunId : null,
                'scope' => $targetScope === 'run' ? 'run' : 'instance',
            ],
            'type' => $type->value,
        ]);

        return self::VERSION . ':' . hash('sha256', $canonical);
    }

    public static function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^v[0-9]+:[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * Type-tagged canonical encoding used by {@see digest()}.
     */
    public static function canonical(mixed $value): string
    {
        if ($value === null) {
            return 'n';
        }

        if (is_bool($value)) {
            return $value ? 'b1' : 'b0';
        }

        if (is_int($value)) {
            return 'i' . $value;
        }

        if (is_float($value)) {
            return 'd' . self::floatToken($value);
        }

        if (is_string($value)) {
            return 's' . strlen($value) . ':' . $value;
        }

        if ($value instanceof AvroBinaryValue) {
            return 'x' . strlen($value->bytes) . ':' . $value->bytes;
        }

        if ($value instanceof AvroMapValue) {
            $pairs = [];

            foreach ($value->pairs as $pair) {
                $pairs[] = [(string) $pair[0], $pair[1]];
            }

            return self::mapToken($pairs);
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = '';

                foreach ($value as $item) {
                    $items .= self::canonical($item);
                }

                return 'l' . count($value) . '[' . $items . ']';
            }

            $pairs = [];

            foreach ($value as $key => $item) {
                $pairs[] = [(string) $key, $item];
            }

            return self::mapToken($pairs);
        }

        if ($value instanceof \BackedEnum) {
            return self::canonical($value->value);
        }

        if ($value instanceof \DateTimeInterface) {
            return 't' . $value->format('Y-m-d\TH:i:s.uP');
        }

        if ($value instanceof \Stringable) {
            return self::canonical((string) $value);
        }

        $serialized = serialize($value);

        return 'o' . strlen($serialized) . ':' . $serialized;
    }

    /**
     * @param list<array{0: string, 1: mixed}> $pairs
     */
    private static function mapToken(array $pairs): string
    {
        if (count($pairs) === 1 && $pairs[0][0] === self::PROTECTED_INTENT_KEY && is_string($pairs[0][1])) {
            return 'p' . strlen($pairs[0][1]) . ':' . $pairs[0][1];
        }

        usort($pairs, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        $entries = '';

        foreach ($pairs as [$key, $item]) {
            $entries .= 's' . strlen($key) . ':' . $key . self::canonical($item);
        }

        return 'm' . count($pairs) . '{' . $entries . '}';
    }

    private static function floatToken(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }

        // Fixed-endian IEEE 754 bytes preserve every finite value without
        // depending on the process's locale or serialize_precision setting.
        return '0x' . bin2hex(pack('E', $value));
    }
}
