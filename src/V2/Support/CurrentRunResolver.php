<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

final class CurrentRunResolver
{
    /**
     * @param list<string> $relations
     */
    public static function forInstance(
        WorkflowInstance $instance,
        array $relations = [],
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        $run = $lockForUpdate
            ? self::queryForInstance($instance->id, $relations, true)->first()
            : self::loadedOrQueriedRun($instance, $relations);

        $instance->setRelation('currentRun', $run);

        if ($run instanceof WorkflowRun) {
            $run->setRelation('instance', $instance);
        }

        return $run;
    }

    /**
     * @param list<string> $relations
     */
    public static function forRun(
        WorkflowRun $run,
        array $relations = [],
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        $run->loadMissing('instance');

        if (! $run->instance instanceof WorkflowInstance) {
            return null;
        }

        return self::forInstance($run->instance, $relations, $lockForUpdate);
    }

    public static function syncPointer(WorkflowInstance $instance, ?WorkflowRun $run): void
    {
        $resolvedRunId = $run?->id;
        $resolvedRunCount = $run?->run_number ?? 0;
        $currentRunCount = (int) ($instance->run_count ?? 0);

        if ($instance->current_run_id === $resolvedRunId && $currentRunCount >= $resolvedRunCount) {
            $instance->setRelation('currentRun', $run);

            if ($run instanceof WorkflowRun) {
                $run->setRelation('instance', $instance);
            }

            return;
        }

        $instance->forceFill([
            'current_run_id' => $resolvedRunId,
            'run_count' => max($currentRunCount, $resolvedRunCount),
        ])->save();

        $instance->setRelation('currentRun', $run);

        if ($run instanceof WorkflowRun) {
            $run->setRelation('instance', $instance);
        }
    }

    /**
     * @param list<string> $relations
     */
    private static function loadedOrQueriedRun(WorkflowInstance $instance, array $relations): ?WorkflowRun
    {
        if ($instance->relationLoaded('runs')) {
            /** @var WorkflowRun|null $run */
            $run = $instance->runs
                ->sort(static function (WorkflowRun $left, WorkflowRun $right): int {
                    if ($left->run_number !== $right->run_number) {
                        return $right->run_number <=> $left->run_number;
                    }

                    $leftStartedAt = $left->started_at?->getTimestampMs()
                        ?? $left->created_at?->getTimestampMs()
                        ?? 0;
                    $rightStartedAt = $right->started_at?->getTimestampMs()
                        ?? $right->created_at?->getTimestampMs()
                        ?? 0;

                    if ($leftStartedAt !== $rightStartedAt) {
                        return $rightStartedAt <=> $leftStartedAt;
                    }

                    $leftCreatedAt = $left->created_at?->getTimestampMs() ?? 0;
                    $rightCreatedAt = $right->created_at?->getTimestampMs() ?? 0;

                    if ($leftCreatedAt !== $rightCreatedAt) {
                        return $rightCreatedAt <=> $leftCreatedAt;
                    }

                    return $right->id <=> $left->id;
                })
                ->first();

            $run?->loadMissing($relations);

            return $run;
        }

        return self::queryForInstance($instance->id, $relations)->first();
    }

    /**
     * @param list<string> $relations
     */
    private static function queryForInstance(
        string $instanceId,
        array $relations = [],
        bool $lockForUpdate = false,
    ) {
        $query = WorkflowRun::query()
            ->where('workflow_instance_id', $instanceId)
            ->orderByDesc('run_number')
            ->orderByDesc('started_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query;
    }
}
