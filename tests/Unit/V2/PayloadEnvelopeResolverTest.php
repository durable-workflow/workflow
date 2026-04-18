<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\PayloadEnvelopeResolver;

final class PayloadEnvelopeResolverTest extends TestCase
{
    public function testResolveToArrayReturnsEmptyForNullOrEmpty(): void
    {
        $this->assertSame([], PayloadEnvelopeResolver::resolveToArray(null));
        $this->assertSame([], PayloadEnvelopeResolver::resolveToArray([]));
    }

    public function testResolveToArrayReturnsPositionalArrayUnchanged(): void
    {
        $this->assertSame(['alpha', 'beta'], PayloadEnvelopeResolver::resolveToArray(['alpha', 'beta']));
    }

    public function testResolveToArrayDecodesAvroEnvelope(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $envelope = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['a', 'b', 42]),
        ];

        $this->assertSame(['a', 'b', 42], PayloadEnvelopeResolver::resolveToArray($envelope));
    }

    public function testResolveToArrayDecodesLegacyYEnvelope(): void
    {
        $envelope = [
            'codec' => 'workflow-serializer-y',
            'blob' => Serializer::serializeWithCodec('workflow-serializer-y', ['a', 'b']),
        ];

        $this->assertSame(['a', 'b'], PayloadEnvelopeResolver::resolveToArray($envelope));
    }

    public function testResolveToArrayRejectsRemovedJsonCodec(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown payload codec');

        PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'json',
            'blob' => '[]',
        ]);
    }

    public function testResolveToArrayRejectsUnknownCodec(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown payload codec');

        PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'does-not-exist',
            'blob' => 'xxx',
        ]);
    }

    public function testResolveToArrayRejectsNonArrayBlobPayload(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $envelope = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', 'just a string'),
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must decode to an array');

        PayloadEnvelopeResolver::resolveToArray($envelope);
    }

    public function testResolveToArrayRejectsCorruptBlob(): void
    {
        $envelope = [
            'codec' => 'avro',
            'blob' => '{not-valid-avro',
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be decoded with codec "avro"');

        PayloadEnvelopeResolver::resolveToArray($envelope);
    }
}
