<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Models\WorkflowTask;

final class TaskBackendCapabilities
{
    public static function recordClaimFailureIfUnsupported(
        WorkflowTask $task,
        ?CarbonInterface $failedAt = null,
    ): ?string {
        $message = self::unsupportedMessage($task);

        if ($message === null) {
            self::clearClaimFailure($task);

            return null;
        }

        $task->forceFill([
            'last_claim_failed_at' => $failedAt ?? now(),
            'last_claim_error' => $message,
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
