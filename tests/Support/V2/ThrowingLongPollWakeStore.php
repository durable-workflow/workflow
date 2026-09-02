<?php

declare(strict_types=1);

namespace Tests\Support\V2;

use RuntimeException;
use Workflow\V2\Support\CacheLongPollWakeStore;

/**
 * Acceleration-layer wake store that fails every signal with an
 * exception.
 *
 * Simulates the "wake backend unreachable" degraded-mode scenario
 * pinned in docs/architecture/scheduler-correctness.md, where
 * `signal()` calls surface as exceptions at the publisher. The
 * scheduler-correctness contract requires durable dispatch to remain
 * correct under this failure mode; publishers MUST NOT cause task
 * creation, history write, or schedule fire to fail.
 */
final class ThrowingLongPollWakeStore extends CacheLongPollWakeStore
{
    public int $signalAttempts = 0;

    public function signal(string ...$channels): void
    {
        $this->signalAttempts++;

        throw new RuntimeException('simulated wake backend unreachable');
    }
}
