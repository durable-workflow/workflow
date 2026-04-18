<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Throwable;

/**
 * Public contract for external activity-task workers.
 *
 * Lets an adapter poll for ready durable activity tasks, claim a task by id,
 * execute the activity in-process or record completion/failure from an
 * external worker, all without reimplementing activity lifecycle internals.
 */
interface ActivityTaskBridge
{
    /**
     * Find ready activity tasks matching the given queue criteria.
     *
     * Returns an array of task summaries ordered by availability.
     *
     * @return list<array{
     *     task_id: string,
     *     workflow_run_id: string,
     *     workflow_instance_id: string,
     *     activity_execution_id: string|null,
     *     activity_type: string|null,
     *     activity_class: string|null,
     *     connection: string|null,
     *     queue: string|null,
     *     compatibility: string|null,
     *     available_at: string|null,
     * }>
     */
    /**
     * @param  list<string>  $activityTypes
     */
    public function poll(
        ?string $connection,
        ?string $queue,
        int $limit = 1,
        ?string $compatibility = null,
        ?string $namespace = null,
        array $activityTypes = []
    ): array;

    /**
     * Claim a specific activity task with detailed status.
     *
     * Always returns a result array. When the task cannot be claimed,
     * claimed is false and reason/reason_detail explain why.
     *
     * @return array{
     *     claimed: bool,
     *     task_id: string,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string|null,
     *     attempt_number: int|null,
     *     activity_type: string|null,
     *     activity_class: string|null,
     *     idempotency_key: string|null,
     *     payload_codec: string|null,
     *     arguments: string|null,
     *     retry_policy: array<string, mixed>|null,
     *     connection: string|null,
     *     queue: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     reason: string|null,
     *     reason_detail: string|null,
     *     retry_after_seconds: int|null,
     *     backend_error: string|null,
     *     compatibility_reason: string|null,
     * }
     */
    public function claimStatus(string $taskId, ?string $leaseOwner = null): array;

    /**
     * Claim a specific activity task.
     *
     * Returns the claim payload on success, or null if the task cannot be claimed.
     *
     * @return array{
     *     task_id: string,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string|null,
     *     attempt_number: int|null,
     *     activity_type: string|null,
     *     activity_class: string|null,
     *     idempotency_key: string|null,
     *     payload_codec: string|null,
     *     arguments: string|null,
     *     retry_policy: array<string, mixed>|null,
     *     connection: string|null,
     *     queue: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     * }|null
     */
    public function claim(string $taskId, ?string $leaseOwner = null): ?array;

    /**
     * Record activity task completion from an external worker.
     *
     * @param mixed $result The serialized activity result.
     * @return array{
     *     recorded: bool,
     *     task_id: string,
     *     reason: string|null,
     *     next_task_id: string|null,
     * }
     */
    public function complete(string $attemptId, mixed $result, ?string $codec = null): array;

    /**
     * Record activity task failure from an external worker.
     *
     * @param Throwable|array<string, mixed>|string $failure
     * @return array{
     *     recorded: bool,
     *     task_id: string,
     *     reason: string|null,
     *     next_task_id: string|null,
     * }
     */
    public function fail(string $attemptId, Throwable|array|string $failure, ?string $codec = null): array;

    /**
     * Get the current status of an activity attempt.
     *
     * Returns liveness, cancellation, and lease metadata for the attempt
     * without renewing the lease or recording a heartbeat.
     *
     * @return array{
     *     can_continue: bool,
     *     cancel_requested: bool,
     *     reason: string|null,
     *     heartbeat_recorded: bool,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     workflow_task_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string,
     *     attempt_number: int|null,
     *     run_status: string|null,
     *     run_closed_reason: string|null,
     *     run_closed_at: string|null,
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null,
     * }
     */
    public function status(string $attemptId): array;

    /**
     * Heartbeat to extend the lease on a claimed activity task.
     *
     * Returns liveness, cancellation, and lease metadata. When the activity
     * has been cancelled, can_continue is false and cancel_requested is true.
     *
     * @param array<string, mixed> $progress Optional progress payload.
     * @return array{
     *     can_continue: bool,
     *     cancel_requested: bool,
     *     reason: string|null,
     *     heartbeat_recorded: bool,
     *     workflow_instance_id: string|null,
     *     workflow_run_id: string|null,
     *     workflow_task_id: string|null,
     *     activity_execution_id: string|null,
     *     activity_attempt_id: string,
     *     attempt_number: int|null,
     *     run_status: string|null,
     *     run_closed_reason: string|null,
     *     run_closed_at: string|null,
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null,
     * }
     */
    public function heartbeat(string $attemptId, array $progress = []): array;
}
