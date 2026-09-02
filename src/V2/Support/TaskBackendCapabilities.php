<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

final class TaskBackendCapabilities
{
    public static function recordClaimFailureIfUnsupported(
        WorkflowTask $task,
        ?CarbonInterface $failedAt = null,
    ): ?string {
        if (WorkflowStub::faked()) {
            self::clearClaimFailure($task);

            return null;
        }

        $message = self::unsupportedMessage($task);

        if ($message === null) {
            self::clearClaimFailure($task);

            return null;
        }

        $failedAt ??= now();

        $task->forceFill([
            'last_claim_failed_at' => $failedAt,
            'last_claim_error' => $message,
            'repair_available_at' => TaskRepairPolicy::repairAvailableAtAfterFailure($task, $failedAt),
        ])->save();

        return $message;
    }

    public static function clearClaimFailure(WorkflowTask $task): void
    {
        if ($task->last_claim_failed_at === null && $task->last_claim_error === null) {
            return;
        }

        $task->forceFill([
            'last_claim_failed_at' => null,
            'last_claim_error' => null,
            'repair_available_at' => null,
        ])->save();
    }

    private static function unsupportedMessage(WorkflowTask $task): ?string
    {
        $snapshot = BackendCapabilities::snapshot(queueConnection: $task->connection);

        if (BackendCapabilities::isSupported($snapshot)) {
            return null;
        }

        return (new UnsupportedBackendCapabilitiesException($snapshot))->getMessage();
    }
}
