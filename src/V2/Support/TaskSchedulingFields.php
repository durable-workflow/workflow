<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;

/**
 * Resolve dispatch-shaping task fields (priority + fairness) for a new
 * workflow or activity task.
 *
 * Workflow tasks inherit priority and fairness from the parent run. Activity
 * tasks inherit from the run unless the per-call ActivityOptions snapshot
 * supplies an override; in that case the per-activity values win.
 */
final class TaskSchedulingFields
{
    /**
     * @return array{priority: int, fairness_key: ?string, fairness_weight: int}
     */
    public static function forRun(WorkflowRun $run): array
    {
        return [
            'priority' => self::asPriority($run->priority ?? null),
            'fairness_key' => self::asKey($run->fairness_key ?? null),
            'fairness_weight' => self::asWeight($run->fairness_weight ?? null),
        ];
    }

    /**
     * @return array{priority: int, fairness_key: ?string, fairness_weight: int}
     */
    public static function forActivity(WorkflowRun $run, ?ActivityExecution $execution): array
    {
        $base = self::forRun($run);

        if (! $execution instanceof ActivityExecution) {
            return $base;
        }

        $options = is_array($execution->activity_options) ? $execution->activity_options : [];

        $priority = $options['priority'] ?? null;
        $fairnessKey = $options['fairness_key'] ?? null;
        $fairnessWeight = $options['fairness_weight'] ?? null;

        return [
            'priority' => is_int($priority) ? self::asPriority($priority) : $base['priority'],
            'fairness_key' => $fairnessKey === null ? $base['fairness_key'] : self::asKey($fairnessKey),
            'fairness_weight' => is_int($fairnessWeight) ? self::asWeight($fairnessWeight) : $base['fairness_weight'],
        ];
    }

    private static function asPriority(mixed $value): int
    {
        if (! is_int($value) || $value < TaskPriority::MIN || $value > TaskPriority::MAX) {
            return TaskPriority::DEFAULT;
        }

        return $value;
    }

    private static function asKey(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : strtolower($trimmed);
    }

    private static function asWeight(mixed $value): int
    {
        if (! is_int($value) || $value < 1 || $value > 1000) {
            return 1;
        }

        return $value;
    }
}
