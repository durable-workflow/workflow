<?php

declare(strict_types=1);

namespace Workflow\V2\Client;

use RuntimeException;

/**
 * @api Stable v2 control-plane client API.
 */
final class WorkflowClientException extends RuntimeException
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $body,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }
}
