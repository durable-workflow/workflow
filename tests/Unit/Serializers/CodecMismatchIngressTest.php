<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use Tests\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecDecodeException;
use Workflow\Serializers\Json;
use Workflow\Serializers\Serializer;

/**
 * Loud, typed ingress failures for codec/bytes mismatch.
 *
 * Covers the negative-case acceptance criteria from
 * https://github.com/zorporation/durable-workflow/issues/362:
 *  - JSON bytes labeled as `avro` produce a typed error naming the codec
 *    and a remediation hint, not a generic RuntimeException.
 *  - Avro bytes labeled as `json` produce a typed error naming the codec
 *    and a remediation hint, not a generic RuntimeException.
 *  - The exception identifies the declared codec so cross-component
 *    error reporting can surface it without re-parsing the message.
 */
final class CodecMismatchIngressTest extends TestCase
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
            $this->assertStringContainsString('codec tag', $e->remediation);
            $this->assertStringContainsString('"json"', $e->remediation);
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

    public function testAvroBytesUnderJsonCodecAreRejectedLoudlyWithAvroHint(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        // Encode something with Avro (generic wrapper, prefix 0x00).
        // base64 leading char is always 'A' for prefix bytes 0x00/0x01,
        // but the second char varies with the encoded JSON length.
        $avroBytes = Avro::serialize(['hello', 123]);

        $this->assertStringStartsWith(
            'A',
            $avroBytes,
            'Avro generic wrapper bytes (prefix 0x00) base64-encode with first char "A".'
        );
        $this->assertSame(
            "\x00",
            base64_decode($avroBytes, true)[0] ?? '?',
            'Avro generic wrapper should decode to bytes starting with 0x00.'
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
        // a valid Avro framing prefix (must be 0x00 or 0x01).
        $bogus = base64_encode("\x05");

        try {
            Avro::unserialize($bogus);
            $this->fail('Expected CodecDecodeException for unknown Avro prefix');
        } catch (CodecDecodeException $e) {
            $this->assertSame('avro', $e->declaredCodec);
            $this->assertStringContainsString('Unknown Avro payload prefix', $e->detail);
            $this->assertStringContainsString('0x05', $e->detail);
            $this->assertStringContainsString('codec tag', $e->remediation);
        }
    }

    public function testTypedAvroDecodedWithoutSchemaIsRejectedLoudly(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $schema = Avro::parseSchema('{"type":"record","name":"Order","fields":[{"name":"id","type":"string"}]}');
        Avro::withSchema($schema);
        $typedBlob = Avro::serialize([
            'id' => 'X-1',
        ]);

        $this->assertSame(
            "\x01",
            base64_decode($typedBlob, true)[0] ?? '?',
            'Typed Avro should decode to bytes starting with 0x01.'
        );

        try {
            Avro::unserialize($typedBlob); // No schema context → wrapped path.
            $this->fail('Expected CodecDecodeException when decoding typed Avro without a schema context');
        } catch (CodecDecodeException $e) {
            $this->assertSame('avro', $e->declaredCodec);
            $this->assertStringContainsString('schema context', $e->detail);
            $this->assertStringContainsString('Avro::withSchema', $e->remediation);
        }
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
