<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;

final class ExternalPayloadStorageTest extends TestCase
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

    public function testReferenceRoundTripsStableWireShape(): void
    {
        $reference = ExternalPayloadReference::fromArray([
            'schema' => ExternalPayloadReference::SCHEMA,
            'uri' => 's3://bucket/payloads/ab/hash',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 42,
            'codec' => 'avro',
        ]);

        $this->assertSame([
            'schema' => 'durable-workflow.v2.external-payload-reference.v1',
            'uri' => 's3://bucket/payloads/ab/hash',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 42,
            'codec' => 'avro',
        ], $reference->toArray());
    }

    public function testReferenceRejectsInvalidHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sha256 must be a hex digest');

        ExternalPayloadReference::fromArray([
            'schema' => ExternalPayloadReference::SCHEMA,
            'uri' => 'file:///tmp/payload',
            'sha256' => 'not-a-hash',
            'size_bytes' => 1,
            'codec' => 'avro',
        ]);
    }

    public function testLocalFilesystemDriverStoresFetchesAndDeletesVerifiedBytes(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');

        $this->assertSame('encoded-payload', ExternalPayloadStorage::fetch($driver, $reference));

        $driver->delete($reference->uri);

        $this->expectException(\RuntimeException::class);
        $driver->get($reference->uri);
    }

    public function testFetchRejectsMutatedPayloadBytes(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');

        file_put_contents($this->pathFromFileUri($reference->uri), 'tampered');

        $this->expectException(ExternalPayloadIntegrityException::class);
        $this->expectExceptionMessage('size');

        ExternalPayloadStorage::fetch($driver, $reference);
    }

    public function testFetchRejectsSameSizeMutatedPayloadBytes(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');

        file_put_contents($this->pathFromFileUri($reference->uri), 'encoded-payloae');

        $this->expectException(ExternalPayloadIntegrityException::class);
        $this->expectExceptionMessage('hash');

        ExternalPayloadStorage::fetch($driver, $reference);
    }

    public function testLocalFilesystemDriverRejectsUrisOutsideRoot(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the local storage root');

        $driver->get('file:///etc/passwd');
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir() . '/dw-external-payload-test-' . bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function pathFromFileUri(string $uri): string
    {
        $parts = parse_url($uri);

        return rawurldecode((string) ($parts['path'] ?? ''));
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
