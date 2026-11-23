<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

/** @internal Rolls back a worker turn that could not claim the requested child instance. */
final class ChildWorkflowIdUnavailable extends LogicException
{
}
