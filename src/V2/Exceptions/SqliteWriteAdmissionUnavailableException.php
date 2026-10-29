<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/** @internal The mutation body has not run; a later repair pass can try again. */
final class SqliteWriteAdmissionUnavailableException extends RuntimeException
{
}
