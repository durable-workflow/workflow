<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

/**
 * Public contract for control-plane workflow operations.
 *
 * Accepts durable workflow type keys for start, signal, query, update,
 * cancel, and terminate without requiring local PHP class resolution.
 * This lets a standalone server or external adapter drive workflows
 * through the package API using only type keys and instance ids.
 *
 * All methods return plain arrays suitable for HTTP/JSON serialization.
 * Rejection and validation outcomes are expressed through the returned
 * array rather than thrown exceptions.
 */
interface WorkflowControlPlane
{
    /**
     * Start a workflow by durable type key.
     *
     * Creates a workflow instance, first run, and ready workflow task.
     * When the workflow class is locally resolvable, the full command
     * contract snapshot and routing are applied. When the class is not
     * locally available, the instance is created with the type key and
     * explicit routing from options; the command contract is deferred
     * to the worker that claims the first task.
     *
     * Options:
     * - arguments: string|null — codec-tagged serialized arguments
     * - connection: string|null — queue connection override
     * - queue: string|null — queue name override
     * - business_key: string|null — caller-supplied business key
     * - labels: array<string, string>|null — visibility labels
     * - memo: array<string, mixed>|null — non-indexed metadata
     * - duplicate_start_policy: 'reject_duplicate'|'return_existing_active'
     *
     * @return array{
     *     started: bool,
     *     workflow_instance_id: string,
     *     workflow_run_id: string|null,
     *     workflow_type: string,
     *     outcome: string,
     *     task_id: string|null,
     *     reason: string|null,
     * }
     */
    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array;

    /**
     * Send a signal to a workflow instance by name.
     *
     * Options:
     * - arguments: array<int, mixed> — positional signal arguments
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     * }
     */
    public function signal(string $instanceId, string $name, array $options = []): array;

    /**
     * Execute a query against a workflow instance.
     *
     * Query requires replaying the workflow, which needs the workflow
     * class to be locally resolvable. When the class is unavailable,
     * the result indicates the query cannot be served.
     *
     * Options:
     * - arguments: array<int, mixed> — positional query arguments
     *
     * @return array{
     *     success: bool,
     *     workflow_instance_id: string,
     *     result: mixed,
     *     reason: string|null,
     * }
     */
    public function query(string $instanceId, string $name, array $options = []): array;

    /**
     * Submit an update to a workflow instance.
     *
     * Options:
     * - arguments: array<int, mixed> — positional update arguments
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     update_id: string|null,
     *     reason: string|null,
     * }
     */
    public function update(string $instanceId, string $name, array $options = []): array;

    /**
     * Cancel a workflow instance.
     *
     * Options:
     * - reason: string|null — cancellation reason
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     * }
     */
    public function cancel(string $instanceId, array $options = []): array;

    /**
     * Terminate a workflow instance.
     *
     * Options:
     * - reason: string|null — termination reason
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     * }
     */
    public function terminate(string $instanceId, array $options = []): array;
}
