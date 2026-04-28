<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Binding seam for the scheduler role's due-schedule tick.
 *
 * Hosts that want to move schedule evaluation out of process can bind a
 * replacement implementation without patching the package command entrypoint.
 */
interface SchedulerRole
{
    /**
     * @return list<array{
     *     schedule_id: string,
     *     instance_id: string|null,
     *     outcome?: string,
     *     error?: string
     * }>
     */
    public function tick(int $limit = 100): array;
}
