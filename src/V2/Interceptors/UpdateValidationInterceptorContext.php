<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

use Workflow\V2\Enums\WorkflowReplayState;
use Workflow\V2\Models\WorkflowRun;

/**
 * Immutable context passed to update-validation interceptor hooks.
 *
 * Validation runs in the process that admits the update, not on a queue
 * worker, so `$workerId` is null. `$arguments` is a copy: the validator
 * always receives the update's own arguments.
 */
final class UpdateValidationInterceptorContext
{
    /**
     * @param array<int|string, mixed> $arguments the update's arguments
     */
    public function __construct(
        public readonly ?string $workerId,
        public readonly ?string $connection,
        public readonly ?string $taskQueue,
        public readonly ?string $namespace,
        public readonly string $updateName,
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
    public static function forRun(WorkflowRun $run, string $updateName, array $arguments): self
    {
        return new self(
            null,
            is_string($run->connection) && $run->connection !== '' ? $run->connection : null,
            is_string($run->queue) && $run->queue !== '' ? $run->queue : null,
            is_string($run->namespace) && $run->namespace !== '' ? $run->namespace : null,
            $updateName,
            (string) $run->workflow_type,
            (string) $run->workflow_instance_id,
            (string) $run->id,
            $arguments,
        );
    }

    /**
     * Update validators always answer from replayed state.
     */
    public function replayState(): WorkflowReplayState
    {
        return WorkflowReplayState::Replaying;
    }
}
