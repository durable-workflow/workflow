<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;

/**
 * Workflow-safe Nexus service operation options.
 *
 * @api Stable v2 workflow authoring API.
 */
final class ServiceOperationOptions
{
    public const MODE_SYNC = 'sync';

    public const MODE_ASYNC = 'async';

    public const WAIT_ACCEPTED = 'accepted';

    public const WAIT_COMPLETED = 'completed';

    /**
     * @param array<string, mixed> $labels
     * @param array<string, mixed> $memo
     * @param array<string, mixed> $searchAttributes
     * @param array<string, mixed> $metadata
     * @param list<string> $principalRoles
     * @param array<string, mixed> $principalClaims
     */
    public function __construct(
        public readonly ?string $targetNamespace = null,
        public readonly ?string $callerNamespace = null,
        public readonly ?string $serviceCallId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $modeOverride = null,
        public readonly ?string $waitFor = null,
        public readonly ?int $waitTimeoutSeconds = null,
        public readonly ?string $payloadCodec = null,
        public readonly ?string $targetWorkflowInstanceId = null,
        public readonly ?string $targetWorkflowRunId = null,
        public readonly ?string $connection = null,
        public readonly ?string $queue = null,
        public readonly ?string $businessKey = null,
        public readonly array $labels = [],
        public readonly array $memo = [],
        public readonly array $searchAttributes = [],
        public readonly ?string $duplicateStartPolicy = null,
        public readonly array $metadata = [],
        public readonly ?string $requestPayloadReference = null,
        public readonly ?string $principalSubject = null,
        public readonly ?string $principalMethod = null,
        public readonly array $principalRoles = [],
        public readonly ?string $principalTenant = null,
        public readonly array $principalClaims = [],
    ) {
        if ($this->modeOverride !== null && ! in_array(
            $this->modeOverride,
            [self::MODE_SYNC, self::MODE_ASYNC],
            true
        )) {
            throw new InvalidArgumentException('Service operation mode_override must be sync or async.');
        }

        if ($this->waitFor !== null && ! in_array($this->waitFor, [self::WAIT_ACCEPTED, self::WAIT_COMPLETED], true)) {
            throw new InvalidArgumentException('Service operation wait_for must be accepted or completed.');
        }

        if ($this->waitTimeoutSeconds !== null && $this->waitTimeoutSeconds < 0) {
            throw new InvalidArgumentException('Service operation wait timeout must be zero or greater.');
        }
    }

    public static function asyncAccepted(?string $idempotencyKey = null): self
    {
        return new self(
            idempotencyKey: $idempotencyKey,
            modeOverride: self::MODE_ASYNC,
            waitFor: self::WAIT_ACCEPTED,
        );
    }

    public static function syncCompleted(?string $idempotencyKey = null): self
    {
        return new self(
            idempotencyKey: $idempotencyKey,
            modeOverride: self::MODE_SYNC,
            waitFor: self::WAIT_COMPLETED,
        );
    }

    public function shouldResumeOnAdmission(): bool
    {
        return $this->modeOverride === self::MODE_ASYNC || $this->waitFor === self::WAIT_ACCEPTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCommandOptions(): array
    {
        return array_filter([
            'namespace' => $this->targetNamespace,
            'caller_namespace' => $this->callerNamespace,
            'service_call_id' => $this->serviceCallId,
            'idempotency_key' => $this->idempotencyKey,
            'mode_override' => $this->modeOverride,
            'wait_for' => $this->waitFor,
            'wait_timeout_seconds' => $this->waitTimeoutSeconds,
            'payload_codec' => $this->payloadCodec,
            'target_workflow_instance_id' => $this->targetWorkflowInstanceId,
            'target_workflow_run_id' => $this->targetWorkflowRunId,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'business_key' => $this->businessKey,
            'labels' => $this->labels === [] ? null : $this->labels,
            'memo' => $this->memo === [] ? null : $this->memo,
            'search_attributes' => $this->searchAttributes === [] ? null : $this->searchAttributes,
            'duplicate_start_policy' => $this->duplicateStartPolicy,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'request_payload_reference' => $this->requestPayloadReference,
            'principal_subject' => $this->principalSubject,
            'principal_method' => $this->principalMethod,
            'principal_roles' => $this->principalRoles === [] ? null : $this->principalRoles,
            'principal_tenant' => $this->principalTenant,
            'principal_claims' => $this->principalClaims === [] ? null : $this->principalClaims,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
