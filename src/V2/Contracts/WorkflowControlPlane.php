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
     * - namespace: string|null — execution namespace for multi-namespace isolation (falls back to config)
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
     * - command_context: \Workflow\V2\CommandContext|null — recorded command attribution/context
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     command_id?: string|null,
     *     command_status?: string|null,
     *     command_source?: string|null,
     *     target_scope?: string|null,
     *     signal_name?: string,
     *     outcome?: string|null,
     *     rejection_reason?: string|null,
     *     validation_errors?: array<string, list<string>>,
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
     * - arguments: array<int|string, mixed> — positional or named query arguments
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     success: bool,
     *     workflow_instance_id: string,
     *     result: mixed,
     *     result_envelope?: array{codec: string, blob: string}|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     target_scope?: string,
     *     query_name?: string,
     *     blocked_reason?: string,
     *     message?: string,
     *     validation_errors?: array<string, list<string>>,
     * }
     */
    public function query(string $instanceId, string $name, array $options = []): array;

    /**
     * Submit an update to a workflow instance.
     *
     * Options:
     * - arguments: array<int|string, mixed> — positional or named update arguments
     * - command_context: \Workflow\V2\CommandContext|null — recorded command attribution/context
     * - wait_for: 'accepted'|'completed'|null — accepted-only submit or completion wait
     * - wait_timeout_seconds: int|null — completion wait timeout
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     update_id: string|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     command_id?: string|null,
     *     command_status?: string|null,
     *     command_source?: string|null,
     *     target_scope?: string|null,
     *     workflow_type?: string|null,
     *     outcome?: string|null,
     *     rejection_reason?: string|null,
     *     validation_errors?: array<string, list<string>>,
     *     update_name?: string|null,
     *     update_status?: string|null,
     *     workflow_sequence?: int|null,
     *     result_envelope?: array{codec: string, blob: string}|null,
     *     failure_id?: string|null,
     *     failure_message?: string|null,
     *     wait_for?: string|null,
     *     wait_timed_out?: bool,
     *     wait_timeout_seconds?: int|null,
     * }
     */
    public function update(string $instanceId, string $name, array $options = []): array;

    /**
     * Cancel a workflow instance.
     *
     * Options:
     * - reason: string|null — cancellation reason
     * - command_context: \Workflow\V2\CommandContext|null — recorded command attribution/context
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     command_id?: string|null,
     *     command_status?: string|null,
     *     command_source?: string|null,
     *     target_scope?: string|null,
     *     workflow_type?: string|null,
     *     outcome?: string|null,
     *     rejection_reason?: string|null,
     * }
     */
    public function cancel(string $instanceId, array $options = []): array;

    /**
     * Terminate a workflow instance.
     *
     * Options:
     * - reason: string|null — termination reason
     * - command_context: \Workflow\V2\CommandContext|null — recorded command attribution/context
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     command_id?: string|null,
     *     command_status?: string|null,
     *     command_source?: string|null,
     *     target_scope?: string|null,
     *     workflow_type?: string|null,
     *     outcome?: string|null,
     *     rejection_reason?: string|null,
     * }
     */
    public function terminate(string $instanceId, array $options = []): array;

    /**
     * Request a repair of the current workflow run.
     *
     * Repair re-projects the run summary, detects liveness issues, and
     * creates a new workflow task when the run is in a repairable state.
     * Only the current run of an open workflow instance may be repaired.
     *
     * Options:
     * - command_context: \Workflow\V2\CommandContext|null — recorded command attribution/context
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     command_id?: string|null,
     *     command_status?: string|null,
     *     command_source?: string|null,
     *     target_scope?: string|null,
     *     workflow_type?: string|null,
     *     outcome?: string|null,
     *     rejection_reason?: string|null,
     * }
     */
    public function repair(string $instanceId, array $options = []): array;

    /**
     * Archive a terminal workflow run.
     *
     * Archive marks a closed run as archived, preventing further commands
     * and signaling that the run data may be eligible for cold storage or
     * cleanup. Only terminal (completed, failed, cancelled, terminated)
     * runs may be archived.
     *
     * Options:
     * - reason: string|null — archive reason
     * - command_context: \Workflow\V2\CommandContext|null — recorded command attribution/context
     * - strict_configured_type_validation: bool — fail closed when a configured workflow type mapping is now invalid
     *
     * @return array{
     *     accepted: bool,
     *     workflow_instance_id: string,
     *     workflow_command_id: string|null,
     *     reason: string|null,
     *     status?: int,
     *     workflow_id?: string,
     *     run_id?: string|null,
     *     command_id?: string|null,
     *     command_status?: string|null,
     *     command_source?: string|null,
     *     target_scope?: string|null,
     *     workflow_type?: string|null,
     *     outcome?: string|null,
     *     rejection_reason?: string|null,
     * }
     */
    public function archive(string $instanceId, array $options = []): array;

    /**
     * Describe the current state of a workflow instance.
     *
     * Returns instance metadata, current run state, summary fields, and
     * action availability without loading the full Waterline detail view.
     * When a specific run_id is provided, that run is described instead
     * of the current run.
     *
     * Options:
     * - run_id: string|null — describe a specific run instead of current
     *
     * @return array{
     *     found: bool,
     *     workflow_instance_id: string,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     namespace: string|null,
     *     business_key: string|null,
     *     run: array{
     *         workflow_run_id: string,
     *         run_number: int,
     *         is_current_run: bool,
     *         status: string,
     *         status_bucket: string|null,
     *         closed_reason: string|null,
     *         compatibility: string|null,
     *         connection: string|null,
     *         queue: string|null,
     *         started_at: string|null,
     *         closed_at: string|null,
     *         last_progress_at: string|null,
     *         wait_kind: string|null,
     *         wait_reason: string|null,
     *     }|null,
     *     run_count: int,
     *     actions: array{
     *         can_signal: bool,
     *         can_query: bool,
     *         can_update: bool,
     *         can_cancel: bool,
     *         can_terminate: bool,
     *         can_repair: bool,
     *         can_archive: bool,
     *     },
     *     reason: string|null,
     * }
     */
    public function describe(string $instanceId, array $options = []): array;
}
