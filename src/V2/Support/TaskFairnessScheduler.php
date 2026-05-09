<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Reorders a batch of ready task candidates so that, within a priority tier,
 * dispatch is rebalanced across distinct fairness-key classes.
 *
 * The bridge SQL already orders candidates by (priority asc, available_at asc, id).
 * This scheduler walks the batch, groups it by priority tier, and within each tier
 * picks the next task from the class that has been served the fewest weighted
 * dispatches recently. Tasks with no fairness key share an implicit "default"
 * class so unmarked tenants are not crowded out by a single keyed class.
 *
 * The scheduler is passed a fairness state store (typically backed by a cache)
 * so dispatch decisions remain stable across processes that share the same store.
 * The store can be a no-op for unit tests; in that case all classes start at zero
 * and the round-robin still alternates as expected.
 */
final class TaskFairnessScheduler
{
    public function __construct(
        private readonly TaskFairnessState $state,
    ) {}

    /**
     * @param  list<array{task_id?: string, priority?: int, fairness_key?: ?string, fairness_weight?: int}>  $candidates
     * @return list<array{task_id?: string, priority?: int, fairness_key?: ?string, fairness_weight?: int}>
     */
    public function reorder(string $namespace, string $taskQueue, array $candidates): array
    {
        if (count($candidates) <= 1) {
            return $candidates;
        }

        $tiers = [];

        foreach ($candidates as $candidate) {
            $priority = isset($candidate['priority']) && is_int($candidate['priority'])
                ? $candidate['priority']
                : TaskPriority::DEFAULT;
            $tiers[$priority] ??= [];
            $tiers[$priority][] = $candidate;
        }

        ksort($tiers);

        $reordered = [];

        foreach ($tiers as $tierCandidates) {
            foreach ($this->reorderTier($namespace, $taskQueue, $tierCandidates) as $entry) {
                $reordered[] = $entry;
            }
        }

        return $reordered;
    }

    /**
     * @param  list<array{task_id?: string, priority?: int, fairness_key?: ?string, fairness_weight?: int}>  $tier
     * @return list<array{task_id?: string, priority?: int, fairness_key?: ?string, fairness_weight?: int}>
     */
    private function reorderTier(string $namespace, string $taskQueue, array $tier): array
    {
        if (count($tier) <= 1) {
            return $tier;
        }

        $classes = [];

        foreach ($tier as $candidate) {
            $key = TaskFairnessKey::classFor($candidate['fairness_key'] ?? null);
            $classes[$key] ??= [];
            $classes[$key][] = $candidate;
        }

        if (count($classes) <= 1) {
            return $tier;
        }

        $weights = $this->state->snapshot($namespace, $taskQueue, array_keys($classes));

        // Track an in-batch dispatch count so a class that already had a task
        // chosen earlier in this reorder pass yields to other classes for the
        // next slot — preventing one class from monopolizing a single batch.
        $batch = array_fill_keys(array_keys($classes), 0);
        $reordered = [];

        while (true) {
            $candidateClasses = array_filter($classes, static fn (array $entries): bool => $entries !== []);

            if ($candidateClasses === []) {
                break;
            }

            $bestClass = null;
            $bestScore = null;

            foreach ($candidateClasses as $class => $_entries) {
                $weight = $this->classWeight($classes[$class][0] ?? []);
                $served = ($weights[$class] ?? 0.0) + $batch[$class];
                $score = $served / max(1, $weight);

                if ($bestClass === null || $score < $bestScore) {
                    $bestClass = $class;
                    $bestScore = $score;
                }
            }

            $reordered[] = array_shift($classes[$bestClass]);
            $batch[$bestClass]++;
        }

        return $reordered;
    }

    /**
     * @param  array{fairness_weight?: int}  $candidate
     */
    private function classWeight(array $candidate): int
    {
        $weight = $candidate['fairness_weight'] ?? null;

        return is_int($weight) && $weight >= 1 ? $weight : 1;
    }
}
