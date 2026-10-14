<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class CacheThrottle
{
    public static function acquire(string $key, string|bool $value, int $seconds, ?string $activeMarker = null): bool
    {
        $cache = Cache::store();
        $store = $cache->getStore();
        if (! $store instanceof DatabaseStore || $store->getConnection() instanceof SqlServerConnection) {
            return ($activeMarker === null || ! $cache->has($activeMarker))
                && $cache->add($key, $value, $seconds);
        }

        // DatabaseStore::add deletes expired rows before inserting. Renew this
        // throttle in place so competing queue polls do not delete the same row.
        $table = config('cache.stores.' . Cache::getDefaultDriver() . '.table', 'cache');
        $prefixedKey = $store->getPrefix() . $key;
        $query = $store->getConnection()
            ->table($table)
            ->useWritePdo()
            ->where('key', $prefixedKey);
        $now = Carbon::now()->getTimestamp();
        if ($activeMarker !== null) {
            $markerExpiration = $store->getConnection()
                ->table($table)
                ->useWritePdo()
                ->where('key', $store->getPrefix() . $activeMarker)->value('expiration');
            if ($markerExpiration !== null && $markerExpiration >= $now) {
                // A wake just before chain expiry must not delay its replacement.
                $seconds = min($seconds, max(1, (int) $markerExpiration - $now));
            }
        }
        $expiration = $query->value('expiration');
        $values = [
            'value' => serialize($value),
            'expiration' => $now + $seconds,
        ];
        if ($expiration === null) {
            return $store->getConnection()
                ->table($table)
                ->insertOrIgnore([
                    'key' => $prefixedKey,
                    ...$values,
                ]) === 1;
        }
        if ($expiration > $now) {
            return false;
        }

        return $query->where('expiration', '<=', $now)
            ->update($values) === 1;
    }
}
