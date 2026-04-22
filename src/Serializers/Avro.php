<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use Throwable;

/**
 * Avro binary codec with optional schema support.
 *
 * When a schema is provided (via the static schema context), payloads are
 * encoded as typed Avro records with full type fidelity (int stays int,
 * float stays float). When no schema is provided, payloads are wrapped
 * in a generic envelope that stores the JSON-encoded value as an Avro
 * string — preserving binary framing while remaining schemaless.
 *
 * Registered as codec name "avro" in {@see CodecRegistry}.
 *
 * @see https://avro.apache.org/docs/current/specification/
 */
final class Avro implements SerializerInterface
{
    /**
     * Stable wire-protocol prefix bytes documented for SDK / export consumers.
     */
    public const PREFIX_GENERIC_WRAPPER = "\x00";

    public const PREFIX_TYPED_SCHEMA = "\x01";

    private const TYPED_SCHEMA_HEADER_BYTES = 4;

    /**
     * Generic wrapper schema for arbitrary payloads.
     *
     * Used when no typed schema is available. Stores the payload as a
     * JSON string inside an Avro record, providing binary framing and
     * schema evolution (the wrapper can be extended with metadata fields
     * without breaking existing payloads).
     */
    private const WRAPPER_SCHEMA = '{"type":"record","name":"Payload","namespace":"durable_workflow","fields":[{"name":"json","type":"string"},{"name":"version","type":"int","default":1}]}';

    private static ?AvroSchema $wrapperSchema = null;

    /**
     * @var AvroSchema|null Typed schema set by the caller for the current encode/decode.
     */
    private static ?AvroSchema $contextSchema = null;

    /**
     * The generic-wrapper schema as canonical JSON.
     *
     * Exposed so that history-export bundles and similar self-describing
     * artifacts can embed the schema needed to decode `0x00`-prefixed
     * Avro payloads offline, without coupling consumers to this class.
     */
    public static function wrapperSchemaJson(): string
    {
        return self::WRAPPER_SCHEMA;
    }

    /**
     * Describe enough framing metadata for offline history-export consumers to
     * decode a stored Avro payload without coupling to this PHP serializer.
     *
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
            'encoding' => 'base64-avro-binary',
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

        $prefix = $bytes[0];
        $metadata['prefix_hex'] = bin2hex($prefix);

        if ($prefix === self::PREFIX_GENERIC_WRAPPER) {
            $metadata['framing'] = 'generic_wrapper';
            $metadata['writer_schema'] = self::WRAPPER_SCHEMA;
            $metadata['writer_schema_fingerprint'] = self::schemaFingerprint(self::WRAPPER_SCHEMA);

            return $metadata;
        }

        if ($prefix === self::PREFIX_TYPED_SCHEMA) {
            $metadata['framing'] = 'typed_schema';

            try {
                $typed = self::readTypedPayload($bytes);
                $metadata['writer_schema'] = $typed['writer_schema_json'];
                $metadata['writer_schema_fingerprint'] = self::schemaFingerprint($typed['writer_schema_json']);
            } catch (CodecDecodeException) {
                $metadata['diagnostic'] = 'typed_schema_missing_writer_schema';
            } catch (Throwable) {
                $metadata['diagnostic'] = 'typed_schema_invalid_writer_schema';
            }

            return $metadata;
        }

        $metadata['diagnostic'] = 'unknown_prefix';

        return $metadata;
    }

    /**
     * Set a typed Avro schema for the next serialize/unserialize call.
     *
     * Call this before serialize() or unserialize() when the workflow or
     * activity type declares an Avro schema. The schema is consumed on
     * the next call and reset to null.
     */
    public static function withSchema(AvroSchema $schema): void
    {
        self::$contextSchema = $schema;
    }

    /**
     * Parse a JSON schema string into an AvroSchema.
     */
    public static function parseSchema(string $json): AvroSchema
    {
        return self::suppressDeprecations(static fn () => AvroSchema::parse($json));
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
        $schema = self::consumeContextSchema();

        if ($schema !== null) {
            return self::encodeWithSchema($data, $schema);
        }

        return self::encodeWrapped($data);
    }

    public static function unserialize(string $data)
    {
        if ($data === '') {
            return null;
        }

        $schema = self::consumeContextSchema();

        if ($schema !== null) {
            return self::decodeWithSchema($data, $schema);
        }

        return self::decodeWrapped($data);
    }

    /**
     * Encode a value using a typed Avro schema.
     *
     * The value must match the schema (e.g., a record schema expects an
     * associative array with the declared fields).
     */
    private static function encodeWithSchema(mixed $data, AvroSchema $schema): string
    {
        return self::suppressDeprecations(static function () use ($data, $schema): string {
            $schemaJson = self::schemaJson($schema);
            $io = new AvroStringIO();
            $writer = new AvroIODatumWriter($schema);
            $encoder = new AvroIOBinaryEncoder($io);

            // Prefix: 0x01 = typed schema mode. The writer schema follows so
            // exported payloads are self-describing for offline consumers.
            $io->write(self::PREFIX_TYPED_SCHEMA);
            $io->write(pack('N', strlen($schemaJson)));
            $io->write($schemaJson);
            $writer->write($data, $encoder);

            return base64_encode($io->string());
        });
    }

    /**
     * Decode a value using a typed Avro schema.
     */
    private static function decodeWithSchema(string $data, AvroSchema $schema): mixed
    {
        return self::suppressDeprecations(static function () use ($data, $schema): mixed {
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                self::failWithIngressDiagnosis($data);
            }

            $prefix = $bytes[0] ?? '';
            if ($prefix !== self::PREFIX_TYPED_SCHEMA) {
                $schemaName = method_exists($schema, 'fullname') ? $schema->fullname() : null;
                throw new CodecDecodeException(
                    'avro',
                    sprintf(
                        'Expected typed Avro payload (prefix 0x01) for schema "%s", got prefix 0x%s.',
                        $schemaName ?: 'inline',
                        bin2hex($prefix),
                    ),
                    'Re-encode the payload with the typed Avro path against the matching writer schema, or change the codec tag to match the bytes you are sending.',
                );
            }

            try {
                $typed = self::readTypedPayload($bytes, $schema);
                $reader = new AvroIODatumReader($typed['writer_schema'], $schema);
                $io = new AvroStringIO($typed['datum_bytes']);
                $decoder = new AvroIOBinaryDecoder($io);

                return $reader->read($decoder);
            } catch (CodecDecodeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $schemaName = method_exists($schema, 'fullname') ? $schema->fullname() : null;
                throw new CodecDecodeException(
                    'avro',
                    sprintf(
                        'Avro datum reader failed against schema "%s": %s',
                        $schemaName ?: 'inline',
                        $e->getMessage(),
                    ),
                    'Verify the writer schema matches the bytes (resolution: writer→reader compatibility per Avro spec). If you intended a different schema, supply it via Avro::withSchema() before decoding.',
                    $e,
                );
            }
        });
    }

    /**
     * Encode an arbitrary value using the generic wrapper schema.
     */
    private static function encodeWrapped(mixed $data): string
    {
        return self::suppressDeprecations(static function () use ($data): string {
            $schema = self::wrapperSchema();
            $io = new AvroStringIO();
            $writer = new AvroIODatumWriter($schema);
            $encoder = new AvroIOBinaryEncoder($io);

            // Prefix: 0x00 = generic wrapper mode
            $io->write("\x00");
            $writer->write([
                'json' => json_encode(
                    $data,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                ),
                'version' => 1,
            ], $encoder);

            return base64_encode($io->string());
        });
    }

    /**
     * Decode a value from the generic wrapper schema.
     */
    private static function decodeWrapped(string $data): mixed
    {
        return self::suppressDeprecations(static function () use ($data): mixed {
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                self::failWithIngressDiagnosis($data);
            }

            $io = new AvroStringIO($bytes);

            // Read prefix
            $prefix = $io->read(1);
            if ($prefix === self::PREFIX_TYPED_SCHEMA) {
                try {
                    $typed = self::readTypedPayload($bytes);
                    $reader = new AvroIODatumReader($typed['writer_schema']);
                    $decoder = new AvroIOBinaryDecoder(new AvroStringIO($typed['datum_bytes']));

                    return $reader->read($decoder);
                } catch (CodecDecodeException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new CodecDecodeException(
                        'avro',
                        'Typed Avro payload decode failed: ' . $e->getMessage(),
                        'Verify the embedded writer schema matches the bytes. If you need schema evolution, supply the reader schema via Avro::withSchema() before decoding.',
                        $e,
                    );
                }
            }
            if ($prefix !== self::PREFIX_GENERIC_WRAPPER) {
                throw new CodecDecodeException(
                    'avro',
                    sprintf(
                        'Unknown Avro payload prefix: 0x%s (expected 0x00 generic wrapper or 0x01 typed schema).',
                        bin2hex($prefix),
                    ),
                    'These bytes were not produced by Workflow\\Serializers\\Avro::serialize(). Re-encode the payload with the Avro codec, or change the codec tag if the producer used a different codec.',
                );
            }

            try {
                $schema = self::wrapperSchema();
                $reader = new AvroIODatumReader($schema);
                $decoder = new AvroIOBinaryDecoder($io);
                $record = $reader->read($decoder);

                return json_decode($record['json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (CodecDecodeException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new CodecDecodeException(
                    'avro',
                    'Generic Avro wrapper decode failed: ' . $e->getMessage(),
                    'Re-encode the payload with the Avro codec (generic wrapper produces a JSON-string-inside-Avro envelope), or change the codec tag if the producer used a different codec.',
                    $e,
                );
            }
        });
    }

    /**
     * Diagnose why the bytes labeled as Avro could not be base64-decoded
     * and throw a {@see CodecDecodeException} with the most actionable hint.
     *
     * The most common ingress mistakes are:
     *  - A producer JSON-encoded the payload but tagged it `avro`. The bytes
     *    will start with a JSON character ({, [, ", -, digit, t, f, n) and
     *    base64_decode() in strict mode rejects them outright.
     *  - A producer sent raw binary Avro bytes without base64-encoding them.
     */
    private static function failWithIngressDiagnosis(string $data): never
    {
        if (self::looksLikeJson($data)) {
            throw new CodecDecodeException(
                'avro',
                'Payload bytes look like JSON, not base64-encoded Avro.',
                'The producer appears to have JSON-encoded the payload but tagged it with codec "avro". Final v2 does not register a JSON payload codec; re-encode the payload with Workflow\\Serializers\\Avro::serialize() before tagging it "avro".',
            );
        }

        throw new CodecDecodeException(
            'avro',
            'Failed to base64-decode Avro payload bytes.',
            'Avro payloads on the wire must be base64-encoded bytes whose first byte is 0x00 (generic wrapper) or 0x01 (typed schema). Re-encode the payload, or change the codec tag if the producer used a different codec.',
        );
    }

    private static function looksLikeJson(string $data): bool
    {
        if ($data === '') {
            return false;
        }

        $first = $data[0];
        if ($first === '{' || $first === '[' || $first === '"') {
            return true;
        }
        if ($first === '-' || ($first >= '0' && $first <= '9')) {
            return true;
        }

        return in_array($data, ['true', 'false', 'null'], true);
    }

    private static function wrapperSchema(): AvroSchema
    {
        if (self::$wrapperSchema === null) {
            self::$wrapperSchema = self::suppressDeprecations(static fn () => AvroSchema::parse(self::WRAPPER_SCHEMA));
        }

        return self::$wrapperSchema;
    }

    /**
     * @return array{writer_schema: AvroSchema, writer_schema_json: string, datum_bytes: string}
     */
    private static function readTypedPayload(string $bytes, ?AvroSchema $fallbackWriterSchema = null): array
    {
        if (($bytes[0] ?? '') !== self::PREFIX_TYPED_SCHEMA) {
            throw new CodecDecodeException(
                'avro',
                'Expected typed Avro payload (prefix 0x01).',
                'Re-encode the payload with the typed Avro path, or decode it as a generic Avro wrapper if it starts with 0x00.',
            );
        }

        $body = substr($bytes, 1);
        if (strlen($body) >= self::TYPED_SCHEMA_HEADER_BYTES) {
            $schemaLength = unpack('N', substr($body, 0, self::TYPED_SCHEMA_HEADER_BYTES))[1];
            $schemaStart = self::TYPED_SCHEMA_HEADER_BYTES;
            $datumStart = $schemaStart + $schemaLength;

            if ($schemaLength > 0 && strlen($body) >= $datumStart) {
                $schemaJson = substr($body, $schemaStart, $schemaLength);

                return [
                    'writer_schema' => self::parseSchema($schemaJson),
                    'writer_schema_json' => $schemaJson,
                    'datum_bytes' => substr($body, $datumStart),
                ];
            }
        }

        if ($fallbackWriterSchema instanceof AvroSchema) {
            return [
                'writer_schema' => $fallbackWriterSchema,
                'writer_schema_json' => self::schemaJson($fallbackWriterSchema),
                'datum_bytes' => $body,
            ];
        }

        throw new CodecDecodeException(
            'avro',
            'Typed Avro payload (prefix 0x01) does not include an embedded writer schema.',
            'Re-encode the payload with the current typed Avro path so the writer schema is embedded, or call Avro::withSchema($writerSchema) before decoding legacy development data.',
        );
    }

    private static function schemaJson(AvroSchema $schema): string
    {
        $json = json_encode(
            $schema->toAvro(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return is_string($json) ? $json : (string) $schema;
    }

    private static function schemaFingerprint(string $schemaJson): string
    {
        return 'sha256:' . hash('sha256', $schemaJson);
    }

    private static function consumeContextSchema(): ?AvroSchema
    {
        $schema = self::$contextSchema;
        self::$contextSchema = null;

        return $schema;
    }

    /**
     * Suppress PHP 8.4 deprecation warnings from apache/avro's (double) casts.
     */
    private static function suppressDeprecations(callable $fn): mixed
    {
        set_error_handler(static fn () => true, E_DEPRECATED);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
