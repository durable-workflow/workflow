<?php

declare(strict_types=1);

namespace Workflow\V2\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched after any durable failure record is committed.
 *
 * Intended for error-reporting integrations (Sentry, Bugsnag, etc.) that need
 * a single hook for all failure types: workflow terminal failures, activity
 * terminal failures, and any future failure source kinds.
 *
 * Timestamp semantics: `committedAt` is the wall-clock time at which the
 * failure record was durably written (commit time).
 */
class FailureRecorded
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $instanceId,
        public readonly string $runId,
        public readonly string $failureId,
        public readonly string $sourceKind,
        public readonly string $sourceId,
        public readonly string $exceptionClass,
        public readonly string $message,
        public readonly string $committedAt,
    ) {
    }
}
