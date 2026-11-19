<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

/**
 * The runtime refused a durable signal-external command in workflow code,
 * before anything was recorded or sent. While a run cleans up after a
 * cancellation request, only activities, timers and side effects are
 * available, as on the Server (`cancellation_cleanup_command_not_available`).
 *
 * @experimental Durable signal-external may change in a minor release.
 * See docs/api-stability.md#durable-signal-external.
 */
final class ExternalSignalRefusedException extends LogicException
{
    public const CANCELLATION_CLEANUP = 'cancellation_cleanup_command_not_available';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function duringCancellationCleanup(): self
    {
        return new self(
            self::CANCELLATION_CLEANUP,
            'A durable signal-external command is not available while the run cleans up after a cancellation request.',
        );
    }
}
