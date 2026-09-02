<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;

final class ExternalPayloadStorage
{
    private const MAX_CACHE_ENTRIES = 128;

    private const MAX_CACHE_BYTES = 16777216;

    /**
     * @var array<string, array{data: string, bytes: int, used_at: int}>
     */
    private static array $verifiedCache = [];

    private static int $verifiedCacheBytes = 0;

    private static int $verifiedCacheSequence = 0;

    public static function store(
        ExternalPayloadStorageDriver $driver,
        string $data,
        string $codec
    ): ExternalPayloadReference {
        $codec = CodecRegistry::canonicalize($codec);
        $sha256 = hash('sha256', $data);
        $uri = $driver->put($data, $sha256, $codec);

        return new ExternalPayloadReference(uri: $uri, sha256: $sha256, sizeBytes: strlen($data), codec: $codec);
    }

    public static function fetch(ExternalPayloadStorageDriver $driver, ExternalPayloadReference $reference): string
    {
        $cacheKey = self::cacheKey($reference);
        if (isset(self::$verifiedCache[$cacheKey])) {
            self::$verifiedCache[$cacheKey]['used_at'] = ++self::$verifiedCacheSequence;

            return self::$verifiedCache[$cacheKey]['data'];
        }

        $data = $driver->get($reference->uri);

        if (strlen($data) !== $reference->sizeBytes) {
            throw new ExternalPayloadIntegrityException('External payload size does not match its reference.');
        }

        if (! hash_equals($reference->sha256, hash('sha256', $data))) {
            throw new ExternalPayloadIntegrityException('External payload hash does not match its reference.');
        }

        self::rememberVerified($cacheKey, $data);

        return $data;
    }

    public static function flushVerifiedCache(): void
    {
        self::$verifiedCache = [];
        self::$verifiedCacheBytes = 0;
        self::$verifiedCacheSequence = 0;
    }

    private static function cacheKey(ExternalPayloadReference $reference): string
    {
        return implode("\n", [
            $reference->uri,
            $reference->sha256,
            (string) $reference->sizeBytes,
            $reference->codec,
        ]);
    }

    private static function rememberVerified(string $cacheKey, string $data): void
    {
        $bytes = strlen($data);
        if ($bytes > self::MAX_CACHE_BYTES) {
            return;
        }

        if (isset(self::$verifiedCache[$cacheKey])) {
            self::$verifiedCacheBytes -= self::$verifiedCache[$cacheKey]['bytes'];
        }

        self::$verifiedCache[$cacheKey] = [
            'data' => $data,
            'bytes' => $bytes,
            'used_at' => ++self::$verifiedCacheSequence,
        ];
        self::$verifiedCacheBytes += $bytes;

        self::evictVerifiedCache();
    }

    private static function evictVerifiedCache(): void
    {
        while (
            count(self::$verifiedCache) > self::MAX_CACHE_ENTRIES
            || self::$verifiedCacheBytes > self::MAX_CACHE_BYTES
        ) {
            $oldestKey = null;
            $oldestUsedAt = PHP_INT_MAX;

            foreach (self::$verifiedCache as $key => $entry) {
                if ($entry['used_at'] < $oldestUsedAt) {
                    $oldestKey = $key;
                    $oldestUsedAt = $entry['used_at'];
                }
            }

            if ($oldestKey === null) {
                self::$verifiedCacheBytes = 0;

                return;
            }

            self::$verifiedCacheBytes -= self::$verifiedCache[$oldestKey]['bytes'];
            unset(self::$verifiedCache[$oldestKey]);
        }
    }
}
