<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Models\WorkflowCommand;

class CommandResult
{
    public function __construct(
        protected readonly WorkflowCommand $command,
    ) {
    }

    public function commandId(): string
    {
        return $this->command->id;
    }

    public function commandSequence(): ?int
    {
        return $this->command->command_sequence;
    }

    public function instanceId(): ?string
    {
        return $this->command->workflow_instance_id;
    }

    /**
     * Stable logical workflow identifier for app-owned projections.
     *
     * Alias of {@see instanceId()} using the workflow authoring name.
     *
     * @api Stable v2 command result identity API.
     */
    public function workflowId(): ?string
    {
        return $this->instanceId();
    }

    /**
     * Stable run identifier for the execution generation selected by this command.
     *
     * @api Stable v2 command result identity API.
     */
    public function runId(): ?string
    {
        return $this->command->workflow_run_id;
    }

    public function requestedRunId(): ?string
    {
        return $this->command->requestedRunId();
    }

    public function resolvedRunId(): ?string
    {
        return $this->command->resolvedRunId();
    }

    public function workflowType(): ?string
    {
        return $this->command->workflow_type;
    }

    public function workflowClass(): ?string
    {
        return $this->command->workflow_class;
    }

    public function type(): string
    {
        return $this->command->command_type->value;
    }

    public function status(): string
    {
        return $this->command->status->value;
    }

    public function outcome(): ?string
    {
        return $this->command->outcome?->value;
    }

    public function rejectionReason(): ?string
    {
        return $this->command->rejection_reason;
    }

    public function targetScope(): string
    {
        return $this->command->target_scope;
    }

    public function source(): ?string
    {
        return $this->command->source;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->command->commandContext();
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationErrors(): array
    {
        return $this->command->validationErrors();
    }

    public function accepted(): bool
    {
        return $this->command->status === CommandStatus::Accepted;
    }

    public function rejected(): bool
    {
        return $this->command->status === CommandStatus::Rejected;
    }

    public function rejectedNotCurrent(): bool
    {
        return $this->command->outcome === CommandOutcome::RejectedNotCurrent;
    }

    public function rejectedInvalidArguments(): bool
    {
        return $this->command->outcome === CommandOutcome::RejectedInvalidArguments;
    }

    public function reason(): ?string
    {
        return $this->command->commandReason();
    }

    public function message(): ?string
    {
        return $this->command->commandMessage();
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public function payloadValues(array $keys): array
    {
        return $this->command->payloadValues($keys);
    }
}
