<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowUpdate;

final class UpdateResult extends CommandResult
{
    public function __construct(
        WorkflowCommand $command,
        private readonly mixed $result = null,
        private readonly ?WorkflowFailure $failure = null,
        private readonly ?WorkflowUpdate $update = null,
        private readonly string $waitFor = 'completed',
        private readonly bool $waitTimedOut = false,
        private readonly ?int $waitTimeoutSeconds = null,
    ) {
        parent::__construct($command);
    }

    public static function fromCommand(
        WorkflowCommand $command,
        mixed $result = null,
        ?WorkflowFailure $failure = null,
        ?WorkflowUpdate $update = null,
        string $waitFor = 'completed',
        bool $waitTimedOut = false,
        ?int $waitTimeoutSeconds = null,
    ): self {
        return new self($command, $result, $failure, $update, $waitFor, $waitTimedOut, $waitTimeoutSeconds);
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

    public function updateId(): ?string
    {
        return $this->update?->id;
    }

    public function updateStatus(): ?string
    {
        return $this->update?->status?->value;
    }

    public function waitFor(): string
    {
        return $this->waitFor;
    }

    public function waitTimedOut(): bool
    {
        return $this->waitTimedOut;
    }

    public function waitTimeoutSeconds(): ?int
    {
        return $this->waitTimeoutSeconds;
    }
}
