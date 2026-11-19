<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use InvalidArgumentException;

final class InvalidCommandRequestIdException extends InvalidArgumentException
{
    public const REASON_INVALID = 'invalid_request_id';

    public const REASON_RESERVED = 'request_id_reserved';

    public function __construct(
        string $message = 'The request ID must be a valid UTF-8 string containing 1 to 255 Unicode characters.',
        private readonly string $reason = self::REASON_INVALID,
    ) {
        parent::__construct($message);
    }

    /**
     * A key under a prefix the runtime reserves for its own deliveries.
     */
    public static function reserved(string $prefix): self
    {
        return new self(
            sprintf('The request ID prefix [%s] is reserved for durable workflow-to-workflow signals.', $prefix),
            self::REASON_RESERVED,
        );
    }

    /**
     * `invalid_request_id`, or `request_id_reserved` for a reserved prefix.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationErrors(): array
    {
        return [
            'request_id' => [$this->getMessage()],
        ];
    }
}
