<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\PayloadEnvelopeResolver;

final class PayloadEnvelopeResolverTest extends NonDatabaseTestCase
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

    public function testResolveToArrayRejectsScalarInputWithTheRequestedFieldName(): void
    {
        try {
            PayloadEnvelopeResolver::resolveToArray('not-an-array', 'arguments');
            $this->fail('Expected scalar input to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['The arguments field must be an array or an envelope object.'],
                $exception->errors()['arguments'],
            );
        }
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

    public function testResolveToArrayRejectsJsonEnvelope(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported_payload_codec');

        PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'json',
            'blob' => '["scheduled",{"runtime":"python"}]',
        ]);
    }

    public function testResolveToArrayRejectsLegacyPhpEnvelope(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported_payload_codec');

        PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'workflow-serializer-y',
            'blob' => 'legacy',
        ]);
    }

    public function testResolveToArrayRejectsUnknownCodec(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('unsupported_payload_codec');

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

    public function testResolveCommandPayloadPreservesRawValuesAndExtractsInlineEnvelope(): void
    {
        $blob = Serializer::serializeWithCodec('avro', ['completed']);

        $this->assertNull(PayloadEnvelopeResolver::resolveCommandPayload(null));
        $this->assertSame('raw-payload', PayloadEnvelopeResolver::resolveCommandPayload('raw-payload'));
        $this->assertSame([], PayloadEnvelopeResolver::resolveCommandPayload([]));
        $this->assertSame($blob, PayloadEnvelopeResolver::resolveCommandPayload([
            'codec' => 'avro',
            'blob' => $blob,
        ]));
    }

    public function testResolveCommandPayloadWithCodecReportsOnlyEnvelopeCodec(): void
    {
        $blob = Serializer::serializeWithCodec('avro', ['completed']);

        $this->assertSame(
            [
                'payload' => null,
                'codec' => null,
            ],
            PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(null),
        );
        $this->assertSame(
            [
                'payload' => ['raw'],
                'codec' => null,
            ],
            PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(['raw']),
        );
        $this->assertSame(
            [
                'payload' => $blob,
                'codec' => 'avro',
            ],
            PayloadEnvelopeResolver::resolveCommandPayloadWithCodec([
                'blob' => $blob,
                'codec' => 'avro',
            ]),
        );
    }

    public function testResolveCommandPayloadMethodsFetchExternalEnvelope(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');
        $envelope = [
            'codec' => 'avro',
            'external_storage' => $reference->toArray(),
        ];

        $this->assertSame(
            'encoded-payload',
            PayloadEnvelopeResolver::resolveCommandPayload($envelope, externalStorage: $driver),
        );
        $this->assertSame(
            [
                'payload' => 'encoded-payload',
                'codec' => 'avro',
            ],
            PayloadEnvelopeResolver::resolveCommandPayloadWithCodec($envelope, externalStorage: $driver),
        );
    }

    public function testResolveReturnsEmptyEnvelopeForMissingInput(): void
    {
        $expected = [
            'codec' => null,
            'blob' => null,
        ];

        $this->assertSame($expected, PayloadEnvelopeResolver::resolve(null));
        $this->assertSame($expected, PayloadEnvelopeResolver::resolve([]));
    }

    public function testResolveEncodesPlainArgumentsAsAvro(): void
    {
        $resolved = PayloadEnvelopeResolver::resolve([
            'first' => 'alpha',
            'second' => 7,
        ]);

        $this->assertSame('avro', $resolved['codec']);
        $this->assertIsString($resolved['blob']);
        $this->assertSame(['alpha', 7], Serializer::unserializeWithCodec($resolved['codec'], $resolved['blob']));
    }

    public function testResolveRejectsScalarInput(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be an array or an envelope object');

        PayloadEnvelopeResolver::resolve('not-an-array');
    }

    public function testResolveRejectsEmptyEnvelopeCodec(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('codec must be a non-empty string');

        PayloadEnvelopeResolver::resolve([
            'codec' => '',
            'blob' => 'payload',
        ]);
    }

    public function testResolveRejectsNonStringEnvelopeBlob(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('envelope blob must be a string');

        PayloadEnvelopeResolver::resolve([
            'codec' => 'avro',
            'blob' => ['not', 'bytes'],
        ]);
    }

    public function testResolveRejectsNonObjectExternalReference(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('external payload reference must be an object');

        PayloadEnvelopeResolver::resolve([
            'codec' => 'avro',
            'external_storage' => 'file:///tmp/payload',
        ], externalStorage: $driver);
    }

    public function testResolveReportsInvalidExternalReference(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unsupported external payload reference schema');

        PayloadEnvelopeResolver::resolve([
            'codec' => 'avro',
            'external_storage' => [
                'schema' => 'unknown-schema',
                'uri' => 'file:///tmp/payload',
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 12,
                'codec' => 'avro',
            ],
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
