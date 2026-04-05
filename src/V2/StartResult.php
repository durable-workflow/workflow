<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Models\WorkflowCommand;

final class StartResult extends CommandResult
{
    public static function fromCommand(WorkflowCommand $command): self
    {
        return new self($command);
    }

    public function startedNew(): bool
    {
        return $this->command->outcome === CommandOutcome::StartedNew;
    }

    public function returnedExistingActive(): bool
    {
        return $this->command->outcome === CommandOutcome::ReturnedExistingActive;
    }

    public function rejectedDuplicate(): bool
    {
        return $this->command->outcome === CommandOutcome::RejectedDuplicate;
    }
}
