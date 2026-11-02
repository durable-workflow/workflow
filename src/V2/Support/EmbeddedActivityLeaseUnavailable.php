<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;

/** The original embedded activity grant could not be confirmed. */
final class EmbeddedActivityLeaseUnavailable extends RuntimeException
{
}
