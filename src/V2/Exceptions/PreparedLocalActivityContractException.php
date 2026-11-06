<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/** @internal A prepared activity entered through a different contract. */
final class PreparedLocalActivityContractException extends RuntimeException
{
}
