<?php

declare(strict_types=1);

namespace Tests\Unit\Serializers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\PayloadEnvelopeResolver;

final class ExternalStorageEnvelopeTest extends TestCase
{
    private ?string $storageRoot = null;

    protected function tearDown(): void
    {
        ExternalPayloadStorage::flushVerifiedCache();

        if ($this->storageRoot !== null) {
            $this->removeDirectory($this->storageRoot);
            $this->storageRoot = null;
        }

        parent::tearDown();
    }

    public function testEnvelopeOffloadsLargePayloadToExternalStorage(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $largeValue = [
            'data' => str_repeat('x', 200),
        ];

        $envelope = Serializer::externalStorageEnvelope($largeValue, 'json', $driver, 10);

        $this->assertArrayHasKey('codec', $envelope);
        $this->assertArrayHasKey('external_storage', $envelope);
        $this->assertArrayNotHasKey('blob', $envelope);
        $this->assertSame(ExternalPayloadReference::SCHEMA, $envelope['external_storage']['schema']);
        $this->assertSame($envelope['codec'], $envelope['external_storage']['codec']);
        $this->assertGreaterThan(10, $envelope['external_storage']['size_bytes']);
    }

    public function testEnvelopeKeepsSmallPayloadInline(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $envelope = Serializer::externalStorageEnvelope([
            'ok' => true,
        ], 'json', $driver, 100_000);

        $this->assertArrayHasKey('codec', $envelope);
        $this->assertArrayHasKey('blob', $envelope);
        $this->assertArrayNotHasKey('external_storage', $envelope);
    }

    public function testEnvelopeAtExactThresholdStaysInline(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $value = 'test';
        $blob = Serializer::serializeWithCodec('json', $value);
        $threshold = strlen($blob);

        $envelope = Serializer::externalStorageEnvelope($value, 'json', $driver, $threshold);

        $this->assertArrayHasKey('blob', $envelope);
        $this->assertArrayNotHasKey('external_storage', $envelope);
    }

    public function testEnvelopeOneBytePastThresholdOffloads(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $value = 'test';
        $blob = Serializer::serializeWithCodec('json', $value);
        $threshold = strlen($blob) - 1;

        $envelope = Serializer::externalStorageEnvelope($value, 'json', $driver, $threshold);

        $this->assertArrayHasKey('external_storage', $envelope);
        $this->assertArrayNotHasKey('blob', $envelope);
    }

    public function testEnvelopeCanBeRoundTrippedThroughPayloadEnvelopeResolver(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $value = [
            'nested' => [
                'data' => str_repeat('a', 200),
            ],
        ];

        $envelope = Serializer::externalStorageEnvelope($value, 'json', $driver, 10);

        $this->assertArrayHasKey('external_storage', $envelope);

        $resolved = PayloadEnvelopeResolver::resolve($envelope, 'payload', $driver);
        $decoded = Serializer::unserializeWithCodec($resolved['codec'], $resolved['blob']);

        $this->assertSame($value, $decoded);
    }

    public function testInlineEnvelopeCanBeRoundTrippedThroughPayloadEnvelopeResolver(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $value = [
            'small' => true,
        ];

        $envelope = Serializer::externalStorageEnvelope($value, 'json', $driver, 100_000);

        $this->assertArrayHasKey('blob', $envelope);

        $resolved = PayloadEnvelopeResolver::resolve($envelope, 'payload', $driver);
        $decoded = Serializer::unserializeWithCodec($resolved['codec'], $resolved['blob']);

        $this->assertSame($value, $decoded);
    }

    public function testEnvelopeRejectsZeroThreshold(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('threshold');

        Serializer::externalStorageEnvelope('value', 'json', $driver, 0);
    }

    public function testEnvelopeRejectsNegativeThreshold(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $this->expectException(InvalidArgumentException::class);

        Serializer::externalStorageEnvelope('value', 'json', $driver, -1);
    }

    public function testEnvelopeNormalizesCodecAlias(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $envelope = Serializer::externalStorageEnvelope([
            'x' => 1,
        ], 'avro', $driver, 100_000);

        $this->assertSame('avro', $envelope['codec']);
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir() . '/dw-ext-env-test-' . bin2hex(random_bytes(6));

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
