<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

final class ServiceOperationAuthorizationException extends RuntimeException
{
    public function __construct(string $reason = 'service_operation_authority_invalid')
    {
        parent::__construct($reason);
    }
}
