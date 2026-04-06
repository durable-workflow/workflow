<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

final class RestoredWorkflowException extends RuntimeException
{
    /**
     * @param array<string, mixed> $failurePayload
     */
    public function __construct(
        private readonly array $failurePayload,
    ) {
        parent::__construct(
            is_string($failurePayload['message'] ?? null) ? $failurePayload['message'] : 'Workflow failure',
            is_int($failurePayload['code'] ?? null) ? $failurePayload['code'] : 0,
        );
    }

    public function originalExceptionClass(): string
    {
        return is_string($this->failurePayload['class'] ?? null)
            ? $this->failurePayload['class']
            : self::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function failurePayload(): array
    {
        return $this->failurePayload;
    }
}
