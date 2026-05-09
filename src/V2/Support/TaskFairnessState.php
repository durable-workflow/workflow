<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Persisted state for the fairness scheduler.
 *
 * The scheduler asks the state store for a recent-dispatch snapshot per fairness
 * class on a given (namespace, task queue), and reports each dispatch back so
 * future polls can rebalance away from already-served classes. Implementations
 * typically back this with a sliding-window cache keyed by (queue, class) so the
 * counters decay over time and a class that goes idle stops penalizing itself.
 */
interface TaskFairnessState
{
    /**
     * Return the recent-dispatch counter (or weighted score) for each class on
     * the given task queue. Missing classes default to 0.
     *
     * @param  list<string>  $classes
     * @return array<string, float>
     */
    public function snapshot(string $namespace, string $taskQueue, array $classes): array;

    /**
     * Record that one dispatch was made for the given class on the given queue.
     */
    public function recordDispatch(string $namespace, string $taskQueue, string $class, int $weight = 1): void;
}
