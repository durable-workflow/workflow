<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Apache\Avro\Datum\AvroIOBinaryDecoder;
use Apache\Avro\Datum\AvroIOBinaryEncoder;
use Apache\Avro\Datum\AvroIODatumReader;
use Apache\Avro\Datum\AvroIODatumWriter;
use Apache\Avro\IO\AvroStringIO;
use Apache\Avro\Schema\AvroSchema;
use RuntimeException;

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
     * Generic wrapper schema for arbitrary payloads.
     *
     * Used when no typed schema is available. Stores the payload as a
     * JSON string inside an Avro record, providing binary framing and
     * schema evolution (the wrapper can be extended with metadata fields
     * without breaking existing payloads).
     */
    private const WRAPPER_SCHEMA = '{"type":"record","name":"Payload","namespace":"durable_workflow","fields":[{"name":"json","type":"string"},{"name":"version","type":"int","default":1}]}';

    private static ?AvroSchema $wrapperSchema = null;

    /** @var AvroSchema|null Typed schema set by the caller for the current encode/decode. */
    private static ?AvroSchema $contextSchema = null;

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
        return self::suppressDeprecations(fn () => AvroSchema::parse($json));
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
        return self::suppressDeprecations(function () use ($data, $schema): string {
            $io = new AvroStringIO();
            $writer = new AvroIODatumWriter($schema);
            $encoder = new AvroIOBinaryEncoder($io);

            // Prefix: 0x01 = typed schema mode
            $io->write("\x01");
            $writer->write($data, $encoder);

            return base64_encode($io->string());
        });
    }

    /**
     * Decode a value using a typed Avro schema.
     */
    private static function decodeWithSchema(string $data, AvroSchema $schema): mixed
    {
        return self::suppressDeprecations(function () use ($data, $schema): mixed {
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                throw new RuntimeException('Failed to base64-decode Avro payload.');
            }

            $io = new AvroStringIO($bytes);

            // Read and verify prefix
            $prefix = $io->read(1);
            if ($prefix !== "\x01") {
                throw new RuntimeException('Expected typed Avro payload (prefix 0x01), got: 0x' . bin2hex($prefix));
            }

            $reader = new AvroIODatumReader($schema);
            $decoder = new AvroIOBinaryDecoder($io);

            return $reader->read($decoder);
        });
    }

    /**
     * Encode an arbitrary value using the generic wrapper schema.
     */
    private static function encodeWrapped(mixed $data): string
    {
        return self::suppressDeprecations(function () use ($data): string {
            $schema = self::wrapperSchema();
            $io = new AvroStringIO();
            $writer = new AvroIODatumWriter($schema);
            $encoder = new AvroIOBinaryEncoder($io);

            // Prefix: 0x00 = generic wrapper mode
            $io->write("\x00");
            $writer->write([
                'json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
        return self::suppressDeprecations(function () use ($data): mixed {
            $bytes = base64_decode($data, true);
            if ($bytes === false) {
                throw new RuntimeException('Failed to base64-decode Avro payload.');
            }

            $io = new AvroStringIO($bytes);

            // Read prefix
            $prefix = $io->read(1);
            if ($prefix === "\x01") {
                throw new RuntimeException('Typed Avro payload requires a schema context. Call Avro::withSchema() before unserialize().');
            }
            if ($prefix !== "\x00") {
                throw new RuntimeException('Unknown Avro payload prefix: 0x' . bin2hex($prefix));
            }

            $schema = self::wrapperSchema();
            $reader = new AvroIODatumReader($schema);
            $decoder = new AvroIOBinaryDecoder($io);
            $record = $reader->read($decoder);

            return json_decode($record['json'], true, 512, JSON_THROW_ON_ERROR);
        });
    }

    private static function wrapperSchema(): AvroSchema
    {
        if (self::$wrapperSchema === null) {
            self::$wrapperSchema = self::suppressDeprecations(
                fn () => AvroSchema::parse(self::WRAPPER_SCHEMA)
            );
        }

        return self::$wrapperSchema;
    }

    private static function consumeContextSchema(): ?AvroSchema
    {
        $schema = self::$contextSchema;
        self::$contextSchema = null;

        return $schema;
    }

    /**
     * Suppress PHP 8.4 deprecation warnings from apache/avro's (double) casts.
     *
     * @see https://github.com/zorporation/durable-workflow/issues/332
     */
    private static function suppressDeprecations(callable $fn): mixed
    {
        set_error_handler(fn () => true, E_DEPRECATED);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
