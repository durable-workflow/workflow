<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;
use Throwable;

/**
 * Thrown when an update validator could not be evaluated at all, for example
 * because the run's state could not be replayed.
 *
 * This is not an application rejection: nothing is recorded for the update,
 * so the caller can retry once the run is replayable again.
 */
final class UpdateValidationFailedException extends LogicException
{
    public function __construct(
        private readonly string $workflowInstanceId,
        private readonly string $workflowRunId,
        private readonly string $updateName,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Workflow instance [%s] could not validate update [%s]: %s',
            $workflowInstanceId,
            $updateName,
            $message,
        ), 0, $previous);
    }

    public function workflowInstanceId(): string
    {
        return $this->workflowInstanceId;
    }

    public function workflowRunId(): string
    {
        return $this->workflowRunId;
    }

    public function updateName(): string
    {
        return $this->updateName;
    }
}
