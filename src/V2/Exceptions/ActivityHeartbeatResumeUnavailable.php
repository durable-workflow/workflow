<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

/** No checkpoint-resume contract applies to the current activity context. */
final class ActivityHeartbeatResumeUnavailable extends LogicException
{
}
