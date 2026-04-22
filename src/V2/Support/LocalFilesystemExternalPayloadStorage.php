<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

final class LocalFilesystemExternalPayloadStorage implements ExternalPayloadStorageDriver
{
    private string $root;

    public function __construct(string $root)
    {
        $resolved = realpath($root);

        if ($resolved === false) {
            if (! mkdir($root, 0775, true) && ! is_dir($root)) {
                throw new RuntimeException(sprintf('Unable to create external payload storage root [%s].', $root));
            }

            $resolved = realpath($root);
        }

        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException(sprintf(
                'External payload storage root [%s] is not a directory.',
                $root
            ));
        }

        $this->root = rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        $this->validateSha256($sha256);
        $codecSegment = $this->safeCodecSegment($codec);
        $path = $this->root . DIRECTORY_SEPARATOR . $codecSegment . DIRECTORY_SEPARATOR . substr(
            $sha256,
            0,
            2
        ) . DIRECTORY_SEPARATOR . $sha256;
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create external payload directory [%s].', $directory));
        }

        if (! is_file($path) && file_put_contents($path, $data, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write external payload [%s].', $path));
        }

        return self::pathToFileUri($path);
    }

    public function get(string $uri): string
    {
        $path = $this->pathFromUri($uri);
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Unable to read external payload [%s].', $uri));
        }

        $data = file_get_contents($path);

        if ($data === false) {
            throw new RuntimeException(sprintf('Unable to read external payload [%s].', $uri));
        }

        return $data;
    }

    public function delete(string $uri): void
    {
        $path = $this->pathFromUri($uri);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function pathFromUri(string $uri): string
    {
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? null) !== 'file') {
            throw new InvalidArgumentException('Local external storage can only read file:// URIs.');
        }

        $host = $parts['host'] ?? '';
        if ($host !== '' && $host !== 'localhost') {
            throw new InvalidArgumentException('Local external storage can only read file://localhost URIs.');
        }

        $path = rawurldecode($parts['path'] ?? '');
        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            if ($parent !== false && str_starts_with($parent, $this->root . DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }

        if ($path === '' || $resolved === false || ! str_starts_with($resolved, $this->root . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('External payload URI is outside the local storage root.');
        }

        return $resolved;
    }

    private static function pathToFileUri(string $path): string
    {
        return 'file://' . implode('/', array_map('rawurlencode', explode(DIRECTORY_SEPARATOR, $path)));
    }

    private function validateSha256(string $sha256): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/i', $sha256)) {
            throw new InvalidArgumentException('sha256 must be a hex digest.');
        }
    }

    private function safeCodecSegment(string $codec): string
    {
        if (! preg_match('/\A[A-Za-z0-9_.-]+\z/', $codec)) {
            throw new InvalidArgumentException('Codec contains characters that are unsafe for local storage paths.');
        }

        return $codec;
    }
}
