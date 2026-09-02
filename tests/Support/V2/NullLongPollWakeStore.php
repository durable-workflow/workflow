<?php

declare(strict_types=1);

namespace Tests\Support\V2;

use Workflow\V2\Support\CacheLongPollWakeStore;

/**
 * Acceleration-layer wake store that silently drops every signal.
 *
 * Simulates the "wake backend silently drops signals" and "cache
 * backend permanently unavailable" degraded-mode scenarios pinned in
 * docs/architecture/scheduler-correctness.md. All signal traffic is
 * dropped, so channel version stamps never advance; pollers that rely
 * on `changed()` will always see `false`.
 */
final class NullLongPollWakeStore extends CacheLongPollWakeStore
{
    public function signal(string ...$channels): void
    {
        // Intentionally drop every signal.
    }

    public function snapshot(array $channels): array
    {
        $snapshot = [];

        foreach ($channels as $channel) {
            if (! is_string($channel) || trim($channel) === '') {
                continue;
            }

            $snapshot[trim($channel)] = null;
        }

        return $snapshot;
    }

    public function changed(array $snapshot): bool
    {
        return false;
    }
}
