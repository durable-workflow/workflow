<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;

final class ActivityOutcomeRecorder
{
    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    public static function record(
        string $taskId,
        string $attemptId,
        int $attemptCount,
        mixed $result,
        ?Throwable $throwable,
        int $maxAttempts,
        int $backoffSeconds,
        ?string $codec = null,
    ): array {
        return DB::transaction(static function () use (
            $taskId,
            $attemptId,
            $attemptCount,
            $result,
            $throwable,
            $maxAttempts,
            $backoffSeconds,
            $codec,
        ): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->find($taskId);

            if (! $task instanceof WorkflowTask) {
                return self::ignored('task_not_found');
            }

            $activityExecutionId = $task->payload['activity_execution_id'] ?? null;

            if (! is_string($activityExecutionId) || $activityExecutionId === '') {
                return self::ignored('activity_execution_missing');
            }

            /** @var ActivityExecution $lockedExecution */
            $lockedExecution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($activityExecutionId);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($lockedExecution->workflow_run_id);

            if (in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)) {
                $reason = $run->status === RunStatus::Terminated
                    ? 'run_terminated'
                    : 'run_cancelled';

                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Cancelled,
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                self::closeAttempt($attemptId, ActivityAttemptStatus::Cancelled);

                $task->forceFill([
                    'status' => TaskStatus::Cancelled,
                    'lease_expires_at' => null,
                ])->save();

                ActivityCancellation::record($run, $lockedExecution, $task);

                self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::ignored($reason);
            }

            // Ignore late activity outcomes once the lease has been reclaimed or a newer
            // attempt has already been claimed for this execution.
            if (
                $task->status !== TaskStatus::Leased
                || $task->attempt_count !== $attemptCount
                || $lockedExecution->attempt_count !== $attemptCount
                || $lockedExecution->current_attempt_id !== $attemptId
            ) {
                self::closeAttemptIfStale($run, $attemptId);

                return self::ignored('stale_attempt');
            }

            $runCodec = is_string($run->payload_codec) && $run->payload_codec !== ''
                ? $run->payload_codec
                : null;
            $encodedSuccessfulResult = null;

            if ($throwable === null) {
                $encodedSuccessfulResult = self::serializeWithCodec(
                    $result,
                    $codec,
                    self::preferredPayloadCodec($lockedExecution, $runCodec),
                );
                $encodedSuccessfulResult['blob'] = ExternalPayloads::externalizeForNamespace(
                    $encodedSuccessfulResult['blob'],
                    $encodedSuccessfulResult['codec'],
                    is_string($run->namespace) ? $run->namespace : null,
                );

                StructuralLimits::logWarning(
                    StructuralLimits::warnApproachingPayloadSize($encodedSuccessfulResult['blob']),
                    [
                        'workflow_run_id' => $run->id,
                        'workflow_type' => $run->workflow_type,
                        'payload_site' => 'activity_output',
                        'activity_class' => $lockedExecution->activity_class,
                        'activity_type' => $lockedExecution->activity_type,
                        'activity_execution_id' => $lockedExecution->id,
                    ],
                );

                try {
                    StructuralLimits::guardPayloadSize($encodedSuccessfulResult['blob']);
                } catch (StructuralLimitExceededException $limitExceeded) {
                    $throwable = $limitExceeded;
                    $encodedSuccessfulResult = null;
                }
            }

            if (in_array($run->status, [RunStatus::Completed, RunStatus::Failed], true)) {
                $lockedExecution->forceFill([
                    'status' => $throwable === null ? ActivityStatus::Completed : ActivityStatus::Failed,
                    'result' => $throwable === null
                        ? $encodedSuccessfulResult['blob']
                        : $lockedExecution->result,
                    'payload_codec' => $throwable === null
                        ? $encodedSuccessfulResult['codec']
                        : $lockedExecution->payload_codec,
                    'exception' => $throwable === null
                        ? $lockedExecution->exception
                        : self::serializeWithCodec(self::failurePayload($throwable, $codec), null, $runCodec)['blob'],
                    'closed_at' => $lockedExecution->closed_at ?? now(),
                ])->save();

                self::closeAttempt(
                    $attemptId,
                    $throwable === null ? ActivityAttemptStatus::Completed : ActivityAttemptStatus::Failed,
                );

                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded(null);
            }

            $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence(
                $run,
                (int) $lockedExecution->sequence,
            );
            $parallelMetadata = ParallelChildGroup::payloadForPath($parallelMetadataPath);
            $resolutionEvent = null;

            if ($throwable === null) {
                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Completed,
                    'result' => $encodedSuccessfulResult['blob'],
                    'payload_codec' => $encodedSuccessfulResult['codec'],
                    'exception' => null,
                    'closed_at' => now(),
                ])->save();

                $resolutionEvent = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_attempt_id' => $attemptId,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'attempt_number' => $attemptCount,
                    'result' => ExternalPayloads::historyValue(
                        $lockedExecution->result,
                        $encodedSuccessfulResult['codec'],
                        is_string($run->namespace) ? $run->namespace : null,
                    ),
                    'payload_codec' => $encodedSuccessfulResult['codec'],
                    'activity' => ActivitySnapshot::fromExecution($lockedExecution),
                ], $parallelMetadata ?? []), $task);

                LifecycleEventDispatcher::activityCompleted(
                    $run,
                    (string) $lockedExecution->id,
                    (string) ($lockedExecution->activity_type ?? $lockedExecution->activity_class),
                    (string) $lockedExecution->activity_class,
                    (int) $lockedExecution->sequence,
                    $attemptCount,
                );

                self::closeAttempt($attemptId, ActivityAttemptStatus::Completed);
            } elseif (self::shouldRetry($lockedExecution, $throwable, $attemptCount, $maxAttempts)) {
                $exceptionPayload = self::failurePayload($throwable, $codec);
                $historyExceptionPayload = self::publicFailurePayload($throwable, $exceptionPayload);
                $retryAvailableAt = now()
                    ->addSeconds($backoffSeconds);

                self::closeAttempt($attemptId, ActivityAttemptStatus::Failed);

                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Pending,
                    'exception' => self::serializeWithCodec($exceptionPayload, null, $runCodec)['blob'],
                    'last_heartbeat_at' => null,
                ])->save();

                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                /** @var WorkflowTask $retryTask */
                $retryTask = WorkflowTask::query()->create([
                    'workflow_run_id' => $run->id,
                    'namespace' => $run->namespace,
                    'task_type' => TaskType::Activity->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => $retryAvailableAt,
                    'payload' => [
                        'activity_execution_id' => $lockedExecution->id,
                        'retry_of_task_id' => $task->id,
                        'retry_after_attempt_id' => $attemptId,
                        'retry_after_attempt' => $attemptCount,
                        'retry_backoff_seconds' => $backoffSeconds,
                        'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
                        'retry_policy' => $lockedExecution->retry_policy,
                    ],
                    'connection' => $lockedExecution->connection,
                    'queue' => $lockedExecution->queue,
                    'compatibility' => $run->compatibility,
                    'attempt_count' => $attemptCount,
                ]);

                WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_attempt_id' => $attemptId,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'retry_task_id' => $retryTask->id,
                    'retry_of_task_id' => $task->id,
                    'retry_available_at' => $retryAvailableAt->toJSON(),
                    'retry_backoff_seconds' => $backoffSeconds,
                    'retry_after_attempt_id' => $attemptId,
                    'retry_after_attempt' => $attemptCount,
                    'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
                    'retry_policy' => $lockedExecution->retry_policy,
                    'exception_type' => $exceptionPayload['type'] ?? null,
                    'exception_class' => $exceptionPayload['class'] ?? get_class($throwable),
                    'message' => $exceptionPayload['message'] ?? $throwable->getMessage(),
                    'code' => $throwable->getCode(),
                    'exception' => $historyExceptionPayload,
                    'activity' => self::publicActivitySnapshot(
                        $throwable,
                        $lockedExecution,
                        $historyExceptionPayload,
                    ),
                ], $parallelMetadata ?? []), $task);

                self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded($retryTask);
            } else {
                $exceptionPayload = self::failurePayload($throwable, $codec);
                $activityFailureCategory = $throwable instanceof StructuralLimitExceededException
                    ? FailureCategory::StructuralLimit
                    : FailureFactory::classify('activity', 'activity_execution', $throwable);
                $activityNonRetryable = ActivityRetryPolicy::isNonRetryableFailure($lockedExecution, $throwable);

                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()->create(array_merge(
                    FailureFactory::make($throwable),
                    [
                        'workflow_run_id' => $run->id,
                        'source_kind' => 'activity_execution',
                        'source_id' => $lockedExecution->id,
                        'propagation_kind' => 'activity',
                        'failure_category' => $activityFailureCategory->value,
                        'non_retryable' => $activityNonRetryable,
                        'handled' => false,
                    ],
                ));

                $lockedExecution->forceFill([
                    'status' => ActivityStatus::Failed,
                    'exception' => self::serializeWithCodec($exceptionPayload, null, $runCodec)['blob'],
                    'closed_at' => now(),
                ])->save();
                $historyExceptionPayload = self::publicFailurePayload($throwable, $exceptionPayload);
                $historyActivitySnapshot = self::publicActivitySnapshot(
                    $throwable,
                    $lockedExecution,
                    $historyExceptionPayload,
                );

                $resolutionEvent = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityFailed, array_merge([
                    'activity_execution_id' => $lockedExecution->id,
                    'activity_attempt_id' => $attemptId,
                    'activity_class' => $lockedExecution->activity_class,
                    'activity_type' => $lockedExecution->activity_type,
                    'sequence' => $lockedExecution->sequence,
                    'attempt_number' => $attemptCount,
                    'failure_id' => $failure->id,
                    'failure_category' => $activityFailureCategory->value,
                    'non_retryable' => $activityNonRetryable,
                    'exception_type' => $exceptionPayload['type'] ?? null,
                    'exception_class' => $failure->exception_class,
                    'message' => $failure->message,
                    'code' => $throwable->getCode(),
                    'exception' => $historyExceptionPayload,
                    'activity' => $historyActivitySnapshot,
                ], self::structuralLimitPayload($throwable), $parallelMetadata ?? []), $task);

                LifecycleEventDispatcher::activityFailed(
                    $run,
                    (string) $lockedExecution->id,
                    (string) ($lockedExecution->activity_type ?? $lockedExecution->activity_class),
                    (string) $lockedExecution->activity_class,
                    (int) $lockedExecution->sequence,
                    $attemptCount,
                    $failure->exception_class,
                    $failure->message,
                );
                LifecycleEventDispatcher::failureRecorded(
                    $run,
                    (string) $failure->id,
                    'activity_execution',
                    (string) $lockedExecution->id,
                    $failure->exception_class,
                    $failure->message,
                );

                self::closeAttempt($attemptId, ActivityAttemptStatus::Failed);
            }

            $task->forceFill([
                'status' => TaskStatus::Completed,
                'lease_expires_at' => null,
            ])->save();

            $closedStatus = $throwable === null
                ? ActivityStatus::Completed
                : ActivityStatus::Failed;

            if (
                $parallelMetadataPath !== []
                && ! ParallelChildGroup::shouldWakeParentOnActivityClosure(
                    $run,
                    $parallelMetadataPath,
                    $closedStatus,
                )
            ) {
                self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded(null);
            }

            // A standalone-activity host run has no workflow code to resume:
            // the activity execution IS the work, so close the host run with
            // the activity's outcome instead of scheduling a workflow-task
            // resume row.
            if (StandaloneActivityHostType::isHostRun($run)) {
                self::closeStandaloneHostRun($run, $lockedExecution, $throwable, $resolutionEvent);

                self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

                return self::recorded(null);
            }

            /** @var WorkflowTask $resumeTask */
            $resumeTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => $resolutionEvent instanceof WorkflowHistoryEvent
                    ? WorkflowTaskPayload::forActivityResolution($resolutionEvent)
                    : [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            self::projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));

            return self::recorded($resumeTask);
        });
    }

    /**
     * Close a standalone-activity host run with the activity's terminal
     * outcome. On success the run is marked Completed and the run output
     * is set to the activity's serialized result (so a "show" surface can
     * deliver the result without spelunking history). On failure the run
     * is marked Failed.
     *
     * Workflow-internal activity behaviour is unchanged: this path is only
     * reached when the host run is a standalone-activity host as identified
     * by {@see StandaloneActivityHostType::isHostRun()}.
     */
    private static function closeStandaloneHostRun(
        WorkflowRun $run,
        ActivityExecution $execution,
        ?Throwable $throwable,
        ?WorkflowHistoryEvent $resolutionEvent,
    ): void {
        $now = now();

        if ($throwable === null) {
            $outputCodec = is_string($execution->payload_codec) && $execution->payload_codec !== ''
                ? $execution->payload_codec
                : (is_string($run->payload_codec) && $run->payload_codec !== ''
                    ? $run->payload_codec
                    : CodecRegistry::defaultCodec());
            $run->forceFill([
                'status' => RunStatus::Completed,
                'closed_reason' => 'completed',
                'output' => $execution->result,
                'output_payload_codec' => $outputCodec,
                'closed_at' => $now,
                'last_progress_at' => $now,
            ])->save();

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowCompleted, [
                'output' => $execution->result,
                'payload_codec' => $outputCodec,
            ]);

            LifecycleEventDispatcher::workflowCompleted($run);

            return;
        }

        $run->forceFill([
            'status' => RunStatus::Failed,
            'closed_reason' => 'failed',
            'closed_at' => $now,
            'last_progress_at' => $now,
        ])->save();

        $exceptionClass = get_class($throwable);
        $message = $throwable->getMessage();
        $eventPayload = $resolutionEvent instanceof WorkflowHistoryEvent
            && is_array($resolutionEvent->payload)
                ? $resolutionEvent->payload
                : [];

        if (is_string($eventPayload['exception_class'] ?? null)) {
            $exceptionClass = (string) $eventPayload['exception_class'];
        }

        if (is_string($eventPayload['message'] ?? null)) {
            $message = (string) $eventPayload['message'];
        }

        $payload = array_filter([
            'failure_id' => $eventPayload['failure_id'] ?? null,
            'source_kind' => 'activity_execution',
            'source_id' => $execution->id,
            'failure_category' => $eventPayload['failure_category'] ?? null,
            'non_retryable' => $eventPayload['non_retryable'] ?? null,
            'exception_type' => $eventPayload['exception_type'] ?? null,
            'exception_class' => $exceptionClass,
            'message' => $message,
            'exception' => $eventPayload['exception'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, $payload);

        LifecycleEventDispatcher::workflowFailed($run, $exceptionClass, $message);
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    public static function recordForAttempt(
        string $attemptId,
        mixed $result,
        ?Throwable $throwable,
        ?string $codec = null
    ): array {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->with('execution')
            ->find($attemptId);

        if (! $attempt instanceof ActivityAttempt) {
            return self::ignored('attempt_not_found');
        }

        if (! is_string($attempt->workflow_task_id) || $attempt->workflow_task_id === '') {
            return self::ignored('task_not_found');
        }

        $execution = $attempt->execution;

        if (! $execution instanceof ActivityExecution) {
            return self::ignored('activity_execution_missing');
        }

        $attemptNumber = max(1, (int) $attempt->attempt_number);

        return self::record(
            $attempt->workflow_task_id,
            $attempt->id,
            $attemptNumber,
            $result,
            $throwable,
            ActivityRetryPolicy::maxAttemptsFromSnapshot($execution),
            ActivityRetryPolicy::backoffSecondsFromSnapshot($execution, $attemptNumber),
            $codec,
        );
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function recorded(?WorkflowTask $nextTask): array
    {
        return [
            'recorded' => true,
            'reason' => null,
            'next_task' => $nextTask,
        ];
    }

    /**
     * @return array{recorded: bool, reason: string|null, next_task: WorkflowTask|null}
     */
    private static function ignored(string $reason): array
    {
        return [
            'recorded' => false,
            'reason' => $reason,
            'next_task' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function structuralLimitPayload(Throwable $throwable): array
    {
        if (! $throwable instanceof StructuralLimitExceededException) {
            return [];
        }

        try {
            return [
                'structural_limit_kind' => $throwable->limitKind->value,
                'structural_limit_value' => $throwable->currentValue,
                'structural_limit_configured' => $throwable->configuredLimit,
            ];
        } catch (\Error) {
            return [];
        }
    }

    private static function closeAttempt(string $attemptId, ActivityAttemptStatus $status): void
    {
        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->lockForUpdate()
            ->find($attemptId);

        if (! $attempt instanceof ActivityAttempt || $attempt->status !== ActivityAttemptStatus::Running) {
            return;
        }

        $attempt->forceFill([
            'status' => $status,
            'lease_expires_at' => null,
            'closed_at' => $attempt->closed_at ?? now(),
        ])->save();
    }

    private static function closeAttemptIfStale(WorkflowRun $run, string $attemptId): void
    {
        $status = in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)
            ? ActivityAttemptStatus::Cancelled
            : ActivityAttemptStatus::Expired;

        self::closeAttempt($attemptId, $status);
    }

    private static function shouldRetry(
        ActivityExecution $execution,
        Throwable $throwable,
        int $attemptCount,
        int $maxAttempts
    ): bool {
        return ! ActivityRetryPolicy::isNonRetryableFailure($execution, $throwable)
            && $attemptCount < $maxAttempts;
    }

    /**
     * Serialize an activity payload, preferring the worker-supplied codec
     * (treats $value as already-serialized bytes), then the parent run's
     * codec (with a chooseCodecForData PHP-only fallback), then the package
     * default.
     *
     * @return array{blob: string, codec: string}
     */
    private static function serializeWithCodec(mixed $value, ?string $workerCodec, ?string $preferredCodec): array
    {
        if (is_string($workerCodec) && $workerCodec !== '' && is_string($value)) {
            return [
                'blob' => $value,
                'codec' => CodecRegistry::canonicalize($workerCodec),
            ];
        }

        if (is_string($preferredCodec) && $preferredCodec !== '') {
            $chosenCodec = Serializer::chooseCodecForData($preferredCodec, $value);

            return [
                'blob' => Serializer::serializeWithCodec($chosenCodec, $value),
                'codec' => $chosenCodec,
            ];
        }

        $chosenCodec = Serializer::chooseCodecForData(CodecRegistry::defaultCodec(), $value);

        return [
            'blob' => Serializer::serializeWithCodec($chosenCodec, $value),
            'codec' => $chosenCodec,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failurePayload(Throwable $throwable, ?string $workerCodec): array
    {
        $payload = FailureFactory::payload($throwable);

        if (
            is_string($workerCodec)
            && $workerCodec !== ''
            && array_key_exists('details', $payload)
            && ! is_string($payload['details_payload_codec'] ?? null)
        ) {
            $payload['details_payload_codec'] = $workerCodec;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function publicFailurePayload(Throwable $throwable, array $payload): array
    {
        if (! $throwable instanceof RestoredWorkflowException) {
            return $payload;
        }

        unset(
            $payload['class'],
            $payload['file'],
            $payload['line'],
            $payload['trace'],
            $payload['properties'],
        );

        return $payload;
    }

    /**
     * @param array<string, mixed> $exceptionPayload
     * @return array<string, mixed>
     */
    private static function publicActivitySnapshot(
        Throwable $throwable,
        ActivityExecution $execution,
        array $exceptionPayload,
    ): array {
        $snapshot = ActivitySnapshot::fromExecution($execution);

        if ($throwable instanceof RestoredWorkflowException) {
            $snapshot['exception'] = $exceptionPayload;
        }

        return $snapshot;
    }

    private static function preferredPayloadCodec(ActivityExecution $execution, ?string $runCodec): ?string
    {
        if (is_string($execution->payload_codec) && $execution->payload_codec !== '') {
            return $execution->payload_codec;
        }

        if (is_string($runCodec) && $runCodec !== '') {
            return $runCodec;
        }

        return CodecRegistry::defaultCodec();
    }

    private static function projectRun(WorkflowRun $run): void
    {
        self::historyProjectionRole()->projectRun($run);
    }

    private static function historyProjectionRole(): HistoryProjectionRole
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);

        return $role;
    }
}
