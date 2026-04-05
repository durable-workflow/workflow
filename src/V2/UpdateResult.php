<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;

final class UpdateResult extends CommandResult
{
    public function __construct(
        WorkflowCommand $command,
        private readonly mixed $result = null,
        private readonly ?WorkflowFailure $failure = null,
    ) {
        parent::__construct($command);
    }

    public static function fromCommand(
        WorkflowCommand $command,
        mixed $result = null,
        ?WorkflowFailure $failure = null,
    ): self {
        return new self($command, $result, $failure);
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function completed(): bool
    {
        return $this->command->outcome === CommandOutcome::UpdateCompleted;
    }

    public function failed(): bool
    {
        return $this->command->outcome === CommandOutcome::UpdateFailed;
    }

    public function failureMessage(): ?string
    {
        return $this->failure?->message;
    }

    public function failureId(): ?string
    {
        return $this->failure?->id;
    }
}
