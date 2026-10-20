<?php

declare(strict_types=1);

namespace Workflow\V2;

use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Support\CommandRequestIdentity;

class CommandResult
{
    protected bool $retained = false;

    protected bool $retainedIntentVerified = true;

    public function __construct(
        protected readonly WorkflowCommand $command,
    ) {
    }

    /**
     * The caller's command key for keyed signals and updates.
     *
     * @api Stable v2 command result identity API.
     */
    public function requestId(): ?string
    {
        return $this->command->requestId();
    }

    /**
     * Whether this result replays a receipt retained from an earlier admission
     * with the same caller key, rather than a new admission.
     */
    public function retained(): bool
    {
        return $this->retained;
    }

    /**
     * False only for a retained legacy receipt whose intent could not be
     * compared because its arguments are no longer decodable.
     */
    public function intentVerified(): bool
    {
        return ! $this->retained || $this->retainedIntentVerified;
    }

    public function requestIdConflict(): bool
    {
        return $this->command->rejection_reason === CommandRequestIdentity::CONFLICT_REASON;
    }

    /**
     * @return array{retained_command_id: string|null, retained_command_type: string|null, retained_outcome: string|null, conflict: list<string>}|null
     */
    public function conflict(): ?array
    {
        if (! $this->requestIdConflict()) {
            return null;
        }

        $values = $this->command->payloadValues([
            'retained_command_id',
            'retained_command_type',
            'retained_outcome',
            'conflict',
        ]);
        $parts = $values['conflict'] ?? [];

        return [
            'retained_command_id' => is_string(
                $values['retained_command_id'] ?? null
            ) ? $values['retained_command_id'] : null,
            'retained_command_type' => is_string(
                $values['retained_command_type'] ?? null
            ) ? $values['retained_command_type'] : null,
            'retained_outcome' => is_string($values['retained_outcome'] ?? null) ? $values['retained_outcome'] : null,
            'conflict' => is_array($parts) ? array_values(array_filter($parts, 'is_string')) : [],
        ];
    }


    public function asRetainedReceipt(bool $intentVerified): static
    {
        $clone = clone $this;
        $clone->retained = true;
        $clone->retainedIntentVerified = $intentVerified;

        return $clone;
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

    public function cancellationContext(): ?CancellationContext
    {
        $snapshot = $this->command->payloadValues(['cancellation'])['cancellation'] ?? null;

        return is_array($snapshot) ? CancellationContext::fromArray($snapshot) : null;
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
