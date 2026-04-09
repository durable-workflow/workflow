<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

final class UnsupportedBackendCapabilitiesException extends RuntimeException
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(private readonly array $snapshot)
    {
        parent::__construct(self::message($snapshot));
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function message(array $snapshot): string
    {
        $issues = $snapshot['issues'] ?? [];

        if (! is_array($issues) || $issues === []) {
            return 'Workflow v2 backend capabilities are unsupported.';
        }

        $messages = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $code = is_string($issue['code'] ?? null) ? $issue['code'] : 'capability_issue';
            $message = is_string($issue['message'] ?? null) ? $issue['message'] : 'Capability issue detected.';
            $messages[] = sprintf('[%s] %s', $code, $message);
        }

        if ($messages === []) {
            return 'Workflow v2 backend capabilities are unsupported.';
        }

        return 'Workflow v2 backend capabilities are unsupported: ' . implode(' ', $messages);
    }
}
