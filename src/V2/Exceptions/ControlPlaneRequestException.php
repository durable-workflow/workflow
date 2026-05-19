<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

final class ControlPlaneRequestException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly ?array $body = null,
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }

    public function reason(): ?string
    {
        $reason = $this->body['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
