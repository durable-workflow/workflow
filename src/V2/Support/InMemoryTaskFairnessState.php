<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * In-process fairness state used as the default implementation and as the
 * test double. Counters live for the duration of the process and decay on
 * a fixed half-life so a class that has been idle naturally re-enters the
 * rotation without needing an external scheduler tick.
 */
final class InMemoryTaskFairnessState implements TaskFairnessState
{
    /**
     * @var array<string, array<string, array{score: float, updated_at: float}>>
     */
    private array $store = [];

    public function __construct(
        /**
         * Number of seconds for the dispatch-counter score to decay by half.
         * Smaller values make the scheduler more reactive to bursty workloads.
         */
        private readonly float $halfLifeSeconds = 30.0,
    ) {
    }

    public function snapshot(string $namespace, string $taskQueue, array $classes): array
    {
        $bucket = $this->bucketKey($namespace, $taskQueue);
        $now = $this->clock();

        $snapshot = [];

        foreach ($classes as $class) {
            $entry = $this->store[$bucket][$class] ?? null;
            $snapshot[$class] = $entry === null ? 0.0 : self::decayed($entry, $now, $this->halfLifeSeconds);
        }

        return $snapshot;
    }

    public function recordDispatch(string $namespace, string $taskQueue, string $class, int $weight = 1): void
    {
        $bucket = $this->bucketKey($namespace, $taskQueue);
        $now = $this->clock();
        $entry = $this->store[$bucket][$class] ?? null;
        $current = $entry === null ? 0.0 : self::decayed($entry, $now, $this->halfLifeSeconds);

        $this->store[$bucket][$class] = [
            'score' => $current + max(1, $weight),
            'updated_at' => $now,
        ];
    }

    public function reset(): void
    {
        $this->store = [];
    }

    private function bucketKey(string $namespace, string $taskQueue): string
    {
        return $namespace . "\x00" . $taskQueue;
    }

    private function clock(): float
    {
        return microtime(true);
    }

    /**
     * @param  array{score: float, updated_at: float}  $entry
     */
    private static function decayed(array $entry, float $now, float $halfLifeSeconds): float
    {
        if ($halfLifeSeconds <= 0.0) {
            return $entry['score'];
        }

        $elapsed = max(0.0, $now - $entry['updated_at']);

        if ($elapsed === 0.0) {
            return $entry['score'];
        }

        $factor = 2 ** (-$elapsed / $halfLifeSeconds);

        return $entry['score'] * $factor;
    }
}
