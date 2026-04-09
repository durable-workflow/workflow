<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\CommandResult;
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
            'rejection_reason' => $result->rejectionReason(),
            'validation_errors' => $result->validationErrors(),
        ];

        if ($result instanceof UpdateResult) {
            $payload['update_id'] = $result->updateId();
            $payload['result'] = $result->result();
            $payload['failure_id'] = $result->failureId();
            $payload['failure_message'] = $result->failureMessage();
        }

        return $payload;
    }
}
