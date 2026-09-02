<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecDecodeException;
use Workflow\Serializers\Json;
use Workflow\Serializers\Serializer;

/**
 * Loud, typed ingress failures for codec/bytes mismatch.
 *
 * Negative-case acceptance criteria:
 *  - JSON bytes labeled as `avro` produce a typed error naming the codec
 *    and a remediation hint, not a generic RuntimeException.
 *  - The legacy untagged JSON helper rejects Avro bytes loudly.
 *  - The exception identifies the declared codec so cross-component
 *    error reporting can surface it without re-parsing the message.
 */
final class CodecMismatchIngressTest extends NonDatabaseTestCase
{
    public function testJsonBytesUnderAvroCodecAreRejectedLoudlyWithJsonHint(): void
    {
        $jsonBytes = '{"order_id":"abc-123","amount":42.5}';

        try {
            Avro::unserialize($jsonBytes);
            $this->fail('Expected CodecDecodeException for JSON bytes labeled as avro');
        } catch (CodecDecodeException $e) {
            $this->assertSame('avro', $e->declaredCodec);
            $this->assertStringContainsString('look like JSON', $e->detail);
            $this->assertStringContainsString('fixed Avro Value codec', $e->remediation);
            $this->assertStringContainsString('JSON is only the HTTP document transport', $e->remediation);
        }
    }

    public function testJsonArrayUnderAvroCodecIsRejectedLoudly(): void
    {
        $jsonBytes = '["a","b",42]';

        try {
            Avro::unserialize($jsonBytes);
            $this->fail('Expected CodecDecodeException for JSON array labeled as avro');
        } catch (CodecDecodeException $e) {
            $this->assertSame('avro', $e->declaredCodec);
            $this->assertStringContainsString('look like JSON', $e->detail);
        }
    }

    public function testLegacyJsonHelperRejectsAvroBytesLoudlyWithAvroHint(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        // Encode something with Avro single-object framing.
        $avroBytes = Avro::serialize(['hello', 123]);

        $this->assertSame(
            Avro::SINGLE_OBJECT_MAGIC,
            substr((string) base64_decode($avroBytes, true), 0, 2),
            'Avro Value should decode to bytes starting with c301 single-object magic.'
        );

        try {
            Json::unserialize($avroBytes);
            $this->fail('Expected CodecDecodeException for Avro bytes labeled as json');
        } catch (CodecDecodeException $e) {
            $this->assertSame('json', $e->declaredCodec);
            $this->assertStringContainsString('base64-encoded Avro', $e->detail);
            $this->assertStringContainsString('"avro"', $e->remediation);
        }
    }

    public function testGenericJsonDecodeFailureNamesJsonCodecAndRemediation(): void
    {
        try {
            Json::unserialize('{not-valid-json');
            $this->fail('Expected CodecDecodeException for malformed JSON');
        } catch (CodecDecodeException $e) {
            $this->assertSame('json', $e->declaredCodec);
            $this->assertStringContainsString('JSON-decode', $e->detail);
            $this->assertStringContainsString('RFC 8259', $e->remediation);
        }
    }

    public function testAvroPrefixedNonAvroBytesAreRejectedLoudly(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        // Base64-encode a single byte 0x05 — pure base64 ("BQ=="), but not
        // valid Avro single-object framing.
        $bogus = base64_encode("\x05");

        try {
            Avro::unserialize($bogus);
            $this->fail('Expected CodecDecodeException for unknown Avro prefix');
        } catch (CodecDecodeException $e) {
            $this->assertSame('avro', $e->declaredCodec);
            $this->assertStringContainsString('invalid_payload_framing', $e->detail);
            $this->assertStringContainsString('c301', $e->detail);
            $this->assertStringContainsString('fixed Avro Value schema', $e->remediation);
        }
    }

    public function testAvroValueCarriesCanonicalSingleObjectFingerprint(): void
    {
        $typedBlob = Avro::serialize([
            'id' => 'X-1',
        ]);

        $this->assertSame(
            Avro::SINGLE_OBJECT_MAGIC . Avro::VALUE_SCHEMA_FINGERPRINT,
            substr((string) base64_decode($typedBlob, true), 0, 10),
            'Avro Value should use the canonical single-object frame.'
        );

        $metadata = Avro::payloadMetadata($typedBlob);

        $this->assertSame('single_object', $metadata['framing']);
        $this->assertSame('c301', $metadata['prefix_hex']);
        $this->assertSame(Avro::VALUE_SCHEMA_JSON, $metadata['writer_schema']);
        $this->assertSame(
            'crc64-avro:' . Avro::VALUE_SCHEMA_FINGERPRINT_HEX,
            $metadata['writer_schema_fingerprint'],
        );
        $this->assertNull($metadata['diagnostic']);

        $decoded = Avro::unserialize($typedBlob);

        $this->assertSame([
            'id' => 'X-1',
        ], $decoded);
    }

    public function testSerializerWrapperPropagatesTypedExceptionForCodecMismatch(): void
    {
        // unserializeWithCodec is the public ingress API used by the worker
        // protocol and HTTP handlers — a typed exception must surface here too.
        try {
            Serializer::unserializeWithCodec('avro', '{"x":1}');
            $this->fail('Expected CodecDecodeException to propagate through Serializer::unserializeWithCodec');
        } catch (CodecDecodeException $e) {
            $this->assertSame('avro', $e->declaredCodec);
            $this->assertStringContainsString('look like JSON', $e->detail);
        }
    }
}
