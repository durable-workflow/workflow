<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;

final class ServiceOperationCall implements YieldedCommand
{
    public function __construct(
        public readonly string $endpointName,
        public readonly string $serviceName,
        public readonly string $operationName,
        public readonly mixed $requestPayload = null,
        public readonly ?ServiceOperationOptions $options = null,
    ) {
    }
}
