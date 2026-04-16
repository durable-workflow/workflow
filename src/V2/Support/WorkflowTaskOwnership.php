<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowTask;

/**
 * Guards workflow task ownership and lease validity.
 *
 * Validates that:
 * - Task exists in the namespace
 * - Task is currently leased
 * - Lease is owned by the requesting worker
 * - Lease has not expired
 * - Attempt counter matches (fencing against reclaimed tasks)
 *
 * Used by worker protocol endpoints (complete, fail, heartbeat) to verify
 * the worker has authority to modify the task.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The constructor signature and public instance method signatures on
 *      this class are covered by the workflow package's semver guarantee.
 *      See docs/api-stability.md.
 */
class WorkflowTaskOwnership
{
    public function __construct(
        private readonly WorkflowTaskBridge $bridge,
    ) {}

    /**
     * Guard workflow task ownership and lease validity.
     *
     * @param  callable(string, string): ?WorkflowTask  $namespaceTaskResolver  Resolves task by namespace and ID (e.g., NamespaceWorkflowScope::task())
     * @return array{
     *     valid: bool,
     *     reason: string|null,
     *     task: WorkflowTask|null,
     *     status: array<string, mixed>|null
     * }
     */
    public function guard(
        callable $namespaceTaskResolver,
        string $namespace,
        string $taskId,
        int $workflowTaskAttempt,
        string $leaseOwner,
    ): array {
        // Namespace scoping — verify the task belongs to this namespace.
        $task = $namespaceTaskResolver($namespace, $taskId);

        if (! $task instanceof WorkflowTask) {
            return [
                'valid' => false,
                'reason' => 'task_not_found',
                'task' => null,
                'status' => null,
            ];
        }

        // Delegate lease and ownership validation to the bridge.
        $status = $this->bridge->status($taskId);

        if (($status['reason'] ?? null) !== null) {
            return [
                'valid' => false,
                'reason' => 'task_not_found',
                'task' => $task,
                'status' => $status,
            ];
        }

        if ($status['task_status'] !== TaskStatus::Leased->value) {
            return [
                'valid' => false,
                'reason' => 'task_not_leased',
                'task' => $task,
                'status' => $status,
            ];
        }

        if ($status['lease_owner'] === null || $status['lease_expires_at'] === null) {
            return [
                'valid' => false,
                'reason' => 'task_not_leased',
                'task' => $task,
                'status' => $status,
            ];
        }

        if ($status['lease_expired']) {
            return [
                'valid' => false,
                'reason' => 'lease_expired',
                'task' => $task,
                'status' => $status,
            ];
        }

        if ($status['lease_owner'] !== $leaseOwner) {
            return [
                'valid' => false,
                'reason' => 'lease_owner_mismatch',
                'task' => $task,
                'status' => $status,
            ];
        }

        if ($status['attempt_count'] !== null && $status['attempt_count'] !== $workflowTaskAttempt) {
            return [
                'valid' => false,
                'reason' => 'workflow_task_attempt_mismatch',
                'task' => $task,
                'status' => $status,
            ];
        }

        return [
            'valid' => true,
            'reason' => null,
            'task' => $task,
            'status' => $status,
        ];
    }
}
