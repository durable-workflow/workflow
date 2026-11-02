<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class ActivityEntryArgumentsMismatchException extends LogicException
{
    public function __construct(string $activityType, string $entryMethod)
    {
        parent::__construct(sprintf(
            'Activity [%s] entry [%s] cannot bind its recorded arguments: recorded_argument_would_be_discarded. Restore compatible activity code; this attempt follows the recorded retry policy.',
            $activityType,
            $entryMethod,
        ));
    }
}
