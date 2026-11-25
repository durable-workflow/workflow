<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTaskClaim;

/**
 * @internal Builds activity interceptor contexts from engine records.
 */
final class ActivityInterceptorContexts
{
    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function forClaim(
        ActivityTaskClaim $claim,
        object $activity,
        array $arguments
    ): ActivityInterceptorContext {
        return self::build(
            $claim->run,
            $claim->task,
            $claim->execution,
            $activity,
            $arguments,
            $claim->attemptNumber(),
            $claim->attemptId(),
            false,
        );
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public static function forLocal(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        object $activity,
        array $arguments,
    ): ActivityInterceptorContext {
        $attemptId = $execution->current_attempt_id;

        return self::build(
            $run,
            $task,
            $execution,
            $activity,
            $arguments,
            max(1, (int) $execution->attempt_count),
            is_string($attemptId) && $attemptId !== '' ? $attemptId : null,
            true,
        );
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private static function build(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        object $activity,
        array $arguments,
        int $attemptNumber,
        ?string $attemptId,
        bool $local,
    ): ActivityInterceptorContext {
        return new ActivityInterceptorContext(
            WorkerInterceptors::workerId(),
            self::nullableString($local ? $task->connection : ($execution->connection ?? $task->connection)),
            self::nullableString($local ? $task->queue : ($execution->queue ?? $task->queue)),
            self::nullableString($task->namespace ?? $run->namespace),
            WorkerInterceptors::taskSnapshot($task),
            (string) $execution->activity_type,
            $activity::class,
            $arguments,
            $attemptNumber,
            (string) $task->id,
            (string) $execution->id,
            $attemptId,
            (string) $run->workflow_instance_id,
            (string) $run->id,
            $local,
            $activity,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
