<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\PredictableExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloadStorage;
use Workflow\V2\Support\FilesystemExternalPayloadStorage;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;

final class ExternalPayloadStorageTest extends TestCase
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

    public function testFetchCachesVerifiedBytesByReference(): void
    {
        $localDriver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $driver = new class($localDriver) implements ExternalPayloadStorageDriver {
            public int $getCalls = 0;

            public function __construct(
                private readonly ExternalPayloadStorageDriver $inner
            ) {
            }

            public function put(string $data, string $sha256, string $codec): string
            {
                return $this->inner->put($data, $sha256, $codec);
            }

            public function get(string $uri): string
            {
                $this->getCalls++;

                return $this->inner->get($uri);
            }

            public function delete(string $uri): void
            {
                $this->inner->delete($uri);
            }
        };

        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');

        $this->assertSame('encoded-payload', ExternalPayloadStorage::fetch($driver, $reference));
        $this->assertSame('encoded-payload', ExternalPayloadStorage::fetch($driver, $reference));
        $this->assertSame(1, $driver->getCalls);
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

    public function testLocalDriverNeverMovesAnIncompleteOrCorruptWriteIntoPlace(): void
    {
        $root = $this->makeStorageRoot();
        $data = 'encoded-payload';
        $sha256 = hash('sha256', $data);
        $writers = [
            'short write' => static fn (string $path, string $content): int|false => file_put_contents(
                $path,
                substr($content, 0, 5)
            ),
            'short write reported whole' => static function (string $path, string $content): int {
                file_put_contents($path, substr($content, 0, 5));

                return strlen($content);
            },
            'same length, wrong bytes' => static fn (string $path, string $content): int|false => file_put_contents(
                $path,
                strrev($content)
            ),
            'failed write' => static fn (string $path, string $content): int|false => false,
        ];

        foreach ($writers as $case => $writer) {
            $driver = new LocalFilesystemExternalPayloadStorage($root, $writer);
            try {
                $driver->put($data, $sha256, 'avro');
                $this->fail("{$case}: the incomplete write was accepted.");
            } catch (ExternalPayloadIntegrityException $error) {
                $this->assertStringContainsString('was not written completely', $error->getMessage(), $case);
            }
            $path = $this->pathFromFileUri($driver->uriFor($sha256, 'avro'));
            $this->assertFileDoesNotExist($path, $case);
            $this->assertSame([], glob(dirname($path) . '/*.tmp') ?: [], $case);
        }
    }

    public function testLocalDriverKeepsAMatchingObjectAndReplacesAPartialOrCorruptOne(): void
    {
        $root = $this->makeStorageRoot();
        $data = 'encoded-payload';
        $sha256 = hash('sha256', $data);
        $writes = 0;
        $driver = new LocalFilesystemExternalPayloadStorage(
            $root,
            static function (string $path, string $content) use (&$writes): int|false {
                $writes++;

                return file_put_contents($path, $content);
            }
        );

        $uri = $driver->put($data, $sha256, 'avro');
        $this->assertSame($driver->uriFor($sha256, 'avro'), $uri);
        $this->assertSame(1, $writes);
        $path = $this->pathFromFileUri($uri);

        // Idempotent: a matching object is kept as it is.
        $this->assertSame($uri, $driver->put($data, $sha256, 'avro'));
        $this->assertSame(1, $writes);

        foreach (['encoded-pay', 'encoded-payloae'] as $damaged) {
            file_put_contents($path, $damaged);
            $this->assertSame($uri, $driver->put($data, $sha256, 'avro'));
            $this->assertSame($data, file_get_contents($path));
        }
        $this->assertSame(3, $writes);
        $this->assertSame([], glob(dirname($path) . '/*.tmp') ?: []);
    }

    public function testLocalDriverRefusesContentThatDoesNotMatchItsHash(): void
    {
        $driver = new LocalFilesystemExternalPayloadStorage($this->makeStorageRoot());
        $sha256 = hash('sha256', 'other-payload');

        try {
            $driver->put('encoded-payload', $sha256, 'avro');
            $this->fail('Content that does not match its hash was stored.');
        } catch (ExternalPayloadIntegrityException) {
        }

        $this->assertFileDoesNotExist($this->pathFromFileUri($driver->uriFor($sha256, 'avro')));
    }

    public function testOnlyTheLocalDriverPromisesPredictableAtomicStorage(): void
    {
        $root = $this->makeStorageRoot();

        $this->assertInstanceOf(
            PredictableExternalPayloadStorageDriver::class,
            new LocalFilesystemExternalPayloadStorage($root)
        );
        // A filesystem disk does not guarantee an atomic write, so this driver
        // keeps the original interface and stores payloads as before.
        $this->assertNotInstanceOf(
            PredictableExternalPayloadStorageDriver::class,
            new FilesystemExternalPayloadStorage($this->makeDisk($root), 's3', 'workflow-payloads')
        );
    }

    public function testFilesystemDriverStoresFetchesAndDeletesVerifiedBytes(): void
    {
        $driver = new FilesystemExternalPayloadStorage(
            $this->makeDisk($this->makeStorageRoot()),
            's3',
            'workflow-payloads',
            'namespaces/billing',
        );

        $reference = ExternalPayloadStorage::store($driver, 'encoded-payload', 'avro');

        $this->assertStringStartsWith('s3://workflow-payloads/namespaces/billing/avro/', $reference->uri);
        $this->assertSame('encoded-payload', ExternalPayloadStorage::fetch($driver, $reference));

        $driver->delete($reference->uri);

        $this->expectException(\RuntimeException::class);
        $driver->get($reference->uri);
    }

    public function testFilesystemDriverRejectsUrisOutsideConfiguredPrefix(): void
    {
        $driver = new FilesystemExternalPayloadStorage(
            $this->makeDisk($this->makeStorageRoot()),
            'gs',
            'workflow-payloads',
            'namespaces/billing',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the configured storage prefix');

        $driver->get('gs://workflow-payloads/namespaces/other/avro/aa/' . str_repeat('a', 64));
    }

    private function makeStorageRoot(): string
    {
        $this->storageRoot = sys_get_temp_dir() . '/dw-external-payload-test-' . bin2hex(random_bytes(6));

        return $this->storageRoot;
    }

    private function makeDisk(string $root): FilesystemAdapter
    {
        $adapter = new LocalFilesystemAdapter($root);

        return new FilesystemAdapter(new Filesystem($adapter), $adapter, [
            'root' => $root,
        ]);
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
