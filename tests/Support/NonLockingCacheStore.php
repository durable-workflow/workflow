<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;

final class NonLockingCacheStore implements Store
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function get($key)
    {
        return $this->values[$key] ?? null;
    }

    public function many(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        $current = (int) ($this->values[$key] ?? 0);
        $current += $value;
        $this->values[$key] = $current;

        return $current;
    }

    public function decrement($key, $value = 1)
    {
        $current = (int) ($this->values[$key] ?? 0);
        $current -= $value;
        $this->values[$key] = $current;

        return $current;
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return 'test-non-locking:';
    }
}
