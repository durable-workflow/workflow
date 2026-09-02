<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecDecodeException;

final class AvroInvalidBase64IngressTest extends TestCase
{
    private const INVALID_BASE64 = '%%%';

    public function testNonJsonInvalidBase64IsRejectedWithTypedRemediation(): void
    {
        self::assertFalse(base64_decode(self::INVALID_BASE64, true));

        try {
            Avro::unserialize(self::INVALID_BASE64);
            self::fail('Expected strict Base64 ingress rejection.');
        } catch (CodecDecodeException $exception) {
            self::assertSame('avro', $exception->declaredCodec);
            self::assertStringContainsString('failed to base64-decode', $exception->detail);
            self::assertStringContainsString('strict base64', $exception->remediation);
            self::assertStringContainsString('c301 single-object frame', $exception->remediation);
        }
    }

    public function testPayloadMetadataDiagnosesNonJsonInvalidBase64(): void
    {
        $metadata = Avro::payloadMetadata(self::INVALID_BASE64);

        self::assertSame('base64-avro-single-object', $metadata['encoding']);
        self::assertNull($metadata['framing']);
        self::assertNull($metadata['prefix_hex']);
        self::assertNull($metadata['writer_schema']);
        self::assertNull($metadata['writer_schema_fingerprint']);
        self::assertSame('invalid_base64', $metadata['diagnostic']);
    }
}
