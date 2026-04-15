<?php

declare(strict_types=1);

namespace Workflow\V2;

use Throwable;
use Workflow\V2\Contracts\WorkflowTaskBridge as WorkflowTaskBridgeContract;

/**
 * Static convenience facade for the WorkflowTaskBridge contract.
 *
 * Delegates all calls to the container-resolved singleton so callers
 * that already use the static API continue to work unchanged.
 *
 * New consumers should resolve the contract interface directly:
 *
 *     app(WorkflowTaskBridgeContract::class)->poll(...)
 */
final class WorkflowTaskBridge
{
    /**
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
    public static function poll(
        ?string $connection,
        ?string $queue,
        int $limit = 1,
        ?string $compatibility = null,
    ): array {
        return self::resolve()->poll($connection, $queue, $limit, $compatibility);
    }

    /**
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
    public static function claimStatus(string $taskId, ?string $leaseOwner = null): array
    {
        return self::resolve()->claimStatus($taskId, $leaseOwner);
    }

    /**
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
    public static function claim(string $taskId, ?string $leaseOwner = null): ?array
    {
        return self::resolve()->claim($taskId, $leaseOwner);
    }

    /**
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
    public static function historyPayload(string $taskId): ?array
    {
        return self::resolve()->historyPayload($taskId);
    }

    /**
     * @return array{
     *     executed: bool,
     *     task_id: string,
     *     workflow_run_id: string|null,
     *     run_status: string|null,
     *     next_task_id: string|null,
     *     reason: string|null,
     * }
     */
    public static function execute(string $taskId): array
    {
        return self::resolve()->execute($taskId);
    }

    /**
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
    public static function complete(string $taskId, array $commands): array
    {
        return self::resolve()->complete($taskId, $commands);
    }

    /**
     * @param Throwable|array<string, mixed>|string $failure
     * @return array{recorded: bool, task_id: string, reason: string|null, next_task_id: string|null}
     */
    public static function fail(string $taskId, Throwable|array|string $failure, ?string $codec = null): array
    {
        return self::resolve()->fail($taskId, $failure, $codec);
    }

    /**
     * @return array{
     *     renewed: bool,
     *     task_id: string,
     *     lease_expires_at: string|null,
     *     run_status: string|null,
     *     task_status: string|null,
     *     reason: string|null,
     * }
     */
    public static function heartbeat(string $taskId): array
    {
        return self::resolve()->heartbeat($taskId);
    }

    private static function resolve(): WorkflowTaskBridgeContract
    {
        return app(WorkflowTaskBridgeContract::class);
    }
}
