<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ServiceCallOutcome;

/**
 * Result of evaluating a service-boundary request.
 *
 * Decisions carry the durable outcome plus audit-safe context. Payload bytes
 * never belong in decision metadata; they remain under the codec and
 * data-converter boundary.
 */
final class ServiceBoundaryDecision
{
    /**
     * @param array<string, scalar|array<mixed>|null> $metadata
     */
    public function __construct(
        public readonly ServiceCallOutcome $outcome,
        public readonly string $reason,
        public readonly ?string $message = null,
        public readonly ?string $policyName = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $metadata = [],
    ) {
    }

    public static function allow(string $policyName = 'default', array $metadata = []): self
    {
        return new self(
            outcome: ServiceCallOutcome::Accepted,
            reason: 'accepted',
            policyName: $policyName,
            metadata: $metadata,
        );
    }

    public static function denyAuthorization(
        string $reason,
        ?string $message = null,
        string $policyName = 'default',
        array $metadata = [],
    ): self {
        return new self(
            outcome: ServiceCallOutcome::RejectedForbidden,
            reason: $reason,
            message: $message,
            policyName: $policyName,
            metadata: ['failure_reason' => 'policy_rejection'] + $metadata,
        );
    }

    public static function denyNamespacePolicy(
        string $reason,
        ?string $message = null,
        string $policyName = 'default',
        array $metadata = [],
    ): self {
        return self::denyAuthorization(
            reason: $reason,
            message: $message,
            policyName: $policyName,
            metadata: $metadata,
        );
    }

    public static function denyRateLimit(
        ?int $retryAfterSeconds = null,
        ?string $message = null,
        string $policyName = 'default',
        array $metadata = [],
    ): self {
        return new self(
            outcome: ServiceCallOutcome::RejectedThrottled,
            reason: 'rate_limit_exceeded',
            message: $message,
            policyName: $policyName,
            retryAfterSeconds: $retryAfterSeconds,
            metadata: ['failure_reason' => 'policy_rejection'] + $metadata,
        );
    }

    public static function denyConcurrency(
        ?int $retryAfterSeconds = null,
        ?string $message = null,
        string $policyName = 'default',
        array $metadata = [],
    ): self {
        return new self(
            outcome: ServiceCallOutcome::RejectedConcurrencyLimited,
            reason: 'concurrency_limit_exceeded',
            message: $message,
            policyName: $policyName,
            retryAfterSeconds: $retryAfterSeconds,
            metadata: ['failure_reason' => 'policy_rejection'] + $metadata,
        );
    }

    public static function denyCircuitOpen(
        ?int $retryAfterSeconds = null,
        ?string $message = null,
        string $policyName = 'default',
        array $metadata = [],
    ): self {
        return new self(
            outcome: ServiceCallOutcome::RejectedCircuitOpen,
            reason: 'circuit_open',
            message: $message,
            policyName: $policyName,
            retryAfterSeconds: $retryAfterSeconds,
            metadata: ['failure_reason' => 'policy_rejection'] + $metadata,
        );
    }

    public static function denyUnknownTarget(
        string $resolutionFailedAt = 'operation',
        ?string $message = null,
        string $policyName = 'default',
    ): self {
        return new self(
            outcome: ServiceCallOutcome::RejectedNotFound,
            reason: 'unknown_target',
            message: $message,
            policyName: $policyName,
            metadata: [
                'failure_reason' => 'resolution_failure',
                'resolution_failed_at' => $resolutionFailedAt,
            ],
        );
    }

    public function isAllowed(): bool
    {
        return $this->outcome === ServiceCallOutcome::Accepted;
    }

    public function isDenied(): bool
    {
        return $this->outcome->isBoundaryRejection();
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'category' => $this->outcome->category(),
            'reason' => $this->reason,
            'message' => $this->message,
            'policy' => $this->policyName,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'metadata' => $this->metadata,
        ];
    }
}
