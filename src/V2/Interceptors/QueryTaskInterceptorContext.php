<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

use Workflow\V2\Models\WorkflowRun;

/**
 * Immutable context passed to query interceptor hooks.
 *
 * Embedded queries run in the calling process, not on a queue worker, so
 * `$workerId` is null.
 */
final class QueryTaskInterceptorContext
{
    /**
     * @param array<string, mixed> $task the query request
     * @param array<int|string, mixed> $arguments validated query arguments
     */
    public function __construct(
        public readonly ?string $workerId,
        public readonly ?string $connection,
        public readonly ?string $taskQueue,
        public readonly ?string $namespace,
        public readonly array $task,
        public readonly string $queryName,
        public readonly string $workflowType,
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly array $arguments,
    ) {
    }

    /**
     * @internal built by the engine
     *
     * @param array<int|string, mixed> $arguments
     */
    public static function forRun(WorkflowRun $run, string $queryName, array $arguments): self
    {
        return new self(
            null,
            is_string($run->connection) && $run->connection !== '' ? $run->connection : null,
            is_string($run->queue) && $run->queue !== '' ? $run->queue : null,
            is_string($run->namespace) && $run->namespace !== '' ? $run->namespace : null,
            [
                'query_name' => $queryName,
                'workflow_id' => $run->workflow_instance_id,
                'run_id' => $run->id,
                'workflow_type' => $run->workflow_type,
            ],
            $queryName,
            (string) $run->workflow_type,
            (string) $run->workflow_instance_id,
            (string) $run->id,
            $arguments,
        );
    }

    /**
     * Return a copy that calls the query method with different arguments.
     */
    public function withArguments(mixed ...$arguments): self
    {
        return new self(
            $this->workerId,
            $this->connection,
            $this->taskQueue,
            $this->namespace,
            $this->task,
            $this->queryName,
            $this->workflowType,
            $this->workflowId,
            $this->runId,
            $arguments,
        );
    }
}
