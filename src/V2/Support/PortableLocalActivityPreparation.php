<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
        return self::prepareForContract(
            1,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $workerAttemptId,
            $descriptor,
            $protocolVersion
        );
    }

    /**
     * @internal Unadvertised v2 primitive. A service adapter must separately
     * prove its installed prepared role and original issued claim capability
     * inside the containing transaction before invoking this method.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function prepareWithRetryPolicy(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        string $workerAttemptId,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array {
        return self::prepareForContract(
            2,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $workerAttemptId,
            $descriptor,
            $protocolVersion
        );
    }

    /**
     * @internal The complete group was validated and the caller holds run/task
     * locks inside its atomic checkpoint transaction. This creates no attempt.
     * @param array<string, mixed> $descriptor
     */
    public static function admitGroupMember(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        array $descriptor,
        string $checkpointId,
        string $batchFingerprint
    ): ActivityExecution {
        $normalized = self::normalizeDescriptor($descriptor);
        if (ConfiguredV2Models::connection()->transactionLevel() < 1 || ($normalized['parallel_group_path'] ?? []) === []) {
            throw ValidationException::withMessages([
                'local_activity' => ['Expected an atomic group transaction.'],
            ]);
        }
        if (! CancellationScopeHistory::isRecordedBefore(
            $run,
            $normalized['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID,
            $sequence,
        )) {
            throw ValidationException::withMessages([
                'local_activity.cancellation_scope_id' => ['Scope must be recorded in this run before its operation.'],
            ]);
        }
        $cleanup = PortableLocalActivityCleanup::snapshot(
            $run,
            $normalized['cancellation_cleanup'] ?? null,
            $sequence,
            $normalized['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID,
        );
        $admission = [
            'version' => 1,
            'descriptor_fingerprint' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)),
            'checkpoint_id' => $checkpointId,
            'batch_fingerprint' => $batchFingerprint,
            'workflow_task_attempt' => $task->attempt_count,
            ...($cleanup === null ? [] : [
                'cancellation_cleanup' => $cleanup,
            ]),
        ];
        return self::createExecution(
            $run,
            $task,
            $sequence,
            $normalized,
            $cleanup,
            [
                'local_group_admission' => $admission,
            ]
        );
    }

    /**
     * @internal Recover an interrupted prepared call. This never admits an
     * application callback. A scheduled retry releases this claim, and the SDK
     * returns to polling. A terminal outcome is replayed on the retained claim.
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function recover(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array {
        return self::recoverForContract(
            1,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $descriptor,
            $protocolVersion
        );
    }

    /**
     * @internal Unadvertised recovery entry. The adapter must prove its
     * installed retry role and the retained original capability/contract.
     * Recovery fences publication; it does not prove physical callback stop.
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function recoverWithRetryPolicy(
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        array $descriptor,
        string $protocolVersion = WorkerProtocolVersion::VERSION,
    ): array {
        return self::recoverForContract(
            2,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $descriptor,
            $protocolVersion
        );
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function normalizeDescriptor(array $descriptor, int $localContractVersion = 1): array
    {
        if (! in_array($localContractVersion, [1, 2], true)) {
            throw new InvalidArgumentException('Unknown prepared local activity contract.');
        }
        $allowed = ['type', 'activity_type', 'arguments', 'payload_codec', 'retry_policy',
            'start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout', 'execution_mode',
            'cancellation_cleanup', 'cancellation_policy', 'cancellation_scope_id', 'parallel_group_id', 'parallel_group_kind', 'parallel_group_mode',
            'parallel_group_base_sequence', 'parallel_group_size', 'parallel_group_index', 'parallel_group_path'];
        if (($descriptor['type'] ?? null) !== 'record_local_activity'
            || array_diff(array_keys($descriptor), $allowed) !== []
            || (isset($descriptor['execution_mode']) && $descriptor['execution_mode'] !== LocalActivityRuntime::EXECUTION_MODE)) {
            throw ValidationException::withMessages([
                'local_activity' => ['Expected a local activity preparation descriptor.'],
            ]);
        }
        if (array_key_exists('cancellation_policy', $descriptor)
            && ! in_array($descriptor['cancellation_policy'], ['try_cancel', 'wait_cancellation_completed'], true)) {
            throw ValidationException::withMessages([
                'local_activity.cancellation_policy' => [
                    'Prepared local activities support try_cancel and wait_cancellation_completed. Abandon requires an independent callback lifetime.',
                ],
            ]);
        }
        if (array_key_exists('cancellation_scope_id', $descriptor)
            && (! is_string($descriptor['cancellation_scope_id'])
                || trim($descriptor['cancellation_scope_id']) === ''
                || strlen($descriptor['cancellation_scope_id']) > 255
                || preg_match('//u', $descriptor['cancellation_scope_id']) !== 1)) {
            throw ValidationException::withMessages([
                'local_activity.cancellation_scope_id' => ['Expected a nonempty canonical scope identity.'],
            ]);
        }
        // Common activity input/retry/timeout validation does not need a
        // fabricated outcome or a claim that an application attempt ran.
        $input = $descriptor;
        unset($input['execution_mode'], $input['cancellation_cleanup'], $input['cancellation_scope_id']);
        $retrySnapshot = null;
        if ($localContractVersion === 2) {
            if (! is_array($descriptor['retry_policy'] ?? null)) {
                throw new InvalidArgumentException('Prepared contract 2 requires an explicit version-2 retry policy.');
            }
            $retrySnapshot = PreparedLocalRetryPolicy::normalize($descriptor['retry_policy'], array_intersect_key(
                $descriptor,
                array_flip(['start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout'])
            ));
            unset($input['retry_policy']);
        } elseif (is_array($descriptor['retry_policy'] ?? null)
            && array_intersect(
                array_keys($descriptor['retry_policy']),
                ['contract_version', 'snapshot_version', 'mode', 'maximum_interval_seconds']
            ) !== []) {
            throw ValidationException::withMessages([
                'local_activity.retry_policy' => ['Versioned retry policies require their explicit prepared contract.'],
            ]);
        }
        $input['type'] = 'schedule_activity';
        $normalized = WorkflowCommandNormalizer::normalize([$input], self::MINIMUM_PROTOCOL_VERSION)[0];
        if (! is_string($normalized['arguments'] ?? null) || ! is_string($normalized['payload_codec'] ?? null)) {
            throw ValidationException::withMessages([
                'local_activity.arguments' => ['Preparation requires encoded arguments.'],
            ]);
        }
        $normalized['type'] = 'record_local_activity';
        $normalized['execution_mode'] = LocalActivityRuntime::EXECUTION_MODE;
        if ($retrySnapshot !== null) {
            $normalized['retry_policy'] = $retrySnapshot;
            $normalized['local_activity_contract_version'] = 2;
        }
        if (array_key_exists('cancellation_scope_id', $descriptor)) {
            $normalized['cancellation_scope_id'] = $descriptor['cancellation_scope_id'];
        }
        if (array_key_exists('cancellation_cleanup', $descriptor)) {
            $proof = $descriptor['cancellation_cleanup'];
            if (! is_array($proof) || array_diff(
                array_keys($proof),
                ['request_id', 'delivery_history_event_id', 'scope_id']
            ) !== []
                || ! is_string($proof['request_id'] ?? null) || trim($proof['request_id']) === ''
                || ! is_string($proof['delivery_history_event_id'] ?? null)
                || trim($proof['delivery_history_event_id']) === ''
                || (array_key_exists('scope_id', $proof) && (! is_string($proof['scope_id'])
                    || trim(
                        $proof['scope_id']
                    ) === '' || $proof['scope_id'] === CancellationScopeHistory::ROOT_SCOPE_ID))) {
                throw ValidationException::withMessages([
                    'local_activity.cancellation_cleanup' => ['Expected canonical request and delivery identities.'],
                ]);
            }
            $normalized['cancellation_cleanup'] = [
                'request_id' => $proof['request_id'],
                'delivery_history_event_id' => $proof['delivery_history_event_id'],
                ...(array_key_exists('scope_id', $proof) ? [
                    'scope_id' => $proof['scope_id'],
                ] : []),
            ];
        }

        return $normalized;
    }

    /**
     * @internal Read the original immutable preparation authority. The caller
     * holds attempt/execution/run locks. Current task ownership is separate.
     */
    public static function originalStart(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $localContractVersion = 1,
    ): ?WorkflowHistoryEvent {
        if (! LocalActivityRuntime::isExecution($execution) || $execution->workflow_run_id !== $run->id
            || $attempt->workflow_run_id !== $run->id || $attempt->activity_execution_id !== $execution->id
            || $attempt->lease_owner !== $leaseOwner || $workflowTaskAttempt < 1) {
            return null;
        }
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)
            ->where('payload->activity_attempt_id', $attempt->id)
            ->first();
        if (! $started instanceof WorkflowHistoryEvent
            || $started->workflow_task_id !== $attempt->workflow_task_id
            || ($started->payload['activity_execution_id'] ?? null) !== $execution->id
            || ($started->payload['activity_type'] ?? null) !== $execution->activity_type
            || ($started->payload['activity_class'] ?? null) !== $execution->activity_class
            || ($started->payload['sequence'] ?? null) !== $execution->sequence
            || ($started->payload['workflow_task_id'] ?? null) !== $attempt->workflow_task_id
            || ($started->payload['local_activity'] ?? null) !== true
            || ($started->payload['execution_mode'] ?? null) !== LocalActivityRuntime::EXECUTION_MODE
            || ($started->payload['local_preparation']['version'] ?? null) !== $localContractVersion
            || ! is_string($started->payload['local_preparation']['descriptor_fingerprint'] ?? null)
            || ($started->payload['local_preparation']['worker_attempt_id'] ?? null) !== $attempt->worker_attempt_id
            || ($started->payload['local_preparation']['workflow_task_attempt'] ?? null) !== $workflowTaskAttempt
            || ($started->payload['task']['id'] ?? null) !== $attempt->workflow_task_id
            || ($started->payload['task']['type'] ?? null) !== TaskType::Workflow->value
            || ($started->payload['task']['status'] ?? null) !== TaskStatus::Leased->value
            || ($started->payload['task']['attempt_count'] ?? null) !== $workflowTaskAttempt
            || ($started->payload['task']['lease_owner'] ?? null) !== $leaseOwner
            || ($started->payload['activity_attempt']['id'] ?? null) !== $attempt->id
            || ($started->payload['activity_attempt']['activity_execution_id'] ?? null) !== $execution->id
            || ($started->payload['activity_attempt']['task_id'] ?? null) !== $attempt->workflow_task_id
            || ($started->payload['activity_attempt']['attempt_number'] ?? null) !== $attempt->attempt_number
            || ($started->payload['activity_attempt']['lease_owner'] ?? null) !== $leaseOwner
            || ($started->payload['activity_attempt']['worker_attempt_id'] ?? null) !== $attempt->worker_attempt_id) {
            return null;
        }
        if ($localContractVersion === 2) {
            if (($started->payload['local_preparation']['local_activity_contract_version'] ?? null) !== 2
                || ($execution->activity_options['local_activity_contract_version'] ?? null) !== 2
                || ! is_array($started->payload['activity']['retry_policy'] ?? null)
                || ! is_array($execution->retry_policy)) {
                return null;
            }
            try {
                if (PreparedLocalRetryPolicy::validateSnapshot($execution->retry_policy)
                    !== PreparedLocalRetryPolicy::validateSnapshot($started->payload['activity']['retry_policy'])) {
                    return null;
                }
            } catch (InvalidArgumentException) {
                return null;
            }
        } elseif ($localContractVersion !== 1
            || array_key_exists('local_activity_contract_version', $started->payload['local_preparation'])
            || ($execution->retry_policy['snapshot_version'] ?? null) === 2) {
            return null;
        }
        $scopeId = $execution->activity_options['cancellation_scope_id'] ?? null;
        if (($started->payload['activity']['cancellation_scope_id'] ?? null) !== $scopeId) {
            return null;
        }
        if ($scopeId !== null) {
            $scheduled = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityScheduled)
                ->where('payload->activity_execution_id', $execution->id)
                ->first();
            if (! is_string($scopeId)
                || ! CancellationScopeHistory::isRecordedBefore($run, $scopeId, $execution->sequence)
                || ($scheduled?->payload['activity']['cancellation_scope_id'] ?? null) !== $scopeId) {
                return null;
            }
        }
        if (array_key_exists('schedule_to_close_deadline_at', $started->payload['local_preparation'])
            && $started->payload['local_preparation']['schedule_to_close_deadline_at']
                !== $execution->schedule_to_close_deadline_at?->toISOString()) {
            return null;
        }
        if ((array_key_exists('cancellation_cleanup', $execution->activity_options ?? [])
                || array_key_exists('cancellation_cleanup', $started->payload['local_preparation']))
            && ! PortableLocalActivityCleanup::isExecution($run, $execution, $started)) {
            return null;
        }

        return $started;
    }

    private static function findRecoveryEvent(
        string $runId,
        string $taskId,
        int $epoch,
        string $owner,
        int $sequence,
    ): ?WorkflowHistoryEvent {
        return ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $runId)
            ->whereIn('event_type', [HistoryEventType::ActivityRetryScheduled, HistoryEventType::ActivityFailed,
                HistoryEventType::ActivityTimedOut])
            ->where('payload->sequence', $sequence)
            ->where('payload->local_recovery->workflow_task_id', $taskId)
            ->where('payload->local_recovery->workflow_task_attempt', $epoch)
            ->where('payload->local_recovery->lease_owner', $owner)
            ->orderBy('sequence')
            ->first();
    }

    private static function recoveryStart(
        WorkflowRun $run,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        string $fingerprint,
        int $contract,
    ): ?WorkflowHistoryEvent {
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)
            ->where('payload->activity_attempt_id', $attempt->id)
            ->first();
        $epoch = $started?->payload['local_preparation']['workflow_task_attempt'] ?? null;
        if (! is_int(
            $epoch
        ) || ($started->payload['local_preparation']['descriptor_fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }

        return self::originalStart($run, $execution, $attempt, $attempt->lease_owner, $epoch, $contract);
    }

    private static function matchesRetryRecoveryReceipt(
        WorkflowHistoryEvent $event,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        ?WorkflowHistoryEvent $started,
    ): bool {
        $payload = $event->payload;
        $decision = $payload['retry_disposition'] ?? null;
        $recovery = $payload['local_recovery'] ?? null;
        if ($started === null || ! is_array($decision) || ! is_array($recovery)) {
            return false;
        }
        $connection = ConfiguredV2Models::connection();
        if (! PreparedLocalRetryDecision::matchesStoredInterruption(
            $execution->retry_policy,
            $attempt->attempt_number,
            new PreparedLocalRetryWindow(
                $execution->schedule_to_close_deadline_at,
                PreparedLocalRetryTimestamp::latestForConnection($connection),
                PreparedLocalRetryTimestamp::earliestForDriver($connection->getDriverName())
            ),
            $decision
        ) || ! self::recoveryLeaseMatches($recovery['original_lease_expires_at'] ?? null, $decision['failure_time'])) {
            return false;
        }
        $expected = [
            'version' => 1,
            'descriptor_fingerprint' => $started->payload['local_preparation']['descriptor_fingerprint'],
            'workflow_task_id' => $event->workflow_task_id,
            'workflow_task_attempt' => $payload['task']['attempt_count'],
            'lease_owner' => $payload['task']['lease_owner'],
            'original_workflow_task_id' => $attempt->workflow_task_id,
            'original_workflow_task_attempt' => $started->payload['local_preparation']['workflow_task_attempt'],
            'original_lease_owner' => $attempt->lease_owner,
            'original_lease_expires_at' => $recovery['original_lease_expires_at'],
            'callback_stop_state' => 'unknown',
        ];
        ksort($expected);
        ksort($recovery);

        return $expected === $recovery
            && ($payload['activity_attempt_id'] ?? null) === $attempt->id
            && ($payload['worker_attempt_id'] ?? null) === $attempt->worker_attempt_id
            && ($payload[$event->event_type === HistoryEventType::ActivityRetryScheduled ? 'retry_after_attempt' : 'attempt_number'] ?? null) === $attempt->attempt_number
            && ($payload['activity_attempt']['status'] ?? null) === ActivityAttemptStatus::Expired->value
            && ($payload['activity_attempt']['closed_at'] ?? null) === $decision['failure_time']
            && self::recoveryEventMatches($event, $decision);
    }

    private static function recoveryLeaseMatches(mixed $expiry, string $failure): bool
    {
        if (! is_string($expiry) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $expiry) !== 1) {
            return false;
        }
        $lease = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $expiry, new DateTimeZone('UTC'));

        return $lease instanceof DateTimeImmutable && $lease->format('Y-m-d\TH:i:s.u\Z') === $expiry
            && $expiry <= $failure;
    }

    /**
     * @param array<string, mixed> $decision
     */
    private static function recoveryEventMatches(WorkflowHistoryEvent $event, array $decision): bool
    {
        $payload = $event->payload;
        $timeout = $decision['failure_source'] === 'engine_timeout';
        $scheduled = $decision['decision'] === 'scheduled';
        $type = $scheduled ? HistoryEventType::ActivityRetryScheduled
            : ($timeout ? HistoryEventType::ActivityTimedOut : HistoryEventType::ActivityFailed);

        return $event->event_type === $type
            && ($payload['timeout_kind'] ?? null) === ($decision['timeout_kind'] ?? null)
            && (! $scheduled || (($payload['retry_reason'] ?? null) === ($timeout ? LocalActivityRuntime::RETRY_REASON_TIMEOUT : LocalActivityRuntime::RETRY_REASON_COLD_REPLAY)
                && is_string($payload['retry_task_id'] ?? null) && $payload['retry_task_id'] !== ''
                && ($payload['retry_backoff_seconds'] ?? null) === $decision['candidate_delay_seconds']
                && ($payload['retry_available_at'] ?? null) === $decision['retry_available_at']))
            && ($scheduled || ! array_key_exists('retry_task_id', $payload))
            && ($timeout || (($payload['exception_class'] ?? null) === \RuntimeException::class
                && ($payload['message'] ?? null) === PreparedLocalRetryDecision::INTERRUPTION_MESSAGE));
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private static function recoverForContract(
        int $localContractVersion,
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        array $descriptor,
        string $protocolVersion,
    ): array {
        $refused = static fn (string $reason): array => [
            'recovered' => false,
            'duplicate' => false,
            'reason' => $reason,
            'claim_released' => false,
            'created_task_ids' => [],
        ];
        if (preg_match('/^[0-9]+\.[0-9]+$/D', $protocolVersion) !== 1
            || version_compare($protocolVersion, self::MINIMUM_PROTOCOL_VERSION, '<')) {
            return $refused('local_activity_recovery_requires_protocol_1_20');
        }
        if ($workflowTaskAttempt < 1 || $sequence < 1 || $leaseOwner === '') {
            return $refused('invalid_local_activity_recovery');
        }
        try {
            $fingerprint = hash(
                'sha256',
                json_encode(self::normalizeDescriptor($descriptor, $localContractVersion), JSON_THROW_ON_ERROR)
            );
        } catch (ValidationException|JsonException|InvalidArgumentException) {
            return $refused('invalid_local_activity_recovery');
        }
        /** @var WorkflowTask|null $snapshotTask */
        $snapshotTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($taskId);
        /** @var ActivityExecution|null $snapshotExecution */
        $snapshotExecution = $snapshotTask === null ? null : ActivityExecution::query()
            ->where('workflow_run_id', $snapshotTask->workflow_run_id)
            ->where('sequence', $sequence)
            ->first();
        if ($snapshotTask === null || $snapshotExecution === null
            || ! is_string($snapshotExecution->current_attempt_id)) {
            return $refused('local_activity_preparation_not_found');
        }
        $snapshotAttemptId = $snapshotExecution->current_attempt_id;
        if ($localContractVersion === 2) {
            $prior = self::findRecoveryEvent(
                $snapshotTask->workflow_run_id,
                $taskId,
                $workflowTaskAttempt,
                $leaseOwner,
                $sequence
            );
            if ($prior !== null) {
                $snapshotAttemptId = $prior->payload['activity_attempt_id'] ?? null;
                if (! is_string($snapshotAttemptId) || $snapshotAttemptId === '') {
                    return $refused('local_activity_recovery_mismatch');
                }
            }
        }
        return ConfiguredV2Models::connection()->transaction(static function () use (
            $localContractVersion,
            $snapshotAttemptId,
            $snapshotTask,
            $snapshotExecution,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $fingerprint,
            $refused,
        ): array {
            $rows = ActivityRowLockOrder::lockForAttempt($snapshotAttemptId);
            $execution = $rows['execution'];
            $attempt = $rows['attempt'];
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->find($snapshotTask->workflow_run_id);
            /** @var WorkflowTask|null $task */
            $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($taskId);
            if (! $execution instanceof ActivityExecution || ! $attempt instanceof ActivityAttempt
                || $execution->id !== $snapshotExecution->id || $execution->sequence !== $sequence
                || $run === null || $execution->workflow_run_id !== $run->id
                || $task === null || $task->workflow_run_id !== $run->id) {
                return $refused('local_activity_preparation_not_found');
            }
            $started = null;
            $originalTask = null;
            if ($localContractVersion === 2) {
                $started = self::recoveryStart($run, $execution, $attempt, $fingerprint, 2);
                if ($started === null) {
                    return $refused('local_activity_preparation_mismatch');
                }
                $originalTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->lockForUpdate()
                    ->find($attempt->workflow_task_id);
                if (! $originalTask instanceof WorkflowTask || $originalTask->workflow_run_id !== $run->id
                    || $originalTask->task_type !== TaskType::Workflow) {
                    return $refused('original_workflow_claim_not_found');
                }
            }
            $receipt = self::findRecoveryEvent($run->id, $taskId, $workflowTaskAttempt, $leaseOwner, $sequence);
            if ($receipt instanceof WorkflowHistoryEvent) {
                if (($receipt->payload['local_recovery']['version'] ?? null) !== 1
                    || ($receipt->payload['local_recovery']['descriptor_fingerprint'] ?? null) !== $fingerprint
                    || ($receipt->payload['activity_execution_id'] ?? null) !== $execution->id
                    || $receipt->workflow_task_id !== $taskId
                    || ($receipt->payload['task']['id'] ?? null) !== $taskId
                    || ($receipt->payload['task']['lease_owner'] ?? null) !== $leaseOwner
                    || ($receipt->payload['task']['attempt_count'] ?? null) !== $workflowTaskAttempt
                    || ($receipt->payload['local_recovery']['callback_stop_state'] ?? null) !== 'unknown'
                    || ($localContractVersion === 2
                        && ! self::matchesRetryRecoveryReceipt($receipt, $execution, $attempt, $started))) {
                    return $refused('local_activity_recovery_mismatch');
                }
                return self::recoveryReceipt($receipt, true);
            }
            $sample = $localContractVersion === 2 ? now() : null;
            if ($sample !== null) {
                PreparedLocalRetryTimestamp::addForConnection($sample, 0, ConfiguredV2Models::connection());
            }
            if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased
                || $task->lease_owner !== $leaseOwner || $task->attempt_count !== $workflowTaskAttempt) {
                return $refused('workflow_claim_mismatch');
            }
            if ($task->lease_expires_at === null || ($sample ?? now())->gte($task->lease_expires_at)) {
                return $refused('workflow_claim_expired');
            }
            $started ??= $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityStarted)
                ->where('payload->activity_attempt_id', $attempt->id)
                ->first();
            $originalTaskAttempt = $started?->payload['local_preparation']['workflow_task_attempt'] ?? null;
            if (! is_int($originalTaskAttempt)
                || self::originalStart(
                    $run,
                    $execution,
                    $attempt,
                    $attempt->lease_owner,
                    $originalTaskAttempt,
                    $localContractVersion
                ) === null
                || ($started->payload['local_preparation']['descriptor_fingerprint'] ?? null) !== $fingerprint
                || $execution->current_attempt_id !== $attempt->id || $execution->attempt_count !== $attempt->attempt_number) {
                return $refused('local_activity_preparation_mismatch');
            }
            // Accepted cancellation may fence immediately, without waiting for
            // lease expiry. The replacement claim and root budget stay intact.
            $cleanup = PortableLocalActivityCleanup::isExecution($run, $execution, $started);
            if ($cleanup && ($sample ?? now())->gte(PortableLocalActivityCleanup::deadline($execution))) {
                return $refused(PortableLocalActivityCleanup::isScoped($execution)
                    ? 'local_activity_cleanup_deadline_expired' : 'cancellation_deadline_expired');
            }
            if ($run->cancellation_request_command_id !== null && (! $cleanup
                || ! PortableLocalActivityCleanup::belongsToRunRequest(
                    $run,
                    $execution->activity_options['cancellation_cleanup']
                ))) {
                $cancelled = $run->historyEvents()
                    ->where('event_type', HistoryEventType::ActivityCancelled)
                    ->where('payload->activity_attempt_id', $attempt->id)
                    ->where('payload->workflow_command_id', $run->cancellation_request_command_id)
                    ->first();
                if (! $cancelled instanceof WorkflowHistoryEvent) {
                    if ($execution->status !== ActivityStatus::Running || $attempt->status !== ActivityAttemptStatus::Running) {
                        return $refused('stale_activity_attempt');
                    }
                    $cancelled = ActivityCancellation::record(
                        $run,
                        $execution,
                        command: $run->cancellation_request_command_id
                    );
                }
                return [
                    ...$refused('cancellation_requested'),
                    'fenced' => true,
                    'cancellation_history_event_id' => $cancelled?->id,
                ];
            }
            if ($run->status->isTerminal()) {
                return $refused('run_closed');
            }
            if (($run->execution_deadline_at !== null && ($sample ?? now())->gte($run->execution_deadline_at))
                || ($run->run_deadline_at !== null && ($sample ?? now())->gte($run->run_deadline_at))) {
                return $refused('run_deadline_expired');
            }
            if ($execution->status !== ActivityStatus::Running || $attempt->status !== ActivityAttemptStatus::Running) {
                return $refused('local_activity_previous_attempt_unresolved');
            }
            if (PortableLocalActivityCleanup::currentAuthorityExpired($run, $execution, $sample)) {
                return $refused('local_activity_cleanup_authority_expired');
            }
            if ($attempt->lease_expires_at === null || ($sample ?? now())->lt($attempt->lease_expires_at)) {
                return $refused('local_activity_previous_attempt_live');
            }
            /** @var WorkflowTask|null $originalTask */
            $originalTask ??= ConfiguredV2Models::query('task_model', WorkflowTask::class)
                ->lockForUpdate()
                ->find($attempt->workflow_task_id);
            if ($originalTask === null || $originalTask->workflow_run_id !== $run->id
                || $originalTask->task_type !== TaskType::Workflow) {
                return $refused('original_workflow_claim_not_found');
            }
            if ($originalTask->id === $task->id) {
                if ($task->attempt_count <= $originalTaskAttempt) {
                    return $refused('local_activity_original_claim_not_reclaimed');
                }
            } elseif ($originalTask->status === TaskStatus::Leased && $originalTask->lease_expires_at !== null
                && ($sample ?? now())
                    ->lt($originalTask->lease_expires_at)) {
                return $refused('local_activity_original_claim_live');
            }
            $recovery = [
                'version' => 1,
                'descriptor_fingerprint' => $fingerprint,
                'workflow_task_id' => $task->id,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'lease_owner' => $leaseOwner,
                'original_workflow_task_id' => $originalTask->id,
                'original_workflow_task_attempt' => $originalTaskAttempt,
                'original_lease_owner' => $attempt->lease_owner,
                'original_lease_expires_at' => $attempt->lease_expires_at->toISOString(),
                'callback_stop_state' => 'unknown',
            ];
            if ($originalTask->id !== $task->id && in_array(
                $originalTask->status,
                [TaskStatus::Ready, TaskStatus::Leased],
                true
            )) {
                $originalTask->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();
            }
            $executor = app(LocalActivityExecutor::class);
            $outcome = $sample === null ? $executor->recoverPortableAttempt(
                $run,
                $task,
                $execution,
                $attempt,
                $recovery
            )
                : $executor->recoverPortableRetryAttempt($run, $task, $execution, $attempt, $recovery, $sample);

            return self::recoveryReceipt($outcome['event'], false);
        }, 5);
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private static function prepareForContract(
        int $localContractVersion,
        string $taskId,
        string $leaseOwner,
        int $workflowTaskAttempt,
        int $sequence,
        string $workerAttemptId,
        array $descriptor,
        string $protocolVersion,
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
            $normalized = self::normalizeDescriptor($descriptor, $localContractVersion);
            $fingerprint = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        } catch (ValidationException|JsonException) {
            return self::response('invalid_local_activity_preparation');
        } catch (InvalidArgumentException $error) {
            if ($localContractVersion === 1) {
                throw $error;
            }
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

        return ConfiguredV2Models::connection()->transaction(static function () use (
            $snapshotTask,
            $snapshotExecution,
            $taskId,
            $leaseOwner,
            $workflowTaskAttempt,
            $sequence,
            $workerAttemptId,
            $normalized,
            $fingerprint,
            $localContractVersion,
        ): array {
            // Follow the shared attempt/execution/run/task lock order whenever
            // an execution exists. An unseen concurrent creation is retried.
            $rows = $snapshotExecution === null ? null : (
                is_string($snapshotExecution->current_attempt_id)
                    ? ActivityRowLockOrder::lockForAttempt($snapshotExecution->current_attempt_id)
                    : ActivityRowLockOrder::lockForExecution($snapshotExecution->id)
            );
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
            $preparedAt = $localContractVersion === 2 ? now() : null;
            if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased
                || $task->lease_owner !== $leaseOwner || $task->attempt_count !== $workflowTaskAttempt) {
                return self::response('workflow_claim_mismatch');
            }
            if ($task->lease_expires_at === null || ($preparedAt ?? now())->gte($task->lease_expires_at)) {
                return self::response('workflow_claim_expired');
            }
            if ($run->status->isTerminal()) {
                return self::response('run_closed');
            }
            if (! CancellationScopeHistory::isRecordedBefore(
                $run,
                $normalized['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID,
                $sequence,
            )) {
                return self::response('local_activity_scope_not_recorded');
            }
            $cleanup = PortableLocalActivityCleanup::snapshot(
                $run,
                $normalized['cancellation_cleanup'] ?? null,
                $sequence,
                $normalized['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID,
            );
            if (isset($normalized['cancellation_cleanup']) && $cleanup === null) {
                return self::response('local_activity_cleanup_authority_mismatch');
            }
            $scopeRefusal = CancellationScopeDelivery::admissionRefusal(
                $run,
                $normalized['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID,
                $sequence,
                $normalized['cancellation_cleanup'] ?? null,
            );
            if ($scopeRefusal !== null) {
                return self::response($scopeRefusal);
            }
            $scopedCleanup = isset($cleanup['scope_id']);
            if ($run->cancellation_request_command_id !== null
                && ! PortableLocalActivityCleanup::belongsToRunRequest($run, $cleanup)) {
                return self::response(isset($normalized['cancellation_cleanup'])
                    && ! $scopedCleanup ? 'local_activity_cleanup_authority_mismatch' : 'cancellation_requested');
            }
            if ($run->cancellation_request_command_id === null && isset($normalized['cancellation_cleanup']) && ! $scopedCleanup) {
                return self::response('local_activity_cleanup_authority_mismatch');
            }
            if ($cleanup !== null && ($preparedAt ?? now())->gte(
                PortableLocalActivityCleanup::snapshotDeadline($cleanup)
            )) {
                return self::response(
                    $scopedCleanup ? 'local_activity_cleanup_deadline_expired' : 'cancellation_deadline_expired'
                );
            }
            if (($run->execution_deadline_at !== null && ($preparedAt ?? now())->gte($run->execution_deadline_at))
                || ($run->run_deadline_at !== null && ($preparedAt ?? now())->gte($run->run_deadline_at))) {
                return self::response('run_deadline_expired');
            }
            if ($scopedCleanup && ($preparedAt ?? now())->gte(
                PortableLocalActivityCleanup::currentDeadline($run, $cleanup)
            )) {
                return self::response('local_activity_cleanup_authority_expired');
            }
            $execution = $rows['execution'] ?? null;
            $attempt = $rows['attempt'] ?? null;
            if ($execution instanceof ActivityExecution
                && ($execution->activity_options['local_activity_contract_version'] ?? 1) !== $localContractVersion) {
                return self::response('local_activity_preparation_mismatch');
            }
            if ($snapshotExecution !== null) {
                if (! $execution instanceof ActivityExecution
                    || $execution->id !== $snapshotExecution->id || $execution->sequence !== $sequence
                    || $execution->current_attempt_id !== ($rows['snapshot_attempt_id'] ?? null)) {
                    return self::response('local_activity_previous_attempt_unresolved');
                }
                if ($attempt === null) {
                    return self::prepareAdmittedMember(
                        $run,
                        $task,
                        $execution,
                        $normalized,
                        $workerAttemptId,
                        $fingerprint
                    );
                }
                if (! $attempt instanceof ActivityAttempt) {
                    return self::response('local_activity_previous_attempt_unresolved');
                }
                if ($execution->status === ActivityStatus::Pending) {
                    return self::prepareRetry(
                        $run,
                        $task,
                        $execution,
                        $attempt,
                        $workerAttemptId,
                        $fingerprint,
                        $localContractVersion,
                        $preparedAt
                    );
                }
                return self::duplicate(
                    $run,
                    $task,
                    $execution,
                    $attempt,
                    $workerAttemptId,
                    $fingerprint,
                    $localContractVersion,
                    $preparedAt
                );
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
            if (($normalized['parallel_group_path'] ?? []) !== []) {
                return self::response('local_activity_group_not_admitted');
            }
            $preparation = [
                'version' => $localContractVersion,
                ...($localContractVersion === 2 ? [
                    'local_activity_contract_version' => 2,
                ] : []),
                'descriptor_fingerprint' => $fingerprint,
                'worker_attempt_id' => $workerAttemptId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                ...($cleanup === null ? [] : [
                    'cancellation_cleanup' => $cleanup,
                ]),
            ];
            $execution = self::createExecution(
                $run,
                $task,
                $sequence,
                $normalized,
                $cleanup,
                [
                    'local_preparation' => $preparation,
                ],
                $preparedAt
            );
            $attempt = app(LocalActivityExecutor::class)->startPortableAttempt(
                $run,
                $task,
                $execution,
                $workerAttemptId,
                $preparation,
                $preparedAt
            );

            return self::response(null, $execution, $attempt, task: $task);
        }, 5);
    }

    /** @param array<string, mixed> $normalized
     * @param array<string, mixed>|null $cleanup
     * @param array<string, mixed> $opening
     */
    private static function createExecution(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        array $normalized,
        ?array $cleanup,
        array $opening,
        ?Carbon $preparedAt = null,
    ): ActivityExecution {
        StructuralLimits::guardPendingActivities($run);
        $now = $preparedAt ?? now();
        if (($normalized['local_activity_contract_version'] ?? null) === 2) {
            $connection = ConfiguredV2Models::connection();
            foreach (['start_to_close_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout'] as $field) {
                PreparedLocalRetryTimestamp::addForConnection(
                    $now,
                    $normalized['retry_policy'][$field] ?? 0,
                    $connection
                );
            }
        }
        $codec = $normalized['payload_codec'];
        $arguments = ExternalPayloads::externalizeForNamespace(
            $normalized['arguments'],
            $codec,
            is_string($run->namespace) ? $run->namespace : null
        );
        StructuralLimits::guardPayloadSize($arguments);
        $options = new ActivityOptions(
            startToCloseTimeout: $normalized['start_to_close_timeout'] ?? null,
            scheduleToCloseTimeout: $normalized['schedule_to_close_timeout'] ?? null,
            heartbeatTimeout: $normalized['heartbeat_timeout'] ?? null,
        );
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
            'parallel_group_path' => $normalized['parallel_group_path'] ?? null,
            'retry_policy' => ($normalized['local_activity_contract_version'] ?? null) === 2
                ? PreparedLocalRetryPolicy::validateSnapshot($normalized['retry_policy'])
                : ActivityRetryPolicy::snapshotExternal($normalized['retry_policy'] ?? null, $options),
            'activity_options' => [
                'execution_mode' => LocalActivityRuntime::EXECUTION_MODE,
                'queue_bypassed' => true,
                'routing' => 'workflow_worker_process',
                ...(($normalized['local_activity_contract_version'] ?? null) === 2
                    ? [
                        'local_activity_contract_version' => 2,
                    ] : []),
                ...(isset($normalized['cancellation_policy']) ? [
                    'cancellation_policy' => $normalized['cancellation_policy'],
                ] : []),
                ...(isset($normalized['cancellation_scope_id']) ? [
                    'cancellation_scope_id' => $normalized['cancellation_scope_id'],
                ] : []),
                ...($cleanup === null ? [] : [
                    'cancellation_cleanup' => $cleanup,
                ]),
            ],
            'schedule_to_close_deadline_at' => $cleanup === null ? ($options->scheduleToCloseTimeout === null
                ? null : $now->copy()
                    ->addSeconds($options->scheduleToCloseTimeout))
                : ($options->scheduleToCloseTimeout === null ? PortableLocalActivityCleanup::currentDeadline(
                    $run,
                    $cleanup
                )
                    : $now->copy()
                        ->addSeconds($options->scheduleToCloseTimeout)
                        ->min(PortableLocalActivityCleanup::currentDeadline($run, $cleanup))),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, LocalActivityRuntime::eventPayload([
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $sequence,
            'workflow_task_id' => $task->id,
            'activity' => ActivitySnapshot::fromExecution($execution),
            ...$opening,
        ]), $task);
        return $execution;
    }

    /** @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    private static function prepareAdmittedMember(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        array $normalized,
        string $workerAttemptId,
        string $fingerprint
    ): array {
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)
            ->where('payload->activity_execution_id', $execution->id)
            ->first();
        $admission = $scheduled?->payload['local_group_admission'] ?? [];
        $originalTask = $scheduled === null ? null
            : ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($scheduled->workflow_task_id);
        $receipt = $originalTask?->payload['portable_local_group_checkpoint'] ?? [];
        $members = $receipt['local_activities'] ?? [];
        $memberMatches = false;
        foreach ($members as $member) {
            if (is_array($member) && ($member['sequence'] ?? null) === $execution->sequence
                && ($member['activity_execution_id'] ?? null) === $execution->id) {
                $memberMatches = true;
            }
        }
        $path = $normalized['parallel_group_path'] ?? [];
        $cleanup = PortableLocalActivityCleanup::snapshot(
            $run,
            $normalized['cancellation_cleanup'] ?? null,
            $execution->sequence,
            $normalized['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID,
        );
        $storedCleanup = $execution->activity_options['cancellation_cleanup'] ?? null;
        if (is_array($cleanup)) {
            ksort($cleanup);
        }
        if (is_array($storedCleanup)) {
            ksort($storedCleanup);
        }
        if (! LocalActivityRuntime::isExecution($execution) || $execution->status !== ActivityStatus::Pending
            || $execution->attempt_count !== 0 || $execution->current_attempt_id !== null
            || ! $scheduled instanceof WorkflowHistoryEvent || ($admission['version'] ?? null) !== 1
            || ($admission['descriptor_fingerprint'] ?? null) !== $fingerprint
            || ($scheduled->payload['activity']['cancellation_scope_id'] ?? null)
                !== ($execution->activity_options['cancellation_scope_id'] ?? null)
            || ($admission['checkpoint_id'] ?? null) !== ($receipt['checkpoint_id'] ?? null)
            || ($admission['batch_fingerprint'] ?? null) !== ($receipt['fingerprint'] ?? null)
            || ($admission['workflow_task_attempt'] ?? null) !== ($receipt['workflow_task_attempt'] ?? null)
            || ($scheduled->payload['task']['attempt_count'] ?? null) !== ($receipt['workflow_task_attempt'] ?? null)
            || ($scheduled->payload['task']['lease_owner'] ?? null) !== ($receipt['lease_owner'] ?? null)
            || ($receipt['task_id'] ?? null) !== $scheduled->workflow_task_id
            || ($receipt['workflow_run_id'] ?? null) !== $run->id
            || ! $memberMatches
            || ($scheduled->payload['sequence'] ?? null) !== $execution->sequence
            || ($scheduled->payload['local_activity'] ?? null) !== true
            || $storedCleanup !== $cleanup
            || $path === [] || ! self::sameGroupPath($execution->parallel_group_path, $path)
            || ! self::sameGroupPath($scheduled->payload['parallel_group_path'] ?? null, $path)) {
            return self::response('local_activity_group_admission_mismatch');
        }
        if ($execution->schedule_to_close_deadline_at !== null && now()->gte(
            $execution->schedule_to_close_deadline_at
        )) {
            $event = app(LocalActivityExecutor::class)->expirePortableRetry($run, $task, $execution);
            return [
                ...self::response('local_activity_deadline_expired'),
                'event_id' => $event->id,
                'event_type' => $event->event_type->value,
            ];
        }
        $attempt = app(LocalActivityExecutor::class)->startPortableAttempt($run, $task, $execution, $workerAttemptId, [
            'version' => 1,
            'descriptor_fingerprint' => $fingerprint,
            'worker_attempt_id' => $workerAttemptId,
            'workflow_task_attempt' => $task->attempt_count,
            ...(isset($execution->activity_options['cancellation_cleanup'])
                ? [
                    'cancellation_cleanup' => $execution->activity_options['cancellation_cleanup'],
                ] : []),
        ]);
        return self::response(null, $execution, $attempt, task: $task);
    }

    /** Object member order can change in database JSON. List order, values and
     * scalar types retain authority and must match exactly.
     * @param list<array<string, mixed>> $expected
     */
    private static function sameGroupPath(mixed $actual, array $expected): bool
    {
        if (! is_array($actual) || ! array_is_list($actual) || count($actual) !== count($expected)) {
            return false;
        }
        foreach ($actual as $offset => $entry) {
            if (! is_array($entry)) {
                return false;
            }
            ksort($entry);
            $other = $expected[$offset];
            ksort($other);
            if ($entry !== $other) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function prepareRetry(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        ActivityAttempt $previous,
        string $workerAttemptId,
        string $fingerprint,
        int $localContractVersion = 1,
        ?Carbon $preparedAt = null,
    ): array {
        $started = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)
            ->where('payload->activity_attempt_id', $previous->id)
            ->first();
        $originalTaskAttempt = $started?->payload['local_preparation']['workflow_task_attempt'] ?? null;
        if (! LocalActivityRuntime::isExecution($execution) || $execution->workflow_run_id !== $run->id
            || ! in_array($previous->status, [ActivityAttemptStatus::Failed, ActivityAttemptStatus::Expired], true)
            || $previous->closed_at === null
            || $execution->attempt_count !== $previous->attempt_number
            || ! is_int($originalTaskAttempt)
            || self::originalStart(
                $run,
                $execution,
                $previous,
                $previous->lease_owner,
                $originalTaskAttempt,
                $localContractVersion
            ) === null
            || ($started->payload['local_preparation']['descriptor_fingerprint'] ?? null) !== $fingerprint) {
            return self::response('local_activity_retry_preparation_mismatch');
        }
        $retry = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityRetryScheduled)
            ->where('payload->activity_attempt_id', $previous->id)
            ->first();
        if (! $retry instanceof WorkflowHistoryEvent || $retry->sequence <= $started->sequence
            || ($retry->payload['activity_execution_id'] ?? null) !== $execution->id
            || ! self::retryTaskMatches($run, $task, $execution, $retry)
            || ($retry->payload['retry_of_task_id'] ?? null) !== $retry->workflow_task_id
            || ($retry->payload['retry_after_attempt_id'] ?? null) !== $previous->id
            || ($retry->payload['retry_after_attempt'] ?? null) !== $previous->attempt_number
            || ($retry->payload['retry_policy'] ?? null) !== $execution->retry_policy
            || ! self::retryAuthorityMatches(
                $retry,
                $previous,
                $originalTaskAttempt,
                $fingerprint,
                $localContractVersion
            )
            || ! is_string($retry->payload['retry_available_at'] ?? null)
            || $execution->attempt_count >= ActivityRetryPolicy::maxAttemptsFromSnapshot($execution)) {
            return self::response('local_activity_retry_preparation_mismatch');
        }
        if ($execution->attempts()->where('worker_attempt_id', $workerAttemptId)->exists()) {
            return self::response('local_activity_retry_worker_attempt_reused');
        }
        // The durable receipt owns backoff. Mutable task availability cannot
        // admit a callback early. Total timeout owns the entire retry chain.
        if ($execution->schedule_to_close_deadline_at !== null && ($preparedAt ?? now())->gte(
            $execution->schedule_to_close_deadline_at
        )) {
            $event = app(LocalActivityExecutor::class)->expirePortableRetry(
                $run,
                $task,
                $execution,
                $localContractVersion,
                $preparedAt
            );
            return [
                ...self::response('local_activity_deadline_expired'),
                'event_id' => $event->id,
                'event_type' => $event->event_type->value,
            ];
        }
        if (($preparedAt ?? now())->lt(Carbon::parse($retry->payload['retry_available_at']))) {
            return self::response('local_activity_retry_not_due');
        }
        $attempt = app(LocalActivityExecutor::class)->startPortableAttempt($run, $task, $execution, $workerAttemptId, [
            'version' => $localContractVersion,
            ...($localContractVersion === 2 ? [
                'local_activity_contract_version' => 2,
            ] : []),
            'descriptor_fingerprint' => $fingerprint,
            'worker_attempt_id' => $workerAttemptId,
            'workflow_task_attempt' => $task->attempt_count,
            ...(isset($execution->activity_options['cancellation_cleanup'])
                ? [
                    'cancellation_cleanup' => $execution->activity_options['cancellation_cleanup'],
                ] : []),
        ], $preparedAt);

        return self::response(null, $execution, $attempt, task: $task);
    }

    /** A sibling's cold recovery releases the shared hosting claim. Only its
     * immutable retry chain within the same admitted batch can transfer the
     * earlier member's retry authority to the final replacement claim.
     */
    private static function retryTaskMatches(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        WorkflowHistoryEvent $retry
    ): bool {
        $cursor = $retry->payload['retry_task_id'] ?? null;
        if ($cursor === $task->id) {
            return true;
        }
        $admission = self::groupAdmission($run, $execution);
        if (! is_string($cursor) || $cursor === '' || $admission === null
            || ($retry->payload['local_recovery']['version'] ?? null) !== 1) {
            return false;
        }
        $after = $retry->sequence;
        $visited = [];
        for ($count = 0; $count < 100; ++$count) {
            if (isset($visited[$cursor])) {
                return false;
            }
            $visited[$cursor] = true;
            $previousTask = ConfiguredV2Models::query('task_model', WorkflowTask::class)->find($cursor);
            $links = $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)
                ->where('workflow_task_id', $cursor)
                ->where('sequence', '>', $after)
                ->get();
            if ($previousTask === null || $previousTask->workflow_run_id !== $run->id
                || $previousTask->task_type !== TaskType::Workflow || $previousTask->status !== TaskStatus::Completed
                || $links->count() !== 1) {
                return false;
            }
            $link = $links->sole();
            $payload = $link->payload;
            $sibling = ActivityExecution::query()->find($payload['activity_execution_id'] ?? null);
            $attempt = ActivityAttempt::query()->find($payload['activity_attempt_id'] ?? null);
            $started = $attempt === null ? null : $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityStarted)
                ->where('payload->activity_attempt_id', $attempt->id)
                ->first();
            $epoch = $started?->payload['local_preparation']['workflow_task_attempt'] ?? null;
            $fingerprint = $started?->payload['local_preparation']['descriptor_fingerprint'] ?? null;
            $next = $payload['retry_task_id'] ?? null;
            if (! $sibling instanceof ActivityExecution || ! $attempt instanceof ActivityAttempt
                || self::groupAdmission($run, $sibling) !== $admission
                || $attempt->status !== ActivityAttemptStatus::Expired || $attempt->closed_at === null
                || ! is_int($epoch) || ! is_string($fingerprint)
                || self::originalStart($run, $sibling, $attempt, $attempt->lease_owner, $epoch) === null
                || ($payload['local_recovery']['version'] ?? null) !== 1
                || ! self::retryAuthorityMatches($link, $attempt, $epoch, $fingerprint)
                || ($payload['retry_of_task_id'] ?? null) !== $cursor
                || ($payload['retry_after_attempt_id'] ?? null) !== $attempt->id
                || ($payload['retry_after_attempt'] ?? null) !== $attempt->attempt_number
                || ! is_string($next) || $next === '' || isset($visited[$next])) {
                return false;
            }
            if ($next === $task->id) {
                return true;
            }
            $cursor = $next;
            $after = $link->sequence;
        }
        return false;
    }

    /** @return array{task_id: string, checkpoint_id: string, batch_fingerprint: string, workflow_task_attempt: int}|null
     */
    private static function groupAdmission(WorkflowRun $run, ActivityExecution $execution): ?array
    {
        if ($execution->workflow_run_id !== $run->id || ($execution->parallel_group_path ?? []) === []) {
            return null;
        }
        $scheduled = $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityScheduled)
            ->where('payload->activity_execution_id', $execution->id)
            ->first();
        $admission = $scheduled?->payload['local_group_admission'] ?? [];
        if (! $scheduled instanceof WorkflowHistoryEvent || ! is_string($scheduled->workflow_task_id)
            || ($admission['version'] ?? null) !== 1
            || ! is_string($admission['checkpoint_id'] ?? null) || $admission['checkpoint_id'] === ''
            || ! is_string($admission['batch_fingerprint'] ?? null) || $admission['batch_fingerprint'] === ''
            || ! is_int($admission['workflow_task_attempt'] ?? null)
            || $admission['workflow_task_attempt'] < 1) {
            return null;
        }
        return [
            'task_id' => $scheduled->workflow_task_id,
            'checkpoint_id' => $admission['checkpoint_id'],
            'batch_fingerprint' => $admission['batch_fingerprint'],
            'workflow_task_attempt' => $admission['workflow_task_attempt'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function retryAuthorityMatches(
        WorkflowHistoryEvent $retry,
        ActivityAttempt $previous,
        int $originalTaskAttempt,
        string $fingerprint,
        int $localContractVersion = 1,
    ): bool {
        $payload = $retry->payload;
        if (($payload['local_outcome']['version'] ?? null) === $localContractVersion) {
            return $previous->status === ActivityAttemptStatus::Failed
                && $retry->workflow_task_id === $previous->workflow_task_id
                && ($payload['local_outcome']['workflow_task_attempt'] ?? null) === $originalTaskAttempt
                && ($payload['task']['lease_owner'] ?? null) === $previous->lease_owner
                && ($payload['task']['attempt_count'] ?? null) === $originalTaskAttempt
                && ($localContractVersion === 1 || (
                    ($payload['retry_disposition']['contract_version'] ?? null) === 2
                    && ($payload['retry_disposition']['snapshot_version'] ?? null) === 2
                    && ($payload['retry_disposition']['decision'] ?? null) === 'scheduled'
                    && ($payload['retry_disposition']['candidate_delay_seconds'] ?? null)
                        === ($payload['retry_backoff_seconds'] ?? null)
                    && ($payload['retry_disposition']['retry_available_at'] ?? null)
                        === ($payload['retry_available_at'] ?? null)
                ));
        }
        if ($localContractVersion !== 1) {
            return false;
        }
        $recovery = $payload['local_recovery'] ?? [];
        return $previous->status === ActivityAttemptStatus::Expired
            && ($recovery['version'] ?? null) === 1
            && ($recovery['descriptor_fingerprint'] ?? null) === $fingerprint
            && ($recovery['original_workflow_task_id'] ?? null) === $previous->workflow_task_id
            && ($recovery['original_workflow_task_attempt'] ?? null) === $originalTaskAttempt
            && ($recovery['original_lease_owner'] ?? null) === $previous->lease_owner
            && ($recovery['callback_stop_state'] ?? null) === 'unknown'
            && ($recovery['workflow_task_id'] ?? null) === $retry->workflow_task_id
            && ($payload['task']['id'] ?? null) === $retry->workflow_task_id
            && is_int($recovery['workflow_task_attempt'] ?? null)
            && ($payload['task']['attempt_count'] ?? null) === $recovery['workflow_task_attempt']
            && is_string($recovery['lease_owner'] ?? null)
            && ($payload['task']['lease_owner'] ?? null) === $recovery['lease_owner'];
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
        int $localContractVersion = 1,
        ?Carbon $preparedAt = null,
    ): array {
        if (! LocalActivityRuntime::isExecution($execution) || $execution->workflow_run_id !== $run->id
            || $execution->status !== ActivityStatus::Running
            || $attempt->workflow_task_id !== $task->id || $attempt->lease_owner !== $task->lease_owner
            || $attempt->worker_attempt_id !== $workerAttemptId || $attempt->status !== ActivityAttemptStatus::Running) {
            return self::response('local_activity_preparation_mismatch');
        }
        $started = self::originalStart(
            $run,
            $execution,
            $attempt,
            $task->lease_owner,
            $task->attempt_count,
            $localContractVersion
        );
        if (! $started instanceof WorkflowHistoryEvent
            || ($started->payload['local_preparation']['descriptor_fingerprint'] ?? null) !== $fingerprint) {
            return self::response('local_activity_preparation_mismatch');
        }
        if ($attempt->lease_expires_at === null || ($preparedAt ?? now())->gte($attempt->lease_expires_at)) {
            return self::response('local_activity_preparation_lease_expired');
        }
        foreach ([
            $execution->close_deadline_at,
            $execution->schedule_to_close_deadline_at,
            $execution->heartbeat_deadline_at,
        ] as $deadline) {
            if ($deadline !== null && ($preparedAt ?? now())->gte($deadline)) {
                return self::response('local_activity_deadline_expired');
            }
        }

        return self::response(null, $execution, $attempt, true, $task);
    }

    /**
     * @return array<string, mixed>
     */
    private static function recoveryReceipt(WorkflowHistoryEvent $event, bool $duplicate): array
    {
        $retryTaskId = $event->payload['retry_task_id'] ?? null;

        return [
            'recovered' => true,
            'duplicate' => $duplicate,
            'reason' => null,
            'event_id' => $event->id,
            'event_type' => $event->event_type->value,
            'activity_execution_id' => $event->payload['activity_execution_id'],
            'activity_attempt_id' => $event->payload['activity_attempt_id'],
            'workflow_task_id' => $event->workflow_task_id,
            'recorded_at' => $event->recorded_at->toISOString(),
            'callback_stop_state' => 'unknown',
            'claim_released' => $event->event_type === HistoryEventType::ActivityRetryScheduled,
            'created_task_ids' => is_string($retryTaskId) ? [$retryTaskId] : [],
            ...(isset($event->payload['retry_disposition']) ? [
                'retry_disposition' => $event->payload['retry_disposition'],
            ] : []),
        ];
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
            'cancellation_cleanup' => $execution?->activity_options['cancellation_cleanup'] ?? null,
            ...(isset($execution?->activity_options['cancellation_scope_id']) ? [
                'cancellation_scope_id' => $execution->activity_options['cancellation_scope_id'],
            ] : []),
            'server_time' => now()
                ->toISOString(),
        ];
    }
}
