<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\CommandResult;

final class CommandResponse
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(CommandResult $result, ?string $workflowType = null): array
    {
        return [
            'outcome' => $result->outcome(),
            'workflow_id' => $result->instanceId(),
            'run_id' => $result->runId(),
            'command_id' => $result->commandId(),
            'workflow_type' => $workflowType ?? $result->workflowType(),
            'command_status' => $result->status(),
            'rejection_reason' => $result->rejectionReason(),
        ];
    }
}
