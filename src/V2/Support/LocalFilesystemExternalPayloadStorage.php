<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Contracts\PredictableExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

final class LocalFilesystemExternalPayloadStorage implements PredictableExternalPayloadStorageDriver
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

    /**
     * Store the content under its hash, atomically and idempotently: the
     * content must match the hash, an existing object must hold the same
     * content, and a new one appears only complete (written aside, then
     * renamed into place).
     */
    public function put(string $data, string $sha256, string $codec): string
    {
        $this->validateSha256($sha256);
        if (! hash_equals(strtolower($sha256), hash('sha256', $data))) {
            throw new ExternalPayloadIntegrityException('External payload content does not match its sha256.');
        }
        $path = $this->pathFor($sha256, $codec);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create external payload directory [%s].', $directory));
        }

        if (is_file($path)) {
            $existing = file_get_contents($path);
            if ($existing === false || ! hash_equals(strtolower($sha256), hash('sha256', $existing))) {
                throw new ExternalPayloadIntegrityException(sprintf(
                    'Stored external payload [%s] does not match its sha256.',
                    $path
                ));
            }

            return self::pathToFileUri($path);
        }

        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $data, LOCK_EX) === false || ! rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw new RuntimeException(sprintf('Unable to write external payload [%s].', $path));
        }

        return self::pathToFileUri($path);
    }

    public function uriFor(string $sha256, string $codec): string
    {
        $this->validateSha256($sha256);

        return self::pathToFileUri($this->pathFor($sha256, $codec));
    }

    public function get(string $uri): string
    {
        $path = $this->pathFromUri($uri);
        if (! is_file($path)) {
            throw new ExternalPayloadIntegrityException(sprintf('Unable to read external payload [%s].', $uri));
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

    private function pathFor(string $sha256, string $codec): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $this->safeCodecSegment($codec) . DIRECTORY_SEPARATOR
            . substr($sha256, 0, 2) . DIRECTORY_SEPARATOR . $sha256;
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
