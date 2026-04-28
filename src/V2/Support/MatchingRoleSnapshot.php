<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class MatchingRoleSnapshot
{
    /**
     * Process-local view of the matching-role deployment shape on this node.
     *
     * `queue_wake_enabled` reports `workflows.v2.matching_role.queue_wake_enabled`
     * exactly as the queue-worker Looping listener in WorkflowServiceProvider
     * consumes it. `shape` reports `in_worker` when the in-worker broad-poll
     * wake is active on this process and `dedicated` when the process has
     * opted out and the broad sweep is expected to run as
     * `php artisan workflow:v2:repair-pass` instead. `task_dispatch_mode`
     * reports the configured dispatch mode (`queue` or `poll`).
     * `partition_primitives` freezes the matching-role routing axes that
     * operators and downstream clients can reason about, while
     * `backpressure_model` reports the durable admission boundary the
     * engine enforces today.
     *
     * @return array{
     *     queue_wake_enabled: bool,
     *     shape: string,
     *     task_dispatch_mode: string,
     *     partition_primitives: list<string>,
     *     backpressure_model: string
     * }
     */
    public static function current(): array
    {
        $queueWakeEnabled = (bool) config('workflows.v2.matching_role.queue_wake_enabled', true);
        $dispatchModeConfig = config('workflows.v2.task_dispatch_mode', 'queue');
        $dispatchMode = is_string($dispatchModeConfig) && $dispatchModeConfig !== ''
            ? $dispatchModeConfig
            : 'queue';

        return [
            'queue_wake_enabled' => $queueWakeEnabled,
            'shape' => $queueWakeEnabled ? 'in_worker' : 'dedicated',
            'task_dispatch_mode' => $dispatchMode,
            'partition_primitives' => ['connection', 'queue', 'compatibility', 'namespace'],
            'backpressure_model' => 'lease_ownership',
        ];
    }
}
