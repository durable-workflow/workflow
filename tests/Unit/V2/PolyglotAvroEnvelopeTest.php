<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecDecodeException;

final class PolyglotAvroEnvelopeTest extends TestCase
{
    public function testEnvelopeRoundTripsJsonNativeValues(): void
    {
        $values = [
            null,
            true,
            false,
            0,
            42,
            -7,
            3.14,
            'polyglot',
            'hello',
            ['a' => 1, 'b' => 'two', 'c' => [1, 2, 3]],
            [1, 'two', 3.0, null, true, ['nested' => 'map']],
        ];

        foreach ($values as $value) {
            $envelope = Avro::envelope($value);

            $this->assertSame('avro', $envelope['codec']);
            $this->assertIsString($envelope['blob']);
            $this->assertEquals($value, Avro::decodeEnvelope($envelope));
        }
    }

    public function testEnvelopeBlobUsesGenericWrapperPrefix(): void
    {
        $envelope = Avro::envelope(['polyglot' => true]);
        $raw = base64_decode($envelope['blob'], strict: true);

        $this->assertNotFalse($raw);
        $this->assertNotEmpty($raw);
        $this->assertSame(Avro::PREFIX_GENERIC_WRAPPER, $raw[0]);
    }

    public function testDecodeEnvelopeAcceptsRawBlobWhenCodecIsKnownFromTask(): void
    {
        $envelope = Avro::envelope(['runtime' => 'python', 'length' => 8]);

        $this->assertSame(
            ['runtime' => 'python', 'length' => 8],
            Avro::decodeEnvelope($envelope['blob']),
        );
    }

    public function testDecodeEnvelopeRejectsEngineSpecificCodec(): void
    {
        $this->expectException(CodecDecodeException::class);
        $this->expectExceptionMessage('language-neutral Avro envelopes');

        Avro::decodeEnvelope([
            'codec' => 'workflow-serializer-y',
            'blob' => 'irrelevant',
        ]);
    }
}
