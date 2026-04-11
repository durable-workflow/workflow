<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Models\WorkflowCommand;

final class SignalWithStartResult extends CommandResult
{
    public function __construct(
        WorkflowCommand $signalCommand,
        private readonly ?WorkflowCommand $startCommand = null,
        private readonly ?string $intakeGroupId = null,
    ) {
        parent::__construct($signalCommand);
    }

    public static function fromCommands(
        WorkflowCommand $signalCommand,
        ?WorkflowCommand $startCommand = null,
        ?string $intakeGroupId = null,
    ): self {
        return new self($signalCommand, $startCommand, $intakeGroupId);
    }

    public function startCommandId(): ?string
    {
        return $this->startCommand?->id;
    }

    public function startCommandSequence(): ?int
    {
        return $this->startCommand?->command_sequence;
    }

    public function startOutcome(): ?string
    {
        return $this->startCommand?->outcome?->value;
    }

    public function startStatus(): ?string
    {
        return $this->startCommand?->status?->value;
    }

    public function startAccepted(): bool
    {
        return $this->startCommand?->status === CommandStatus::Accepted;
    }

    public function startedNew(): bool
    {
        return $this->startCommand?->outcome === CommandOutcome::StartedNew;
    }

    public function returnedExistingActive(): bool
    {
        return $this->startCommand?->outcome === CommandOutcome::ReturnedExistingActive;
    }

    public function intakeGroupId(): ?string
    {
        return $this->intakeGroupId ?? $this->command->intakeGroupId();
    }

    public function startResult(): ?StartResult
    {
        return $this->startCommand instanceof WorkflowCommand
            ? StartResult::fromCommand($this->startCommand)
            : null;
    }
}
