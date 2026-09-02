<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use InvalidArgumentException;
use Throwable;
use UnderflowException;

/**
 * Fixed, language-neutral Avro Value codec.
 *
 * Every payload uses Avro single-object encoding with the immutable
 * durable_workflow.protocol.Value schema. Domain-specific schemas and a live
 * schema registry are deliberately not part of the protocol.
 */
final class Avro implements SerializerInterface
{
    public const SINGLE_OBJECT_MAGIC = "\xC3\x01";

    public const VALUE_SCHEMA_FINGERPRINT_HEX = 'e2a33dff55802237';

    public const VALUE_SCHEMA_FINGERPRINT = "\xE2\xA3\x3D\xFF\x55\x80\x22\x37";

    public const VALUE_SCHEMA_JSON = '{"type":"record","name":"Value","namespace":"durable_workflow.protocol","fields":[{"name":"value","type":["null",{"type":"record","name":"BooleanValue","fields":[{"name":"boolean","type":"boolean"}]},{"type":"record","name":"LongValue","fields":[{"name":"long","type":"long"}]},{"type":"record","name":"DoubleValue","fields":[{"name":"double","type":"double"}]},{"type":"record","name":"BytesValue","fields":[{"name":"bytes","type":"bytes"}]},{"type":"record","name":"StringValue","fields":[{"name":"string","type":"string"}]},{"type":"record","name":"ArrayValue","fields":[{"name":"items","type":{"type":"array","items":"Value"}}]},{"type":"record","name":"MapValue","fields":[{"name":"entries","type":{"type":"map","values":"Value"}}]}]}]}';

    private static ?AvroSchema $valueSchema = null;

    public static function valueSchemaJson(): string
    {
        return self::VALUE_SCHEMA_JSON;
    }

    public static function valueSchemaFingerprint(): string
    {
        return self::VALUE_SCHEMA_FINGERPRINT_HEX;
    }

    public static function parseSchema(string $json): AvroSchema
    {
        return self::suppressApacheDeprecations(static fn (): AvroSchema => AvroSchema::parse($json));
    }

    /**
     * @return array{
     *     encoding: string,
     *     framing: string|null,
     *     prefix_hex: string|null,
     *     writer_schema: string|null,
     *     writer_schema_fingerprint: string|null,
     *     diagnostic: string|null
     * }
     */
    public static function payloadMetadata(string $data): array
    {
        $metadata = [
            'encoding' => 'base64-avro-single-object',
            'framing' => null,
            'prefix_hex' => null,
            'writer_schema' => null,
            'writer_schema_fingerprint' => null,
            'diagnostic' => null,
        ];

        $bytes = base64_decode($data, true);
        if ($bytes === false) {
            $metadata['diagnostic'] = self::looksLikeJson($data)
                ? 'json_bytes_labeled_avro'
                : 'invalid_base64';

            return $metadata;
        }

        if ($bytes === '') {
            $metadata['diagnostic'] = 'empty_payload';

            return $metadata;
        }

        $metadata['prefix_hex'] = bin2hex(substr($bytes, 0, 2));
        if (! str_starts_with($bytes, self::SINGLE_OBJECT_MAGIC)) {
            $metadata['diagnostic'] = 'invalid_single_object_magic';

            return $metadata;
        }

        $metadata['framing'] = 'single_object';
        $fingerprint = substr($bytes, 2, 8);
        $metadata['writer_schema_fingerprint'] = 'crc64-avro:' . bin2hex($fingerprint);

        if ($fingerprint === self::VALUE_SCHEMA_FINGERPRINT) {
            $metadata['writer_schema'] = self::VALUE_SCHEMA_JSON;
        } else {
            $metadata['diagnostic'] = 'unsupported_payload_schema';
        }

        return $metadata;
    }

    public static function encode(string $data): string
    {
        return $data;
    }

    public static function decode(string $data): string
    {
        return $data;
    }

    public static function serialize($data): string
    {
        try {
            return self::suppressApacheDeprecations(static function () use ($data): string {
                $io = new AvroStringIO();
                $io->write(self::SINGLE_OBJECT_MAGIC . self::VALUE_SCHEMA_FINGERPRINT);
                (new ValueDatumWriter())->write(self::toDatum($data), new AvroIOBinaryEncoder($io));

                return base64_encode($io->string());
            });
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'avro_value_encode_failed: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    public static function unserialize(string $data)
    {
        $bytes = base64_decode($data, true);
        if ($bytes === false) {
            self::failWithIngressDiagnosis($data);
        }
        if (strlen($bytes) < 10 || ! str_starts_with($bytes, self::SINGLE_OBJECT_MAGIC)) {
            throw new CodecDecodeException(
                'avro',
                'invalid_payload_framing: expected Avro single-object magic c301.',
                'Encode the value with the Durable Workflow 2.0 fixed Avro Value schema and single-object framing; JSON is only the HTTP document transport.',
            );
        }

        $fingerprint = substr($bytes, 2, 8);
        $writerSchema = self::schemaForFingerprint($fingerprint);

        try {
            return self::suppressApacheDeprecations(static function () use ($bytes, $writerSchema): mixed {
                $payloadIo = new AvroStringIO(substr($bytes, 10));
                $reader = new ValueDatumReader($writerSchema, self::valueSchema());
                $datum = $reader->read(new ValueDatumDecoder($payloadIo));
                if (! $payloadIo->isEof()) {
                    throw new CodecDecodeException(
                        'avro',
                        'invalid_payload_framing: trailing bytes after Avro Value datum.',
                        'Provide exactly one Avro Value datum in each single-object frame.',
                    );
                }

                return self::fromDatum($datum);
            });
        } catch (CodecDecodeException $exception) {
            throw $exception;
        } catch (UnderflowException $exception) {
            throw new CodecDecodeException(
                'avro',
                'invalid_payload_framing: truncated Avro Value datum.',
                'Provide one complete Avro Value datum after the single-object fingerprint.',
                $exception,
            );
        } catch (Throwable $exception) {
            throw new CodecDecodeException(
                'avro',
                'invalid_payload_framing: malformed Avro Value datum.',
                'Provide one complete datum encoded with the writer schema selected by the single-object fingerprint.',
                $exception,
            );
        }
    }

    /**
     * @return array{codec: string, blob: string}
     */
    public static function envelope(mixed $value): array
    {
        return [
            'codec' => 'avro',
            'blob' => self::serialize($value),
        ];
    }

    /**
     * @param array<string, mixed>|string|null $envelope
     */
    public static function decodeEnvelope(array|string|null $envelope): mixed
    {
        if ($envelope === null) {
            return null;
        }
        if (is_string($envelope)) {
            return self::unserialize($envelope);
        }
        if (($envelope['codec'] ?? 'avro') !== 'avro') {
            throw new CodecDecodeException(
                'avro',
                'Avro envelope declared a different codec.',
                'Use Avro::decodeEnvelope() only with codec="avro" envelopes.',
            );
        }
        if (! isset($envelope['blob']) || ! is_string($envelope['blob'])) {
            throw new CodecDecodeException(
                'avro',
                'Avro envelope is missing a string `blob` field.',
                'Provide a base64 Avro single-object payload in `blob`.',
            );
        }

        return self::unserialize($envelope['blob']);
    }

    /**
     * @return array{value: mixed}
     */
    private static function toDatum(mixed $value): array
    {
        if ($value === null) {
            return [
                'value' => null,
            ];
        }
        if (is_bool($value)) {
            return [
                'value' => [
                    'boolean' => $value,
                ],
            ];
        }
        if (is_int($value)) {
            return [
                'value' => [
                    'long' => $value,
                ],
            ];
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('non_finite_float: Avro Value doubles must be finite.');
            }

            return [
                'value' => [
                    'double' => $value,
                ],
            ];
        }
        if ($value instanceof AvroBinaryValue) {
            return [
                'value' => [
                    'bytes' => $value->bytes,
                ],
            ];
        }
        if ($value instanceof AvroMapValue) {
            return [
                'value' => [
                    'entries' => AvroMapValue::fromPairs(array_map(
                        static fn (array $pair): array => [$pair[0], self::toDatum($pair[1])],
                        $value->pairs,
                    )),
                ],
            ];
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException(
                    'invalid_utf8_string: wrap binary strings with AvroBinaryValue::fromBytes().',
                );
            }

            return [
                'value' => [
                    'string' => $value,
                ],
            ];
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return [
                    'value' => [
                        'items' => array_map(static fn (mixed $item): array => self::toDatum($item), $value),
                    ],
                ];
            }

            $entries = [];
            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException(
                        'invalid_map_key: Avro Value maps require string keys; keys are never stringified.',
                    );
                }
                $entries[] = [$key, self::toDatum($item)];
            }

            return [
                'value' => [
                    'entries' => AvroMapValue::fromPairs($entries),
                ],
            ];
        }

        throw new InvalidArgumentException(sprintf(
            'unsupported_value_type: adapt %s to null, bool, int, finite float, UTF-8 string, AvroBinaryValue, list, or string-keyed map.',
            get_debug_type($value),
        ));
    }

    private static function fromDatum(mixed $datum): mixed
    {
        if (! is_array($datum) || ! array_key_exists('value', $datum)) {
            throw new CodecDecodeException(
                'avro',
                'invalid_payload_framing: datum is not a durable_workflow.protocol.Value record.',
                'Provide a schema-conformant Avro Value datum.',
            );
        }

        $branch = $datum['value'];
        if ($branch === null) {
            return null;
        }
        if (! is_array($branch)) {
            throw new CodecDecodeException(
                'avro',
                'invalid_payload_framing: Value selected an invalid union branch.',
                'Provide a schema-conformant Avro Value datum.',
            );
        }

        foreach (['boolean', 'long', 'double', 'bytes', 'string'] as $field) {
            if (array_key_exists($field, $branch)) {
                return $field === 'bytes'
                    ? AvroBinaryValue::fromBytes((string) $branch[$field])
                    : $branch[$field];
            }
        }
        if (array_key_exists('items', $branch) && is_array($branch['items'])) {
            return array_map(static fn (mixed $item): mixed => self::fromDatum($item), $branch['items']);
        }
        if (array_key_exists('entries', $branch) && is_array($branch['entries'])) {
            $entries = [];
            $pairs = [];
            $requiresAdapter = $branch['entries'] === [];
            foreach ($branch['entries'] as $key => $item) {
                $decoded = self::fromDatum($item);
                $pairs[] = [(string) $key, $decoded];
                if (! is_string($key)) {
                    $requiresAdapter = true;
                } else {
                    $entries[$key] = $decoded;
                }
            }

            return $requiresAdapter
                ? AvroMapValue::fromPairs($pairs)
                : $entries;
        }

        throw new CodecDecodeException(
            'avro',
            'invalid_payload_framing: Value selected an unknown named branch.',
            'Provide a branch defined by the writer schema selected by the single-object fingerprint.',
        );
    }

    private static function schemaForFingerprint(string $fingerprint): AvroSchema
    {
        if ($fingerprint !== self::VALUE_SCHEMA_FINGERPRINT) {
            throw new CodecDecodeException(
                'avro',
                sprintf('unsupported_payload_schema: unknown CRC-64-AVRO fingerprint %s.', bin2hex($fingerprint)),
                'Upgrade to a runtime that bundles this writer schema; never guess or fall back to JSON.',
            );
        }

        return self::valueSchema();
    }

    private static function valueSchema(): AvroSchema
    {
        return self::$valueSchema ??= self::parseSchema(self::VALUE_SCHEMA_JSON);
    }

    private static function failWithIngressDiagnosis(string $data): never
    {
        $looksLikeJson = self::looksLikeJson($data);
        throw new CodecDecodeException(
            'avro',
            $looksLikeJson
                ? 'invalid_payload_framing: payload bytes look like JSON, not base64-encoded Avro.'
                : 'invalid_payload_framing: failed to base64-decode Avro payload bytes.',
            $looksLikeJson
                ? 'Encode the value with the fixed Avro Value codec; JSON is only the HTTP document transport.'
                : 'Avro payloads must be strict base64 containing a c301 single-object frame.',
        );
    }

    private static function looksLikeJson(string $data): bool
    {
        $first = $data[0] ?? '';

        return in_array($first, ['{', '[', '"', '-', 't', 'f', 'n'], true)
            || ($first >= '0' && $first <= '9');
    }

    /**
     * Apache Avro 1.12 emits PHP deprecations for legacy numeric casts. Only
     * E_DEPRECATED is adapted; schema-resolution warnings remain visible.
     */
    private static function suppressApacheDeprecations(callable $operation): mixed
    {
        set_error_handler(static fn (): bool => true, E_DEPRECATED);
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
