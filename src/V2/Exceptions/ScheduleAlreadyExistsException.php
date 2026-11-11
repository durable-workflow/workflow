<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A schedule with this id already exists in the namespace, including one
 * that was deleted or finished its runs. Matches the Server's
 * `409 schedule_already_exists`. Update the existing schedule, or create
 * one with a new id.
 *
 * @api
 */
final class ScheduleAlreadyExistsException extends RuntimeException
{
    public function __construct(
        public readonly string $scheduleId,
        public readonly string $namespace,
        public readonly ?string $existingStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Schedule [%s] already exists in namespace [%s]%s. Update it, or create a schedule with a new id.',
            $scheduleId,
            $namespace,
            $existingStatus === null ? '' : sprintf(' with status [%s]', $existingStatus),
        ), 0, $previous);
    }
}
