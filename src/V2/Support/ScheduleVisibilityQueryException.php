<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;

final class ScheduleVisibilityQueryException extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid_schedule_visibility_query',
    ) {
        parent::__construct($message);
    }
}
