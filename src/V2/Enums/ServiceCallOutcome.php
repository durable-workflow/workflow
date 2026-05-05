<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Caller-facing outcome of a cross-namespace service call. Stored on
 * the service-call row separately from ServiceCallStatus so lifecycle
 * state and boundary-policy decisions remain distinguishable.
 *
 * Pinned by docs/architecture/workflow-service-calls-architecture.md,
 * docs/architecture/cross-namespace-service-policy.md, and their
 * documentation tests.
 */
enum ServiceCallOutcome: string
{
    /**
     * The boundary admitted the call and dispatched the resolved handler.
     * Open calls carry this until a terminal outcome replaces it.
     */
    case Accepted = 'accepted';

    /**
     * The handler completed the advertised operation successfully.
     */
    case Completed = 'completed';

    /**
     * The call was cancelled by the caller, cancellation scope, or
     * operation cancellation policy.
     */
    case Cancelled = 'cancelled';

    /**
     * The call deadline elapsed before a terminal handler result arrived.
     */
    case TimedOut = 'timed_out';

    /**
     * The target namespace, endpoint, service, or operation did not resolve.
     */
    case RejectedNotFound = 'rejected_not_found';

    /**
     * Boundary authorization denied the caller at the endpoint, service, or
     * operation axis.
     */
    case RejectedForbidden = 'rejected_forbidden';

    /**
     * Boundary rate limiting rejected the call before handler dispatch.
     */
    case RejectedThrottled = 'rejected_throttled';

    /**
     * Boundary concurrency limiting rejected the call before handler dispatch.
     */
    case RejectedConcurrencyLimited = 'rejected_concurrency_limited';

    /**
     * Boundary circuit-break state rejected the call before handler dispatch.
     */
    case RejectedCircuitOpen = 'rejected_circuit_open';

    /**
     * The handler accepted the call and returned a fallback result rather
     * than the full advertised result.
     */
    case Degraded = 'degraded';

    /**
     * The handler accepted the call and produced a terminal failure.
     */
    case HandlerFailed = 'handler_failed';

    public function isBoundaryRejection(): bool
    {
        return match ($this) {
            self::RejectedNotFound,
            self::RejectedForbidden,
            self::RejectedThrottled,
            self::RejectedConcurrencyLimited,
            self::RejectedCircuitOpen => true,
            self::Accepted,
            self::Completed,
            self::Cancelled,
            self::TimedOut,
            self::Degraded,
            self::HandlerFailed => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Accepted;
    }

    public function bucket(): string
    {
        return match ($this) {
            self::Accepted => 'open',
            self::Completed, self::Degraded => 'completed',
            self::Cancelled => 'cancelled',
            self::TimedOut, self::HandlerFailed => 'failed',
            self::RejectedNotFound,
            self::RejectedForbidden,
            self::RejectedThrottled,
            self::RejectedConcurrencyLimited,
            self::RejectedCircuitOpen => 'policy',
        };
    }

    /**
     * @return array<string, list<string>>
     */
    public static function buckets(): array
    {
        return [
            'open' => [self::Accepted->value],
            'completed' => [self::Completed->value, self::Degraded->value],
            'failed' => [self::TimedOut->value, self::HandlerFailed->value],
            'cancelled' => [self::Cancelled->value],
            'policy' => [
                self::RejectedNotFound->value,
                self::RejectedForbidden->value,
                self::RejectedThrottled->value,
                self::RejectedConcurrencyLimited->value,
                self::RejectedCircuitOpen->value,
            ],
        ];
    }
}
