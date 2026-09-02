<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Package-owned control plane for reserved runtime signal delivery.
 *
 * @internal Runtime infrastructure only. Host adapters should implement
 *           {@see WorkflowControlPlane} for ordinary workflow operations.
 */
interface RuntimeSignalControlPlane
{
    /**
     * Deliver a package-owned runtime signal.
     *
     * @return array<string, mixed>
     */
    public function runtimeSignal(string $instanceId, string $name, array $options = []): array;
}
