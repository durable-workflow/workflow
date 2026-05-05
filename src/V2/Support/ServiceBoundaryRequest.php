<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ServiceCallOperationMode;

/**
 * Input to service-boundary policy evaluation and audit recording.
 */
final class ServiceBoundaryRequest
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $endpointBoundaryPolicy
     * @param array<string, mixed> $serviceBoundaryPolicy
     * @param array<string, mixed> $operationBoundaryPolicy
     * @param array<string, mixed>|null $deadlinePolicy
     * @param array<string, mixed>|null $idempotencyPolicy
     * @param array<string, mixed>|null $cancellationPolicy
     * @param array<string, mixed>|null $retryPolicy
     */
    public function __construct(
        public readonly ServiceCallPrincipal $principal,
        public readonly ?string $callerNamespace,
        public readonly string $targetNamespace,
        public readonly string $endpointName,
        public readonly string $serviceName,
        public readonly string $operationName,
        public readonly ServiceCallOperationMode $operationMode,
        public readonly ?string $resolvedBindingKind = null,
        public readonly ?string $resolvedTargetReference = null,
        public readonly ?string $callerWorkflowInstanceId = null,
        public readonly ?string $callerWorkflowRunId = null,
        public readonly ?string $linkedWorkflowInstanceId = null,
        public readonly ?string $linkedWorkflowRunId = null,
        public readonly ?string $linkedWorkflowUpdateId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $context = [],
        public readonly array $endpointBoundaryPolicy = [],
        public readonly array $serviceBoundaryPolicy = [],
        public readonly array $operationBoundaryPolicy = [],
        public readonly ?array $deadlinePolicy = null,
        public readonly ?array $idempotencyPolicy = null,
        public readonly ?array $cancellationPolicy = null,
        public readonly ?array $retryPolicy = null,
    ) {
    }

    public function boundaryKey(): string
    {
        return sprintf(
            '%s|%s|%s|%s|%s',
            $this->callerNamespace ?? '',
            $this->targetNamespace,
            $this->endpointName,
            $this->serviceName,
            $this->operationName,
        );
    }

    public function targetKey(): string
    {
        return sprintf(
            '%s|%s|%s|%s',
            $this->targetNamespace,
            $this->endpointName,
            $this->serviceName,
            $this->operationName,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function effectiveBoundaryPolicy(): array
    {
        return self::mergePolicy(
            $this->endpointBoundaryPolicy,
            $this->serviceBoundaryPolicy,
            $this->operationBoundaryPolicy,
        );
    }

    /**
     * @param array<string, mixed> ...$policies
     * @return array<string, mixed>
     */
    public static function mergePolicy(array ...$policies): array
    {
        $merged = [];

        foreach ($policies as $policy) {
            foreach ($policy as $key => $value) {
                if (
                    is_array($value)
                    && isset($merged[$key])
                    && is_array($merged[$key])
                    && ! array_is_list($value)
                    && ! array_is_list($merged[$key])
                ) {
                    $merged[$key] = self::mergePolicy($merged[$key], $value);
                    continue;
                }

                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
