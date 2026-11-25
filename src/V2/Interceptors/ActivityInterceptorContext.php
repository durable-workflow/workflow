<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

/**
 * Immutable context passed to activity interceptor hooks.
 */
final class ActivityInterceptorContext
{
    /**
     * @param array<string, mixed> $task snapshot of the activity task row, or of the hosting
     *                                   workflow task row for a local activity
     * @param array<int|string, mixed> $arguments resolved activity arguments
     */
    public function __construct(
        public readonly ?string $workerId,
        public readonly ?string $connection,
        public readonly ?string $taskQueue,
        public readonly ?string $namespace,
        public readonly array $task,
        public readonly string $activityType,
        public readonly string $activityClass,
        public readonly array $arguments,
        public readonly int $attemptNumber,
        public readonly string $taskId,
        public readonly string $activityExecutionId,
        public readonly ?string $activityAttemptId,
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly bool $local,
        public readonly object $activity,
    ) {
    }

    /**
     * Return a copy that calls the activity with different arguments.
     */
    public function withArguments(mixed ...$arguments): self
    {
        return new self(
            $this->workerId,
            $this->connection,
            $this->taskQueue,
            $this->namespace,
            $this->task,
            $this->activityType,
            $this->activityClass,
            $arguments,
            $this->attemptNumber,
            $this->taskId,
            $this->activityExecutionId,
            $this->activityAttemptId,
            $this->workflowId,
            $this->runId,
            $this->local,
            $this->activity,
        );
    }
}
