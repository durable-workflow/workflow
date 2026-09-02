<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;

final class NullExternalPayloadStoragePolicy implements ExternalPayloadStoragePolicy
{
    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
    {
        return null;
    }

    public function thresholdBytesFor(?string $namespace): ?int
    {
        return null;
    }
}
