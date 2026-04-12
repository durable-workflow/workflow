<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Throwable;

/**
 * Public contract for external workflow-task workers.
 *
 * Lets an adapter poll for ready durable workflow tasks, claim a task by id,
 * retrieve the replay/history payload, execute the task in-process using the
 * package executor, or record completion/failure from an external worker,
 * all without reimplementing replay internals.
 */
interface WorkflowTaskBridge
{
    /**
     * Find ready workflow tasks matching the given queue criteria.
     *
     * Returns an array of task summaries ordered by availability.
     * Each summary contains task_id, workflow_run_id, workflow_instance_id,
     * workflow_type, connection, queue, compatibility, and available_at.
     *
     * @return list<array{
     *     task_id: string,
     *     workflow_run_id: string,
     *     workflow_instance_id: string,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     available_at: string|null,
     * }>
     */
    public function poll(?string $connection, ?string $queue, int $limit = 1, ?string $compatibility = null): array;

    /**
     * Claim a specific workflow task with detailed status.
     *
     * Always returns a result array. When the task cannot be claimed,
     * claimed is false and reason/reason_detail explain why.
     *
     * @return array{
     *     claimed: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     workflow_instance_id: string|null,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     payload_codec: string|null,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     reason: string|null,
     *     reason_detail: string|null,
     * }
     */
    public function claimStatus(string $taskId, ?string $leaseOwner = null): array;

    /**
     * Claim a specific workflow task.
     *
     * Returns the claim payload on success, or null if the task cannot be claimed.
     *
     * @return array{
     *     task_id: string,
     *     workflow_run_id: string,
     *     workflow_instance_id: string,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     payload_codec: string,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     * }|null
     */
    public function claim(string $taskId, ?string $leaseOwner = null): ?array;

    /**
     * Get the replay/history payload for a claimed workflow task.
     *
     * Returns the full history event list, run metadata, and arguments
     * needed by an external worker to replay the workflow.
     *
     * @return array{
     *     task_id: string,
     *     workflow_run_id: string,
     *     workflow_instance_id: string,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     payload_codec: string,
     *     arguments: string|null,
     *     run_status: string,
     *     last_history_sequence: int,
     *     history_events: list<array{
     *         id: string,
     *         sequence: int,
     *         event_type: string,
     *         payload: array<string, mixed>,
     *         workflow_task_id: string|null,
     *         workflow_command_id: string|null,
     *         recorded_at: string|null,
     *     }>,
     * }|null
     */
    public function historyPayload(string $taskId): ?array;

    /**
     * Get a paginated slice of the replay/history payload for a claimed workflow task.
     *
     * Returns history events with sequence > $afterSequence, up to $pageSize events.
     * Use this for large histories to avoid loading the full event list in one request.
     *
     * The response includes has_more and next_after_sequence to support cursor-based
     * pagination. When has_more is false, all events have been returned.
     *
     * @return array{
     *     task_id: string,
     *     workflow_run_id: string,
     *     workflow_instance_id: string,
     *     workflow_type: string|null,
     *     workflow_class: string|null,
     *     payload_codec: string,
     *     arguments: string|null,
     *     run_status: string,
     *     last_history_sequence: int,
     *     after_sequence: int,
     *     page_size: int,
     *     has_more: bool,
     *     next_after_sequence: int|null,
     *     history_events: list<array{
     *         id: string,
     *         sequence: int,
     *         event_type: string,
     *         payload: array<string, mixed>,
     *         workflow_task_id: string|null,
     *         workflow_command_id: string|null,
     *         recorded_at: string|null,
     *     }>,
     * }|null
     */
    public function historyPayloadPaginated(string $taskId, int $afterSequence = 0, int $pageSize = 200): ?array;

    /**
     * Execute a claimed workflow task in-process using the package executor.
     *
     * Claims the task (if not already claimed), runs the full replay/execution
     * loop, records the outcome, and returns the result. This is the recommended
     * path when the caller has the package installed and wants to avoid
     * reimplementing RunWorkflowTask internals.
     *
     * @return array{
     *     executed: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     run_status: string|null,
     *     next_task_id: string|null,
     *     reason: string|null,
     * }
     */
    public function execute(string $taskId): array;

    /**
     * Record workflow task failure.
     *
     * Marks the task as failed and records the error. Use this when the
     * external worker encountered an infrastructure or replay error.
     *
     * @param Throwable|array<string, mixed>|string $failure
     * @return array{
     *     recorded: bool,
     *     task_id: string,
     *     reason: string|null,
     * }
     */
    public function fail(string $taskId, Throwable|array|string $failure): array;

    /**
     * Heartbeat to extend the lease on a claimed workflow task.
     *
     * @return array{
     *     renewed: bool,
     *     task_id: string,
     *     lease_expires_at: string|null,
     *     run_status: string|null,
     *     task_status: string|null,
     *     reason: string|null,
     * }
     */
    public function heartbeat(string $taskId): array;

    /**
     * Complete a claimed workflow task with commands from an external worker.
     *
     * The external worker replayed the workflow and produced a list of commands.
     * Each command is a typed array with a 'type' key and type-specific fields.
     *
     * Non-terminal command types (zero or more, processed in order):
     *
     * - schedule_activity: {type: 'schedule_activity', activity_type: string, arguments?: string|null, connection?: string|null, queue?: string|null}
     *   Schedules an activity task for execution. activity_type is a registered type key.
     *   arguments is a codec-tagged serialized payload. connection and queue override run defaults.
     *
     * - start_timer: {type: 'start_timer', delay_seconds: int}
     *   Schedules a durable timer that fires after delay_seconds.
     *
     * - start_child_workflow: {type: 'start_child_workflow', workflow_type: string, arguments?: string|null, connection?: string|null, queue?: string|null}
     *   Starts a child workflow instance. workflow_type is a registered type key.
     *   arguments is a codec-tagged serialized payload.
     *
     * - record_side_effect: {type: 'record_side_effect', result: string}
     *   Records a deterministic side-effect result using the workflow payload codec.
     *
     * - record_version_marker: {type: 'record_version_marker', change_id: string, version: int, min_supported: int, max_supported: int}
     *   Records the resolved getVersion() decision for replay-safe workflow upgrades.
     *
     * - upsert_search_attributes: {type: 'upsert_search_attributes', attributes: array<string, scalar|null>}
     *   Upserts indexed operator-visible metadata on the workflow run.
     *
     * Terminal command types (at most one):
     *
     * - complete_workflow: {type: 'complete_workflow', result?: string|null}
     *   Marks the workflow run as completed with an optional serialized result.
     *
     * - fail_workflow: {type: 'fail_workflow', message: string, exception_class?: string, exception_type?: string}
     *   Marks the workflow run as failed with a failure record.
     *
     * - continue_as_new: {type: 'continue_as_new', arguments?: string|null, workflow_type?: string|null}
     *   Closes the current run as continued and starts a new run in the same instance.
     *   workflow_type defaults to the current run's type if omitted.
     *
     * At least one command must be present. At most one terminal command is allowed.
     * When only non-terminal commands are present, the run transitions to Waiting
     * and the workflow task is marked Completed.
     *
     * @param list<array{type: string, ...}> $commands
     * @return array{
     *     completed: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     run_status: string|null,
     *     created_task_ids: list<string>,
     *     reason: string|null,
     * }
     */
    public function complete(string $taskId, array $commands): array;
}
