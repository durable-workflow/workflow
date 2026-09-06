<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/** @internal Stops local Fiber teardown, not a durable workflow failure. */
final class WorkflowFiberDiscardedException extends RuntimeException
{
}
