<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Workflow-visible Nexus service operation result.
 *
 * Async/accepted calls use the same surface as terminal calls so workflow code
 * can always persist or expose the durable service-call id.
 *
 * @api Stable v2 workflow authoring API.
 */
final class ServiceOperationResult
{
    /**
     * @param array<string, mixed> $serviceCall
     */
    public function __construct(
        public readonly string $serviceCallId,
        public readonly ?string $status,
        public readonly ?string $outcome,
        public readonly ?string $endpointName,
        public readonly ?string $serviceName,
        public readonly ?string $operationName,
        public readonly mixed $responsePayload,
        public readonly array $serviceCall,
    ) {
    }

    /**
     * @param array<string, mixed> $surface
     */
    public static function fromSurface(array $surface, mixed $responsePayload = null): self
    {
        $serviceCallId = self::stringValue($surface['service_call_id'] ?? null)
            ?? self::stringValue($surface['id'] ?? null)
            ?? '';

        return new self(
            serviceCallId: $serviceCallId,
            status: self::stringValue($surface['status'] ?? null),
            outcome: self::stringValue($surface['outcome'] ?? null),
            endpointName: self::stringValue($surface['endpoint_name'] ?? null),
            serviceName: self::stringValue($surface['service_name'] ?? null),
            operationName: self::stringValue($surface['operation_name'] ?? null),
            responsePayload: $responsePayload,
            serviceCall: $surface,
        );
    }

    public function accepted(): bool
    {
        return ! in_array($this->status, ['failed', 'cancelled'], true);
    }

    public function completed(): bool
    {
        return $this->status === 'completed';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    public function cancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'service_call_id' => $this->serviceCallId,
            'status' => $this->status,
            'outcome' => $this->outcome,
            'endpoint_name' => $this->endpointName,
            'service_name' => $this->serviceName,
            'operation_name' => $this->operationName,
            'response_payload' => $this->responsePayload,
            'service_call' => $this->serviceCall,
        ];
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
