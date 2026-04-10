<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;
use Throwable;

final class UnresolvedWorkflowFailureException extends LogicException
{
    /**
     * @param array<string, mixed> $failurePayload
     */
    public function __construct(
        private readonly array $failurePayload,
        private readonly string $resolutionSource,
        private readonly ?string $resolutionError = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::message($failurePayload, $resolutionSource, $resolutionError), 0, $previous);
    }

    /**
     * @param array<string, mixed> $failurePayload
     */
    public static function unresolved(array $failurePayload): self
    {
        return new self($failurePayload, 'unresolved');
    }

    /**
     * @param array<string, mixed> $failurePayload
     */
    public static function misconfigured(array $failurePayload, Throwable $previous): self
    {
        return new self($failurePayload, 'misconfigured', $previous->getMessage(), $previous);
    }

    /**
     * @param array<string, mixed> $failurePayload
     */
    public static function unrestorable(array $failurePayload, Throwable $previous): self
    {
        return new self($failurePayload, 'unrestorable', $previous->getMessage(), $previous);
    }

    public function originalExceptionClass(): string
    {
        return is_string($this->failurePayload['class'] ?? null)
            ? $this->failurePayload['class']
            : 'unknown';
    }

    public function exceptionType(): ?string
    {
        return is_string($this->failurePayload['type'] ?? null)
            ? $this->failurePayload['type']
            : null;
    }

    public function resolutionSource(): string
    {
        return $this->resolutionSource;
    }

    public function resolutionError(): ?string
    {
        return $this->resolutionError;
    }

    /**
     * @return array<string, mixed>
     */
    public function failurePayload(): array
    {
        return $this->failurePayload;
    }

    /**
     * @param array<string, mixed> $failurePayload
     */
    private static function message(array $failurePayload, string $resolutionSource, ?string $resolutionError): string
    {
        $class = is_string($failurePayload['class'] ?? null) && $failurePayload['class'] !== ''
            ? $failurePayload['class']
            : 'unknown';
        $type = is_string($failurePayload['type'] ?? null) && $failurePayload['type'] !== ''
            ? $failurePayload['type']
            : 'unknown';

        $message = sprintf(
            'Unable to restore workflow failure [%s] with durable exception type [%s] for replay (%s). Register a valid workflows.v2.types.exceptions mapping or workflows.v2.types.exception_class_aliases entry before replaying this run.',
            $class,
            $type,
            $resolutionSource,
        );

        if ($resolutionError !== null && $resolutionError !== '') {
            $message .= sprintf(' Resolution error: %s', $resolutionError);
        }

        return $message;
    }
}
