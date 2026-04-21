<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;
use Throwable;

final class WorkflowPayloadDecodeException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly array $context,
        Throwable $previous,
    ) {
        $receiver = $context['signal_name'] ?? $context['update_name'] ?? $context['receiver_name'] ?? 'payload';
        $codec = $context['codec'] ?? 'unknown';

        parent::__construct(
            sprintf('Failed to decode workflow %s payload with codec [%s].', $receiver, $codec),
            0,
            $previous,
        );
    }
}
