<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use JsonException;
use LogicException;

/** @internal A continuation binds query identity; authorization is reapplied by every query. */
final class VersionUsageCursor
{
    private const DOMAIN = "durable-workflow.version-usage-cursor.v1\0";

    public static function encode(string $query, string $through, string $after, string $key): string
    {
        $body = rtrim(strtr(base64_encode(json_encode([
            'version' => 1,
            'query' => $query,
            'through' => $through,
            'after' => $after,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return $body . '.' . self::signature($body, $key);
    }

    public static function decode(string $cursor, string $query, string $key): array
    {
        if (strlen($cursor) > 2048 || preg_match('/\A([A-Za-z0-9_-]+)\.([0-9a-f]{64})\z/D', $cursor, $parts) !== 1) {
            throw new InvalidArgumentException('Invalid version-usage cursor.');
        }
        // Authenticate the exact transport bytes before decoding or parsing them.
        if (! hash_equals(self::signature($parts[1], $key), $parts[2])) {
            throw new InvalidArgumentException('Invalid version-usage cursor signature.');
        }
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        try {
            $value = $decoded === false ? null : json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $value = null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Invalid version-usage cursor.');
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['after', 'query', 'through', 'version'] || $value['version'] !== 1
            || ! is_string($value['query']) || ! hash_equals($query, $value['query'])
            || ! self::runId($value['after']) || ! self::runId($value['through'])
            || strcmp($value['after'], $value['through']) > 0
            || self::encode($query, $value['through'], $value['after'], $key) !== $cursor) {
            throw new InvalidArgumentException('Version-usage cursor does not match this query.');
        }
        return $value;
    }

    private static function signature(string $body, string $key): string
    {
        if ($key === '') {
            throw new LogicException('A non-empty application key is required for version-usage cursors.');
        }
        return hash_hmac('sha256', self::DOMAIN . $body, $key);
    }

    private static function runId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}\z/D', $value) === 1;
    }
}
