<?php

declare(strict_types=1);

namespace Workflow\V2;

use Throwable;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;

/**
 * Static convenience facade for the ActivityTaskBridge contract.
 *
 * Delegates all calls to the container-resolved singleton so callers
 * that already use the static API continue to work unchanged.
 *
 * New consumers should resolve the contract interface directly:
 *
 *     app(ActivityTaskBridgeContract::class)->poll(...)
 */
final class ActivityTaskBridge
{
    /**
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
    public static function poll(
        ?string $connection,
        ?string $queue,
        int $limit = 1,
        ?string $compatibility = null
    ): array {
        return self::resolve()->poll($connection, $queue, $limit, $compatibility);
    }

    /**
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
    public static function claimStatus(string $taskId, ?string $leaseOwner = null): array
    {
        return self::resolve()->claimStatus($taskId, $leaseOwner);
    }

    /**
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
    public static function claim(string $taskId, ?string $leaseOwner = null): ?array
    {
        return self::resolve()->claim($taskId, $leaseOwner);
    }

    /**
     * @return array{recorded: bool, task_id: string, reason: string|null, next_task_id: string|null}
     */
    public static function complete(string $attemptId, mixed $result, ?string $codec = null): array
    {
        return self::resolve()->complete($attemptId, $result, $codec);
    }

    /**
     * @param Throwable|array<string, mixed>|string $failure
     * @return array{recorded: bool, task_id: string, reason: string|null, next_task_id: string|null}
     */
    public static function fail(string $attemptId, Throwable|array|string $failure): array
    {
        return self::resolve()->fail($attemptId, $failure);
    }

    /**
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
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null,
     * }
     */
    public static function status(string $attemptId): array
    {
        return self::resolve()->status($attemptId);
    }

    /**
     * @param array<string, mixed> $progress
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
     *     activity_status: string|null,
     *     attempt_status: string|null,
     *     task_status: string|null,
     *     lease_owner: string|null,
     *     lease_expires_at: string|null,
     *     last_heartbeat_at: string|null,
     * }
     */
    public static function heartbeatStatus(string $attemptId, array $progress = []): array
    {
        return self::resolve()->heartbeat($attemptId, $progress);
    }

    /**
     * @param array<string, mixed> $progress
     */
    public static function heartbeat(string $attemptId, array $progress = []): bool
    {
        return self::resolve()->heartbeat($attemptId, $progress)['can_continue'];
    }

    private static function resolve(): ActivityTaskBridgeContract
    {
        return app(ActivityTaskBridgeContract::class);
    }
}
