<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/** The replayable exception identity recorded for terminal activity deadlines. */
final class ActivityTimeoutException extends RuntimeException
{
}
