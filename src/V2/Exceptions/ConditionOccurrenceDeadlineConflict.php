<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

/** @internal Rolls back the entire completion before reporting a chain refusal. */
final class ConditionOccurrenceDeadlineConflict extends LogicException
{
}
