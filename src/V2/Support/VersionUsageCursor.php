<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use JsonException;

/** @internal A continuation binds query identity; authorization is reapplied by every query. */
final class VersionUsageCursor
{
    public static function encode(string $query, string $through, string $after): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'version' => 1,
            'query' => $query,
            'through' => $through,
            'after' => $after,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function decode(string $cursor, string $query): array
    {
        if (strlen($cursor) > 2048 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Invalid version-usage cursor.');
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
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
            || self::encode($query, $value['through'], $value['after']) !== $cursor) {
            throw new InvalidArgumentException('Version-usage cursor does not match this query.');
        }
        return $value;
    }

    private static function runId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}\z/D', $value) === 1;
    }
}
