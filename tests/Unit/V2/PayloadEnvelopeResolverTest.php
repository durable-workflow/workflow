<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\PayloadEnvelopeResolver;

final class PayloadEnvelopeResolverTest extends TestCase
{
    private ?string $storageRoot = null;

    protected function tearDown(): void
    {
        if ($this->storageRoot !== null) {
            $this->removeDirectory($this->storageRoot);
            $this->storageRoot = null;
        }

        parent::tearDown();
    }

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

    public function testResolveToArrayDecodesExternalPayloadReference(): void
    {
        if (! class_exists(\Apache\Avro\Schema\AvroSchema::class)) {
            $this->markTestSkipped('apache/avro package is not installed in this environment.');
        }

        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $blob = Serializer::serializeWithCodec('avro', ['external', 7]);
        $reference = ExternalPayloadStorage::store($driver, $blob, 'avro');

        $this->assertSame(
            ['external', 7],
            PayloadEnvelopeResolver::resolveToArray([
                'codec' => 'avro',
                'external_storage' => $reference->toArray(),
            ], externalStorage: $driver),
        );
    }

    public function testResolveExternalPayloadRequiresStorageDriver(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('External payload references require an external storage driver');

        PayloadEnvelopeResolver::resolve([
            'codec' => 'avro',
            'external_storage' => [
                'schema' => 'durable-workflow.v2.external-payload-reference.v1',
                'uri' => 'file:///tmp/payload',
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 12,
                'codec' => 'avro',
            ],
        ]);
    }

    public function testResolveExternalPayloadRejectsCodecMismatch(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');
        $payload = $reference->toArray();
        $payload['codec'] = 'workflow-serializer-y';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('codec must match');

        PayloadEnvelopeResolver::resolve([
            'codec' => 'avro',
            'external_storage' => $payload,
        ], externalStorage: $driver);
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir() . '/dw-envelope-test-' . bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
