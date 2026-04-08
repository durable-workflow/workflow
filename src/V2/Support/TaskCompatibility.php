<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class TaskCompatibility
{
    public static function resolve(WorkflowTask $task, ?WorkflowRun $run = null): ?string
    {
        return self::normalize($task->compatibility)
            ?? self::normalize($run?->compatibility ?? $task->run?->compatibility);
    }

    public static function sync(WorkflowTask $task, ?WorkflowRun $run = null): ?string
    {
        $compatibility = self::resolve($task, $run);

        if ($compatibility === null || $task->compatibility === $compatibility) {
            return $compatibility;
        }

        $task->forceFill([
            'compatibility' => $compatibility,
        ])->save();

        return $compatibility;
    }

    public static function supported(WorkflowTask $task, ?WorkflowRun $run = null): bool
    {
        return WorkerCompatibility::supports(self::resolve($task, $run));
    }

    public static function supportedInFleet(WorkflowTask $task, ?WorkflowRun $run = null): bool
    {
        return WorkerCompatibilityFleet::supports(
            self::resolve($task, $run),
            $task->connection ?? $run?->connection,
            $task->queue ?? $run?->queue,
        );
    }

    public static function mismatchReason(WorkflowTask $task, ?WorkflowRun $run = null): ?string
    {
        return WorkerCompatibility::mismatchReason(self::resolve($task, $run));
    }

    public static function fleetMismatchReason(WorkflowTask $task, ?WorkflowRun $run = null): ?string
    {
        return WorkerCompatibilityFleet::mismatchReason(
            self::resolve($task, $run),
            $task->connection ?? $run?->connection,
            $task->queue ?? $run?->queue,
        );
    }

    private static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
