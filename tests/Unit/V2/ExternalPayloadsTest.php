<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use Tests\TestCase;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;

final class ExternalPayloadsTest extends TestCase
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

    public function testLargePayloadIsStoredAsReferenceAndResolvedForReplay(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $payload = str_repeat('x', 512);

        $stored = ExternalPayloads::externalize($payload, 'avro', $driver, 32);

        $this->assertNotSame($payload, $stored);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $stored);

        $historyValue = ExternalPayloads::historyValue($stored, 'avro', 'default');

        $this->assertIsArray($historyValue);
        $this->assertSame('avro', $historyValue['codec']);
        $this->assertArrayHasKey('external_storage', $historyValue);
        $this->assertArrayNotHasKey('blob', $historyValue);
        $this->assertSame(ExternalPayloadReference::SCHEMA, $historyValue['external_storage']['schema']);
        $this->assertSame(512, $historyValue['external_storage']['size_bytes']);

        $wireEnvelope = ExternalPayloads::wireEnvelope($stored, 'avro', 'default');

        $this->assertSame($historyValue, $wireEnvelope);
        $this->assertSame($payload, ExternalPayloads::resolveStoredPayload($stored, 'avro', 'default', $driver));
    }

    public function testMissingLocalPayloadIsAnIntegrityFailure(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $reference = ExternalPayloadStorage::store($driver, 'payload-a', 'avro');

        unlink($this->pathFromUri($reference->uri));

        $this->expectException(ExternalPayloadIntegrityException::class);
        $this->expectExceptionMessage('Unable to read external payload');

        ExternalPayloadStorage::fetch($driver, $reference);
    }

    public function testSmallPayloadRemainsInline(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $payload = 'small-payload';

        $this->assertSame($payload, ExternalPayloads::externalize($payload, 'avro', $driver, 1024));
        $this->assertSame([
            'codec' => 'avro',
            'blob' => $payload,
        ], ExternalPayloads::wireEnvelope($payload, 'avro', 'default'));
    }

    public function testStoredReferenceRejectsCodecMismatch(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $stored = ExternalPayloads::externalize(str_repeat('x', 128), 'avro', $driver, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('codec');

        ExternalPayloads::resolveStoredPayload($stored, 'workflow-serializer-y', 'default', $driver);
    }

    public function testStoredReferenceEncodingIsIndependentOfObjectKeyOrder(): void
    {
        $reference = [
            'schema' => ExternalPayloadReference::SCHEMA,
            'uri' => 'file:///payloads/example.avro',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 128,
            'codec' => 'avro',
        ];
        $databaseNormalizedReference = [
            'uri' => $reference['uri'],
            'codec' => $reference['codec'],
            'schema' => $reference['schema'],
            'sha256' => $reference['sha256'],
            'size_bytes' => $reference['size_bytes'],
        ];

        $stored = ExternalPayloads::encodeStoredEnvelope([
            'codec' => 'avro',
            'external_storage' => $reference,
        ]);
        $recovered = ExternalPayloads::encodeStoredEnvelope([
            'external_storage' => $databaseNormalizedReference,
            'codec' => 'avro',
        ]);

        $this->assertSame($stored, $recovered);
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir() . '/dw-external-payloads-test-' . bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function pathFromUri(string $uri): string
    {
        $parts = parse_url($uri);

        return rawurldecode($parts['path'] ?? '');
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
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
