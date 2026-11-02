<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/** The original activity attempt lost its task lease without settling. */
final class ActivityLeaseExpiredException extends RuntimeException
{
}
