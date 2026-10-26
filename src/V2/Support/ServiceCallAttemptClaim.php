<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonSerializable;
use LogicException;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceOperation;

final class ServiceCallAttemptClaim implements JsonSerializable
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly WorkflowServiceCall $call,
        public readonly WorkflowServiceOperation $operation,
        public readonly ServiceBoundaryRequest $request,
        public readonly array $options,
        public readonly int $attempt,
        public readonly int $maxAttempts,
        public readonly int $generation,
        public readonly string $token,
        public readonly string $catalogRevision,
        public readonly string $policyRevision,
        #[\SensitiveParameter]
        private readonly string $effectToken,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'call' => $this->call,
            'operation' => $this->operation,
            'request' => $this->request,
            'options' => $this->options,
            'attempt' => $this->attempt,
            'maxAttempts' => $this->maxAttempts,
            'generation' => $this->generation,
            'token' => '[redacted]',
            'catalogRevision' => $this->catalogRevision,
            'policyRevision' => $this->policyRevision,
            'effectToken' => '[redacted]',
        ];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Service-call attempt claims cannot be serialized.');
    }

    public function effectToken(): string
    {
        return $this->effectToken;
    }

    /**
     * @return array{service_call_id: string, attempt: int, max_attempts: int, generation: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'service_call_id' => $this->call->id,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'generation' => $this->generation,
        ];
    }
}
