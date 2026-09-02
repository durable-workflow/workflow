<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\CodecRegistry;

/**
 * Lossless persisted representation for portable memo values.
 *
 * Memo values use the fixed Avro Value schema. The surrounding envelope is
 * safe to retain in JSON columns while its blob preserves union-branch
 * identity, binary bytes, and map semantics across process restarts.
 */
final class MemoPayload
{
    public const CODEC = 'avro';

    /**
     * Integer-like strings are excluded because PHP coerces canonical decimal
     * strings to integer array keys, which would change their Avro map identity.
     */
    public const KEY_PATTERN = '^(?!-?[0-9]+$)[A-Za-z0-9_.:-]{1,64}$';

    /**
     * @return array{codec: string, blob: string}
     */
    public static function envelope(mixed $value): array
    {
        return [
            'codec' => self::CODEC,
            'blob' => Avro::serialize(self::canonicalValue($value)),
        ];
    }

    /**
     * Encode a memo map without letting PHP's empty-array ambiguity select
     * the Avro array branch.
     *
     * @param array<string, mixed> $value
     * @return array{codec: string, blob: string}
     */
    public static function mapEnvelope(array $value): array
    {
        return self::envelope($value === [] ? AvroMapValue::fromPairs([]) : $value);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function decode(array $envelope): mixed
    {
        self::assertEnvelopeShape($envelope);

        return Avro::unserialize($envelope['blob']);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{codec: string, blob: string}
     */
    public static function canonicalEnvelope(array $envelope): array
    {
        return self::envelope(self::decode($envelope));
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{codec: string, blob: string}
     */
    public static function canonicalMapEnvelope(array $envelope): array
    {
        $map = self::decode($envelope);

        if ($map instanceof AvroMapValue) {
            return self::envelope($map);
        }

        if (! is_array($map) || array_is_list($map)) {
            throw new InvalidArgumentException(
                'invalid_memo_entries_payload: the Avro memo payload must decode to a string-keyed map.',
            );
        }

        return self::mapEnvelope($map);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    public static function decodeEntries(array $envelope): array
    {
        $entries = self::decode($envelope);

        if ($entries instanceof AvroMapValue) {
            $decoded = [];

            foreach ($entries->pairs as [$key, $value]) {
                if (! self::isValidKey($key)) {
                    throw new InvalidArgumentException(sprintf(
                        'invalid_memo_key: memo key "%s" does not match the portable key pattern %s.',
                        $key,
                        self::KEY_PATTERN,
                    ));
                }

                $decoded[$key] = $value;
            }

            return $decoded;
        }

        if (! is_array($entries) || array_is_list($entries)) {
            throw new InvalidArgumentException(
                'invalid_memo_entries_payload: the Avro memo payload must decode to a string-keyed map.',
            );
        }

        return $entries;
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/' . self::KEY_PATTERN . '/D', $key) === 1;
    }

    public static function encodedSize(mixed $value): int
    {
        return self::encodedEnvelopeSize(self::envelope($value));
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function encodedEnvelopeSize(array $envelope): int
    {
        self::assertEnvelopeShape($envelope);
        $bytes = base64_decode($envelope['blob'], true);

        if ($bytes === false) {
            throw new InvalidArgumentException('invalid_memo_payload: the Avro envelope blob must be strict base64.');
        }

        return strlen($bytes);
    }

    /**
     * Return the canonical Avro bytes used by structural-size guards.
     */
    public static function encodedBytes(mixed $value): string
    {
        $blob = self::envelope($value)['blob'];
        $bytes = base64_decode($blob, true);

        if ($bytes === false) {
            throw new InvalidArgumentException('invalid_memo_payload: the Avro envelope blob must be strict base64.');
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function encodedMapBytes(array $value): string
    {
        $blob = self::mapEnvelope($value)['blob'];
        $bytes = base64_decode($blob, true);

        if ($bytes === false) {
            throw new InvalidArgumentException('invalid_memo_payload: the Avro envelope blob must be strict base64.');
        }

        return $bytes;
    }

    /**
     * Whether a JSON object is the standard inline payload envelope shape.
     *
     * @param array<string, mixed> $value
     */
    public static function isInlineEnvelope(array $value): bool
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys === ['blob', 'codec'];
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof AvroBinaryValue) {
            return $value;
        }

        if ($value instanceof AvroMapValue) {
            $pairs = array_map(
                static fn (array $pair): array => [$pair[0], self::canonicalValue($pair[1])],
                $value->pairs,
            );
            usort($pairs, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

            return AvroMapValue::fromPairs($pairs);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalValue(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::canonicalValue(...), $value);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function assertEnvelopeShape(array $envelope): void
    {
        $keys = array_keys($envelope);
        sort($keys);

        if ($keys !== ['blob', 'codec']) {
            throw new InvalidArgumentException(
                'invalid_memo_payload: expected exactly the standard {codec, blob} payload envelope.',
            );
        }

        $codec = $envelope['codec'] ?? null;
        if (! is_string($codec) || CodecRegistry::canonicalize($codec) !== self::CODEC) {
            throw new InvalidArgumentException('unsupported_payload_codec: memo payloads require codec "avro".');
        }

        if (! is_string($envelope['blob'] ?? null) || $envelope['blob'] === '') {
            throw new InvalidArgumentException(
                'invalid_memo_payload: the Avro envelope blob must be a non-empty string.'
            );
        }
    }
}
