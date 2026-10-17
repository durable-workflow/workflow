<?php

declare(strict_types=1);

namespace Workflow\Support;

use RuntimeException;

/** @internal A safe write cannot proceed without inspecting its driver wait. */
final class SqliteWriteWaitException extends RuntimeException
{
}
