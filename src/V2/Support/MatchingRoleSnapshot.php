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
     * `php artisan workflow:v2:repair-pass` instead. `wake_owner` names which
     * cooperating process should currently own that broad wake path.
     * `task_dispatch_mode` reports the configured dispatch mode (`queue` or
     * `poll`).
     * `partition_primitives` freezes the matching-role routing axes that
     * operators and downstream clients can reason about, while
     * `backpressure_model` reports the durable admission boundary the
     * engine enforces today.
     * `discovery_limits` freezes the numeric matching-role contract values
     * (poll batch cap, availability ceiling, default wake signal TTL, and
     * default workflow/activity lease durations) so operators and downstream
     * tooling can reason about the contract without grepping the source.
     *
     * @return array{
     *     queue_wake_enabled: bool,
     *     shape: string,
     *     wake_owner: string,
     *     task_dispatch_mode: string,
     *     partition_primitives: list<string>,
     *     backpressure_model: string,
     *     discovery_limits: array{
     *         poll_batch_cap: int,
     *         availability_ceiling_seconds: int,
     *         wake_signal_ttl_seconds: int,
     *         workflow_task_lease_seconds: int,
     *         activity_task_lease_seconds: int
     *     }
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
            'wake_owner' => $queueWakeEnabled ? 'worker_loop' : 'dedicated_repair_pass',
            'task_dispatch_mode' => $dispatchMode,
            'partition_primitives' => ['connection', 'queue', 'compatibility', 'namespace'],
            'backpressure_model' => 'lease_ownership',
            'discovery_limits' => [
                'poll_batch_cap' => DefaultWorkflowTaskBridge::POLL_BATCH_CAP,
                'availability_ceiling_seconds' => DefaultWorkflowTaskBridge::AVAILABILITY_CEILING_SECONDS,
                'wake_signal_ttl_seconds' => CacheLongPollWakeStore::DEFAULT_SIGNAL_TTL_SECONDS,
                'workflow_task_lease_seconds' => DefaultWorkflowTaskBridge::WORKFLOW_TASK_LEASE_SECONDS,
                'activity_task_lease_seconds' => ActivityLease::DURATION_SECONDS,
            ],
        ];
    }
}
