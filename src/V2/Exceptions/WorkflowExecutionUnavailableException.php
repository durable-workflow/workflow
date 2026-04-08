<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class WorkflowExecutionUnavailableException extends LogicException
{
    public function __construct(
        private readonly string $operation,
        private readonly string $targetName,
        private readonly string $blockedReason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function targetName(): string
    {
        return $this->targetName;
    }

    public function blockedReason(): string
    {
        return $this->blockedReason;
    }
}
