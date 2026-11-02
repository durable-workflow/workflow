<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;
use Workflow\V2\Support\ServiceBoundaryDecision;

final class ServiceOperationAuthorizationException extends RuntimeException
{
    public function __construct(
        string $reason = 'service_operation_authority_invalid',
        public readonly ?ServiceBoundaryDecision $boundaryDecision = null,
    ) {
        parent::__construct($reason);
    }
}
