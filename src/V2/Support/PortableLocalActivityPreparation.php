<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * @internal Candidate preparation primitive. The caller must persist its
 * authored command prefix before preparing the next local call. This does
 * not expose an endpoint or change the published same-process local path.
 */
final class PortableLocalActivityPreparation
{
    public const MINIMUM_PROTOCOL_VERSION = '1.20';

    /**
     * The worker may invoke application code only after a prepared response.
     * A lost response can be retried with the same claim and worker attempt ID.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function prepare(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        string $workerAttemptId,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array {
        if (preg_match('/^[0-9]+\.[0-9]+$/D', $protocolVersion) !== 1
            || version_compare($protocolVersion, self::MINIMUM_PROTOCOL_VERSION, '<')) {
            return self::response('local_activity_preparation_requires_protocol_1_20');
        }
        if ($sequence < 1 || $workflowTaskAttempt < 1 || $leaseOwner === ''
            || trim($workerAttemptId) === '' || strlen($workerAttemptId) > 255
            || preg_match('//u', $workerAttemptId) !== 1) {
            return self::response('invalid_local_activity_preparation');
        }
        try {
            $normalized = self::normalizeDescriptor($descriptor);
            $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        } catch (ValidationException|JsonException) {
            return self::response('invalid_local_activity_preparation');
        }
        /** @var WorkflowTask|null $snapshotTask */
        $snapshotTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);
        if ($snapshotTask === null) {
            return self::response('task_not_found');
        }
        /** @var ActivityExecution|null $snapshotExecution */
        $snapshotExecution = ActivityExecution::query()
            ->where('workflow_run_id', $snapshotTask->workflow_run_id)
            ->where('sequence', $sequence)
            ->first();

        return DB::transaction(static function () use (
            $snapshotTask,
            $snapshotExecution,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $workerAttemptId,
            $normalized,
            $fingerprint,
        ): array {
            // Follow the shared attempt/execution/run/task lock order whenever
            // an execution exists. An unseen concurrent creation is retried.
            $rows = $snapshotExecution === null ? null : ActivityRowLockOrder::lockForExecution($snapshotExecution->id);
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($snapshotTask->workflow_run_id);
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);
            if ($run === null || $task === null || $task->workflow_run_id !== $run->id) {
                return self::response('workflow_claim_not_found');
            }
            if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased
                || $task->lease_owner !== $leaseOwner || $task->attempt_count !== $workflowTaskAttempt) {
                return self::response('workflow_claim_mismatch');
            }
            if ($task->lease_expires_at === null || now()->gte($task->lease_expires_at)) {
                return self::response('workflow_claim_expired');
            }
            if ($run->status->isTerminal()) {
                return self::response('run_closed');
            }
            if ($run->cancellation_request_command_id !== null) {
                return self::response('cancellation_requested');
            }
            if (($run->execution_deadline_at !== null && now()->gte($run->execution_deadline_at))
                || ($run->run_deadline_at !== null && now()->gte($run->run_deadline_at))) {
                return self::response('run_deadline_expired');
            }
            $execution = $rows['execution'] ?? null;
            $attempt = $rows['attempt'] ?? null;
            if ($snapshotExecution !== null) {
                if (! $execution instanceof ActivityExecution || ! $attempt instanceof ActivityAttempt
                    || $execution->current_attempt_id !== ($rows['snapshot_attempt_id'] ?? null)) {
                    return self::response('local_activity_previous_attempt_unresolved');
                }
                return self::duplicate($run, $task, $execution, $attempt, $workerAttemptId, $fingerprint);
            }
            if (ActivityExecution::query()->where('workflow_run_id', $run->id)->where(
                'sequence',
                $sequence
            )->exists()) {
                return self::response('local_activity_preparation_retry');
            }
            if (WorkflowStepHistory::nextDurableCommandSequence($run) !== $sequence) {
                return self::response('local_activity_command_prefix_not_recorded');
            }
            StructuralLimits::guardPendingActivities($run);
            $codec = $normalized['payload_codec'];
            $arguments = ExternalPayloads::externalizeForNamespace(
                $normalized['arguments'],
                $codec,
                is_string($run->namespace) ? $run->namespace : null,
            );
            StructuralLimits::guardPayloadSize($arguments);
            $now = now();
            $options = new ActivityOptions(
                startToCloseTimeout: $normalized['start_to_close_timeout'] ?? null,
                scheduleToCloseTimeout: $normalized['schedule_to_close_timeout'] ?? null,
                heartbeatTimeout: $normalized['heartbeat_timeout'] ?? null,
            );
            $attemptId = (string) Str::ulid();
            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $sequence,
                'activity_class' => $normalized['activity_type'],
                'activity_type' => $normalized['activity_type'],
                'status' => ActivityStatus::Pending,
                'attempt_count' => 0,
                'payload_codec' => $codec,
                'arguments' => $arguments,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'retry_policy' => ActivityRetryPolicy::snapshotExternal($normalized['retry_policy'] ?? null, $options),
                'activity_options' => [
                    'execution_mode' => LocalActivityRuntime::EXECUTION_MODE,
                    'queue_bypassed' => true,
                    'routing' => 'workflow_worker_process',
                ],
                'schedule_to_close_deadline_at' => $options->scheduleToCloseTimeout === null
                    ? null : $now->copy()
                        ->addSeconds($options->scheduleToCloseTimeout),
            ]);
            $preparation = [
                'version' => 1,
                'descriptor_fingerprint' => $fingerprint,
                'worker_attempt_id' => $workerAttemptId,
                'workflow_task_attempt' => $workflowTaskAttempt,
            ];
            $base = LocalActivityRuntime::eventPayload([
                'activity_execution_id' => $execution->id,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => $sequence,
                'workflow_task_id' => $task->id,
                'local_preparation' => $preparation,
            ]);
            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
                ...$base,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ], $task);
            $execution->forceFill([
                'status' => ActivityStatus::Running,
                'attempt_count' => 1,
                'current_attempt_id' => $attemptId,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
                'close_deadline_at' => $options->startToCloseTimeout === null
                    ? null : $now->copy()
                        ->addSeconds($options->startToCloseTimeout),
                'heartbeat_deadline_at' => $options->heartbeatTimeout === null
                    ? null : $now->copy()
                        ->addSeconds($options->heartbeatTimeout),
            ])->save();
            /** @var ActivityAttempt $attempt */
            $attempt = ActivityAttempt::query()->create([
                'id' => $attemptId,
                'worker_attempt_id' => $workerAttemptId,
                'workflow_run_id' => $run->id,
                'activity_execution_id' => $execution->id,
                'workflow_task_id' => $task->id,
                'attempt_number' => 1,
                'status' => ActivityAttemptStatus::Running,
                'lease_owner' => $leaseOwner,
                'started_at' => $now,
                'last_heartbeat_at' => $now,
                'lease_expires_at' => $task->lease_expires_at,
            ]);
            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
                ...$base,
                'activity_attempt_id' => $attempt->id,
                'worker_attempt_id' => $workerAttemptId,
                'attempt_number' => 1,
                'lease_expires_at' => $task->lease_expires_at->toISOString(),
                'activity' => ActivitySnapshot::fromExecution($execution),
                'activity_attempt' => [
                    'id' => $attempt->id,
                    'worker_attempt_id' => $workerAttemptId,
                    'activity_execution_id' => $execution->id,
                    'task_id' => $task->id,
                    'attempt_number' => 1,
                    'status' => ActivityAttemptStatus::Running->value,
                    'lease_owner' => $leaseOwner,
                    'started_at' => $now->toISOString(),
                    'lease_expires_at' => $task->lease_expires_at->toISOString(),
                ],
            ], $task);

            return self::response(null, $execution, $attempt, task: $task);
        }, 5);
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function normalizeDescriptor(array $descriptor): array
    {
        $allowed = ['type', 'activity_type', 'arguments', 'payload_codec', 'retry_policy',
            'start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout', 'execution_mode'];
        if (($descriptor['type'] ?? null) !== 'record_local_activity'
            || array_diff(array_keys($descriptor), $allowed) !== []
            || (isset($descriptor['execution_mode']) && $descriptor['execution_mode'] !== LocalActivityRuntime::EXECUTION_MODE)) {
            throw ValidationException::withMessages([
                'local_activity' => ['Expected a local activity preparation descriptor.'],
            ]);
        }
        // Common activity input/retry/timeout validation does not need a
        // fabricated outcome or a claim that an application attempt ran.
        $input = $descriptor;
        unset($input['execution_mode']);
        $input['type'] = 'schedule_activity';
        $normalized = WorkflowCommandNormalizer::normalize([$input], self::MINIMUM_PROTOCOL_VERSION)[0];
        if (! is_string($normalized['arguments'] ?? null) || ! is_string($normalized['payload_codec'] ?? null)) {
            throw ValidationException::withMessages([
                'local_activity.arguments' => ['Preparation requires encoded arguments.'],
            ]);
        }
        $normalized['type'] = 'record_local_activity';
        $normalized['execution_mode'] = LocalActivityRuntime::EXECUTION_MODE;

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function duplicate(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        string $workerAttemptId,
        string $fingerprint,
    ): array {
        if (! LocalActivityRuntime::isExecution($execution) || $execution->workflow_run_id !== $run->id
            || $execution->status !== ActivityStatus::Running
            || $attempt->workflow_task_id !== $task->id || $attempt->lease_owner !== $task->lease_owner
            || $attempt->worker_attempt_id !== $workerAttemptId || $attempt->status !== ActivityAttemptStatus::Running) {
            return self::response('local_activity_preparation_mismatch');
        }
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)
            ->where('payload->activity_attempt_id', $attempt->id)
            ->first();
        if (! $started instanceof WorkflowHistoryEvent
            || $started->workflow_task_id !== $task->id
            || ($started->payload['activity_execution_id'] ?? null) !== $execution->id
            || ($started->payload['workflow_task_id'] ?? null) !== $task->id
            || ($started->payload['local_activity'] ?? null) !== true
            || ($started->payload['execution_mode'] ?? null) !== LocalActivityRuntime::EXECUTION_MODE
            || ($started->payload['local_preparation']['descriptor_fingerprint'] ?? null) !== $fingerprint
            || ($started->payload['local_preparation']['worker_attempt_id'] ?? null) !== $workerAttemptId
            || ($started->payload['local_preparation']['workflow_task_attempt'] ?? null) !== $task->attempt_count
            || ($started->payload['task']['id'] ?? null) !== $task->id
            || ($started->payload['task']['type'] ?? null) !== TaskType::Workflow->value
            || ($started->payload['task']['status'] ?? null) !== TaskStatus::Leased->value
            || ($started->payload['task']['attempt_count'] ?? null) !== $task->attempt_count
            || ($started->payload['task']['lease_owner'] ?? null) !== $task->lease_owner) {
            return self::response('local_activity_preparation_mismatch');
        }
        foreach ([
            $execution->close_deadline_at,
            $execution->schedule_to_close_deadline_at,
            $execution->heartbeat_deadline_at,
        ] as $deadline) {
            if ($deadline !== null && now()->gte($deadline)) {
                return self::response('local_activity_deadline_expired');
            }
        }

        return self::response(null, $execution, $attempt, true, $task);
    }

    /**
     * @return array<string, mixed>
     */
    private static function response(
        ?string $reason,
        ?ActivityExecution $execution = null,
        ?ActivityAttempt $attempt = null,
        bool $duplicate = false,
        ?WorkflowTask $task = null,
    ): array {
        return [
            'prepared' => $reason === null,
            'duplicate' => $duplicate,
            'reason' => $reason,
            'activity_execution_id' => $execution?->id,
            'activity_attempt_id' => $attempt?->id,
            'worker_attempt_id' => $attempt?->worker_attempt_id,
            'attempt_number' => $attempt?->attempt_number,
            'workflow_task_id' => $attempt?->workflow_task_id,
            'workflow_task_attempt' => $task?->attempt_count,
            'lease_owner' => $attempt?->lease_owner,
            'lease_expires_at' => $attempt?->lease_expires_at?->toISOString(),
            'start_to_close_deadline_at' => $execution?->close_deadline_at?->toISOString(),
            'schedule_to_close_deadline_at' => $execution?->schedule_to_close_deadline_at?->toISOString(),
            'heartbeat_deadline_at' => $execution?->heartbeat_deadline_at?->toISOString(),
            'server_time' => now()
                ->toISOString(),
        ];
    }
}
