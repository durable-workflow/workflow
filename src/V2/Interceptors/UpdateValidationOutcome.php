<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

/**
 * What an update validator decided, as update-validation hooks observe it.
 *
 * The engine acts on the validator's own decision, whatever a hook returns.
 */
final class UpdateValidationOutcome
{
    /**
     * @param array<string, list<string>> $validationErrors
     */
    public function __construct(
        public readonly bool $approved,
        public readonly ?string $rejectionMessage = null,
        public readonly ?string $exceptionType = null,
        public readonly array $validationErrors = [],
    ) {
    }

    /**
     * @internal built by the engine
     *
     * @param array{message: string, exception_type: string, validation_errors: array<string, list<string>>}|null $rejection
     */
    public static function fromRejection(?array $rejection): self
    {
        return $rejection === null
            ? new self(true)
            : new self(false, $rejection['message'], $rejection['exception_type'], $rejection['validation_errors']);
    }
}
