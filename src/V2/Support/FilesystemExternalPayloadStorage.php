<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

final class FilesystemExternalPayloadStorage implements ExternalPayloadStorageDriver
{
    private string $prefix;

    /**
     * @param  array<string, mixed>  $writeOptions
     */
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $uriScheme,
        private readonly string $uriAuthority,
        string $prefix = '',
        private readonly array $writeOptions = [],
    ) {
        if (! preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*\z/', $uriScheme)) {
            throw new InvalidArgumentException('External payload URI scheme is invalid.');
        }

        if ($uriAuthority === '' || str_contains($uriAuthority, '/')) {
            throw new InvalidArgumentException(
                'External payload URI authority must be a non-empty bucket, container, or disk name.'
            );
        }

        $this->prefix = $this->normalizePrefix($prefix);
    }

    public function put(string $data, string $sha256, string $codec): string
    {
        $this->validateSha256($sha256);
        $key = $this->objectKey($sha256, $codec);

        if (! $this->disk->put($key, $data, $this->writeOptions)) {
            throw new RuntimeException(sprintf('Unable to write external payload [%s].', $key));
        }

        return $this->objectUri($key);
    }

    public function get(string $uri): string
    {
        $key = $this->keyFromUri($uri);
        $data = $this->disk->get($key);

        if (! is_string($data)) {
            throw new RuntimeException(sprintf('Unable to read external payload [%s].', $uri));
        }

        return $data;
    }

    public function delete(string $uri): void
    {
        $this->disk->delete($this->keyFromUri($uri));
    }

    private function objectKey(string $sha256, string $codec): string
    {
        $codecSegment = $this->safePathSegment($codec);
        $key = $codecSegment . '/' . substr($sha256, 0, 2) . '/' . $sha256;

        return $this->prefix === '' ? $key : $this->prefix . '/' . $key;
    }

    private function objectUri(string $key): string
    {
        return $this->uriScheme . '://' . rawurlencode($this->uriAuthority) . '/' . implode(
            '/',
            array_map('rawurlencode', explode('/', $key)),
        );
    }

    private function keyFromUri(string $uri): string
    {
        $parts = parse_url($uri);

        if (($parts['scheme'] ?? null) !== $this->uriScheme) {
            throw new InvalidArgumentException('External payload URI uses a different storage scheme.');
        }

        if (rawurldecode((string) ($parts['host'] ?? '')) !== $this->uriAuthority) {
            throw new InvalidArgumentException(
                'External payload URI uses a different bucket, container, or disk name.'
            );
        }

        $path = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        $this->assertSafeKey($path);

        if ($this->prefix !== '' && ! str_starts_with($path, $this->prefix . '/')) {
            throw new InvalidArgumentException('External payload URI is outside the configured storage prefix.');
        }

        return $path;
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            return '';
        }

        $this->assertSafeKey($prefix);

        return implode('/', array_map([$this, 'safePathSegment'], explode('/', $prefix)));
    }

    private function assertSafeKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('External payload URI must include an object key.');
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('External payload URI contains an unsafe object key.');
            }
        }
    }

    private function safePathSegment(string $segment): string
    {
        if (! preg_match('/\A[A-Za-z0-9_.-]+\z/', $segment)) {
            throw new InvalidArgumentException('External payload storage path segment contains unsafe characters.');
        }

        return $segment;
    }

    private function validateSha256(string $sha256): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/i', $sha256)) {
            throw new InvalidArgumentException('sha256 must be a hex digest.');
        }
    }
}
