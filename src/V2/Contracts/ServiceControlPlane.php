<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

interface ServiceControlPlane
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function execute(string $endpointName, string $serviceName, string $operationName, array $options = []): array;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function describeCall(string $serviceCallId, array $options = []): array;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function cancelCall(string $serviceCallId, array $options = []): array;
}
