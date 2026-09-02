<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

interface ExternalPayloadStoragePolicy
{
    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver;

    public function thresholdBytesFor(?string $namespace): ?int;
}
