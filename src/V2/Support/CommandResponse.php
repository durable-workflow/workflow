<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\CommandResult;
use Workflow\V2\SignalWithStartResult;
use Workflow\V2\UpdateResult;

final class CommandResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(CommandResult $result, ?string $workflowType = null): array
    {
        $payload = [
            'outcome' => $result->outcome(),
            'workflow_id' => $result->instanceId(),
            'run_id' => $result->runId(),
            'requested_run_id' => $result->requestedRunId(),
            'resolved_run_id' => $result->resolvedRunId(),
            'command_id' => $result->commandId(),
            'command_sequence' => $result->commandSequence(),
            'target_scope' => $result->targetScope(),
            'workflow_type' => $workflowType ?? $result->workflowType(),
            'command_status' => $result->status(),
            'command_source' => $result->source(),
            'reason' => $result->reason(),
            'rejection_reason' => $result->rejectionReason(),
            'validation_errors' => $result->validationErrors(),
        ];

        if ($result instanceof SignalWithStartResult) {
            $payload['start_command_id'] = $result->startCommandId();
            $payload['start_command_sequence'] = $result->startCommandSequence();
            $payload['start_outcome'] = $result->startOutcome();
            $payload['start_command_status'] = $result->startStatus();
            $payload['intake_group_id'] = $result->intakeGroupId();
        }

        if ($result instanceof UpdateResult) {
            $payload['update_id'] = $result->updateId();
            $payload['update_status'] = $result->updateStatus();
            $payload['update_name'] = $result->updateName();
            $payload['workflow_sequence'] = $result->workflowSequence();
            $payload['result'] = $result->result();
            $payload['result_envelope'] = $result->resultEnvelope();
            $payload['failure_id'] = $result->failureId();
            $payload['failure_message'] = $result->failureMessage();
            $payload['accepted_at'] = $result->acceptedAt()?->toJSON();
            $payload['applied_at'] = $result->appliedAt()?->toJSON();
            $payload['rejected_at'] = $result->rejectedAt()?->toJSON();
            $payload['closed_at'] = $result->closedAt()?->toJSON();
            $payload['wait_for'] = $result->waitFor();
            $payload['wait_timed_out'] = $result->waitTimedOut();
            $payload['wait_timeout_seconds'] = $result->waitTimeoutSeconds();
        }

        return $payload;
    }
}
