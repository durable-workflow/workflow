<?php

declare(strict_types=1);

namespace Workflow\Exceptions;

use Exception;
use Throwable;

class NonRetryableException extends Exception implements NonRetryableExceptionContract
{
    public function __construct($message = '', $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
