<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Testing\ActivityFakeContext;
use Workflow\V2\WorkflowStub;

final class LocalActivityExecutor
{
    /**
     * @return array{status: 'completed'|'failed'|'waiting', event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null}
     */
    public function execute(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        LocalActivityCall $activityCall,
    ): array {
        $started = $this->prepareAttempt($run, $task, $sequence, $activityCall);

        if ($started['event'] instanceof WorkflowHistoryEvent) {
            return [
                'status' => $started['event']->event_type === HistoryEventType::ActivityCompleted
                    ? 'completed'
                    : 'failed',
                'event' => $started['event'],
                'next_task' => null,
            ];
        }

        if ($started['next_task'] instanceof WorkflowTask) {
            return [
                'status' => 'waiting',
                'event' => null,
                'next_task' => $started['next_task'],
            ];
        }

        /** @var ActivityExecution $execution */
        $execution = $started['execution'];
        /** @var ActivityAttempt $attempt */
        $attempt = $started['attempt'];

        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $run, $task->id);
        $entryMethod = EntryMethod::forActivity($activity);
        $activityArguments = $execution->activityArguments();
        $arguments = $activity->resolveMethodDependencies($activityArguments, $entryMethod);

        $result = null;
        $throwable = null;

        if (WorkflowStub::faked()) {
            WorkflowStub::recordDispatched($execution->activity_class, $activityArguments);
        }

        if (WorkflowStub::hasMock($execution->activity_class)) {
            $mocked = WorkflowStub::mockedResult(
                $execution->activity_class,
                new ActivityFakeContext(
                    run: $run,
                    execution: $execution,
                    taskId: $task->id,
                    sequence: (int) $execution->sequence,
                    activity: $execution->activity_class,
                ),
                $activityArguments,
            );

            $result = $mocked['result'];
            $throwable = $mocked['throwable'];
        } else {
            try {
                $result = $activity->{$entryMethod->getName()}(...$arguments);
            } catch (Throwable $error) {
                $throwable = $error;
            }
        }

        return $this->recordAttemptOutcome($task, $execution, $attempt, $result, $throwable);
    }

    /**
     * @return array{execution: ActivityExecution|null, attempt: ActivityAttempt|null, event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null}
     */
    private function prepareAttempt(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        LocalActivityCall $activityCall,
    ): array {
        return DB::transaction(function () use ($run, $task, $sequence, $activityCall): array {
            /** @var WorkflowRun $lockedRun */
            $lockedRun = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            /** @var WorkflowTask $lockedTask */
            $lockedTask = WorkflowTask::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            /** @var ActivityExecution|null $execution */
            $execution = ActivityExecution::query()
                ->lockForUpdate()
                ->where('workflow_run_id', $lockedRun->id)
                ->where('sequence', $sequence)
                ->first();

            if (! $execution instanceof ActivityExecution) {
                $execution = $this->createExecution($lockedRun, $lockedTask, $sequence, $activityCall);
            }

            if (! LocalActivityRuntime::isExecution($execution)) {
                throw new RuntimeException('Workflow history at this sequence belongs to an ordinary queued activity.');
            }

            $terminalEvent = self::terminalEvent($lockedRun, $execution);

            if ($terminalEvent instanceof WorkflowHistoryEvent) {
                return [
                    'execution' => $execution,
                    'attempt' => null,
                    'event' => $terminalEvent,
                    'next_task' => null,
                ];
            }

            $timeoutKind = self::timeoutKind($execution);

            if ($timeoutKind !== null) {
                $outcome = $this->recordTimeoutOutcome($lockedRun, $lockedTask, $execution, $timeoutKind);

                return [
                    'execution' => $execution,
                    'attempt' => null,
                    'event' => $outcome['event'],
                    'next_task' => $outcome['next_task'],
                ];
            }

            if ($execution->status === ActivityStatus::Running) {
                $outcome = $this->recordInterruptedAttempt($lockedRun, $lockedTask, $execution);

                if ($outcome['event'] instanceof WorkflowHistoryEvent || $outcome['next_task'] instanceof WorkflowTask) {
                    return [
                        'execution' => $execution,
                        'attempt' => null,
                        'event' => $outcome['event'],
                        'next_task' => $outcome['next_task'],
                    ];
                }
            }

            $attempt = $this->startAttempt($lockedRun, $lockedTask, $execution);

            return [
                'execution' => $execution->fresh() ?? $execution,
                'attempt' => $attempt,
                'event' => null,
                'next_task' => null,
            ];
        });
    }

    private function createExecution(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        LocalActivityCall $activityCall,
    ): ActivityExecution {
        StructuralLimits::guardPendingActivities($run);

        EntryMethod::describeActivity($activityCall->activity);

        $options = $activityCall->options ?? new LocalActivityOptions();
        $activityOptions = $options->toActivityOptions();
        $scheduleToCloseDeadlineAt = $options->scheduleToCloseTimeout !== null
            ? now()
                ->addSeconds($options->scheduleToCloseTimeout)
            : null;
        $argumentsCodec = Serializer::chooseCodecForData($run->payload_codec, $activityCall->arguments);
        $serializedArguments = Serializer::serializeWithCodec($argumentsCodec, $activityCall->arguments);

        StructuralLimits::guardPayloadSize($serializedArguments);

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'activity_class' => $activityCall->activity,
            'activity_type' => TypeRegistry::for($activityCall->activity),
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'payload_codec' => $argumentsCodec,
            'arguments' => $serializedArguments,
            'connection' => $run->connection,
            'queue' => $run->queue,
            'activity_options' => $options->toSnapshot(),
            'schedule_to_close_deadline_at' => $scheduleToCloseDeadlineAt,
        ]);

        $activityClass = TypeRegistry::resolveActivityClass($execution->activity_class, $execution->activity_type);
        $activity = new $activityClass($execution, $run, $task->id);

        $execution->forceFill([
            'retry_policy' => ActivityRetryPolicy::snapshot($activity, $activityOptions),
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, LocalActivityRuntime::eventPayload([
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $sequence,
            'workflow_task_id' => $task->id,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]), $task);

        return $execution;
    }

    private function startAttempt(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
    ): ActivityAttempt {
        $now = now();
        $attemptId = (string) Str::ulid();
        $attemptNumber = ((int) $execution->attempt_count) + 1;
        $retryPolicy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
        $leaseExpiresAt = LocalActivityRuntime::renewWorkflowTask($task)
            ?? $task->lease_expires_at
            ?? LocalActivityRuntime::workflowTaskLeaseExpiresAt();
        $startToCloseTimeout = is_int($retryPolicy['start_to_close_timeout'] ?? null)
            ? $retryPolicy['start_to_close_timeout']
            : null;
        $heartbeatTimeout = is_int($retryPolicy['heartbeat_timeout'] ?? null)
            ? $retryPolicy['heartbeat_timeout']
            : null;

        $execution->forceFill([
            'status' => ActivityStatus::Running,
            'attempt_count' => $attemptNumber,
            'current_attempt_id' => $attemptId,
            'started_at' => $now,
            'last_heartbeat_at' => $now,
            'close_deadline_at' => $startToCloseTimeout === null ? null : $now->copy()->addSeconds($startToCloseTimeout),
            'heartbeat_deadline_at' => $heartbeatTimeout === null ? null : $now->copy()->addSeconds($heartbeatTimeout),
        ])->save();

        /** @var ActivityAttempt $attempt */
        $attempt = ActivityAttempt::query()->create([
            'id' => $attemptId,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => $attemptNumber,
            'status' => ActivityAttemptStatus::Running->value,
            'lease_owner' => $task->lease_owner,
            'started_at' => $now,
            'last_heartbeat_at' => $now,
            'lease_expires_at' => $leaseExpiresAt,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, LocalActivityRuntime::eventPayload([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt->attempt_number,
            'workflow_task_id' => $task->id,
            'lease_expires_at' => $leaseExpiresAt?->toJSON(),
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => self::attemptSnapshot($attempt),
        ]), $task);

        LifecycleEventDispatcher::activityStarted(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            (int) $attempt->attempt_number,
        );

        self::projectRun($run);

        return $attempt;
    }

    /**
     * @return array{status: 'completed'|'failed'|'waiting', event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null}
     */
    private function recordAttemptOutcome(
        WorkflowTask $task,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        mixed $result,
        ?Throwable $throwable,
    ): array {
        return DB::transaction(function () use ($task, $execution, $attempt, $result, $throwable): array {
            /** @var WorkflowTask $lockedTask */
            $lockedTask = WorkflowTask::query()
                ->lockForUpdate()
                ->findOrFail($task->id);

            /** @var ActivityExecution $lockedExecution */
            $lockedExecution = ActivityExecution::query()
                ->lockForUpdate()
                ->findOrFail($execution->id);

            /** @var ActivityAttempt $lockedAttempt */
            $lockedAttempt = ActivityAttempt::query()
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->findOrFail($lockedExecution->workflow_run_id);

            if (
                $lockedExecution->current_attempt_id !== $lockedAttempt->id
                || $lockedExecution->attempt_count !== $lockedAttempt->attempt_number
                || $lockedAttempt->status !== ActivityAttemptStatus::Running
            ) {
                return [
                    'status' => 'waiting',
                    'event' => null,
                    'next_task' => null,
                ];
            }

            $timeoutKind = self::timeoutKind($lockedExecution);

            if ($timeoutKind !== null && $throwable === null) {
                $outcome = $this->recordTimeoutOutcome($run, $lockedTask, $lockedExecution, $timeoutKind);

                return [
                    'status' => $outcome['event']?->event_type === HistoryEventType::ActivityTimedOut ? 'failed' : 'waiting',
                    'event' => $outcome['event'],
                    'next_task' => $outcome['next_task'],
                ];
            }

            if ($throwable === null) {
                return $this->recordSuccess($run, $lockedTask, $lockedExecution, $lockedAttempt, $result);
            }

            return $this->recordFailureOrRetry($run, $lockedTask, $lockedExecution, $lockedAttempt, $throwable);
        });
    }

    /**
     * @return array{status: 'completed', event: WorkflowHistoryEvent, next_task: null}
     */
    private function recordSuccess(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        mixed $result,
    ): array {
        $encoded = self::serializeWithCodec($result, self::preferredPayloadCodec($execution, $run));

        try {
            StructuralLimits::guardPayloadSize($encoded['blob']);
        } catch (StructuralLimitExceededException $limitExceeded) {
            /** @var array{status: 'failed'|'waiting', event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null} $failure */
            $failure = $this->recordFailureOrRetry($run, $task, $execution, $attempt, $limitExceeded);

            return $failure;
        }

        $execution->forceFill([
            'status' => ActivityStatus::Completed,
            'result' => $encoded['blob'],
            'payload_codec' => $encoded['codec'],
            'exception' => null,
            'closed_at' => now(),
            'close_deadline_at' => null,
            'heartbeat_deadline_at' => null,
        ])->save();

        self::closeAttempt($attempt, ActivityAttemptStatus::Completed);

        $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted, LocalActivityRuntime::eventPayload([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt->attempt_number,
            'result' => $execution->result,
            'payload_codec' => $encoded['codec'],
            'workflow_task_id' => $task->id,
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => self::attemptSnapshot($attempt->fresh() ?? $attempt),
        ]), $task);

        LifecycleEventDispatcher::activityCompleted(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            (int) $attempt->attempt_number,
        );

        self::projectRun($run);

        return [
            'status' => 'completed',
            'event' => $event,
            'next_task' => null,
        ];
    }

    /**
     * @return array{status: 'failed'|'waiting', event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null}
     */
    private function recordFailureOrRetry(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        ActivityAttempt $attempt,
        Throwable $throwable,
    ): array {
        $attemptNumber = max(1, (int) $attempt->attempt_number);
        $maxAttempts = ActivityRetryPolicy::maxAttemptsFromSnapshot($execution);

        if (! ActivityRetryPolicy::isNonRetryableFailure($execution, $throwable) && $attemptNumber < $maxAttempts) {
            $retry = $this->scheduleRetry(
                $run,
                $task,
                $execution,
                $attempt,
                $throwable,
                LocalActivityRuntime::RETRY_REASON_FAILURE,
            );

            return [
                'status' => 'waiting',
                'event' => null,
                'next_task' => $retry,
            ];
        }

        $event = $this->recordTerminalFailure($run, $task, $execution, $attempt, $throwable);

        return [
            'status' => 'failed',
            'event' => $event,
            'next_task' => null,
        ];
    }

    private function scheduleRetry(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        ?ActivityAttempt $attempt,
        Throwable $throwable,
        string $retryReason,
        ?string $timeoutKind = null,
    ): WorkflowTask {
        $attemptNumber = max(1, (int) ($attempt?->attempt_number ?? $execution->attempt_count));
        $maxAttempts = ActivityRetryPolicy::maxAttemptsFromSnapshot($execution);
        $backoffSeconds = ActivityRetryPolicy::backoffSecondsFromSnapshot($execution, $attemptNumber);
        $retryAvailableAt = now()
            ->addSeconds($backoffSeconds);
        $exceptionPayload = FailureFactory::payload($throwable);
        $runCodec = is_string($run->payload_codec) && $run->payload_codec !== '' ? $run->payload_codec : null;

        if ($attempt instanceof ActivityAttempt) {
            self::closeAttempt($attempt, ActivityAttemptStatus::Failed);
        }

        $execution->forceFill([
            'status' => ActivityStatus::Pending,
            'exception' => Serializer::serializeWithCodec($runCodec ?? CodecRegistry::defaultCodec(), $exceptionPayload),
            'last_heartbeat_at' => null,
            'close_deadline_at' => null,
            'heartbeat_deadline_at' => null,
        ])->save();

        /** @var WorkflowTask $retryTask */
        $retryTask = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'namespace' => $run->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => $retryAvailableAt,
            'payload' => LocalActivityRuntime::workflowTaskPayload($execution, [
                'retry_after_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
                'retry_after_attempt' => $attemptNumber,
                'retry_backoff_seconds' => $backoffSeconds,
                'retry_reason' => $retryReason,
                'timeout_kind' => $timeoutKind,
            ]),
            'connection' => $run->connection,
            'queue' => $run->queue,
            'compatibility' => $run->compatibility,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityRetryScheduled, LocalActivityRuntime::eventPayload([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'retry_task_id' => $retryTask->id,
            'retry_of_task_id' => $task->id,
            'retry_available_at' => $retryAvailableAt->toJSON(),
            'retry_backoff_seconds' => $backoffSeconds,
            'retry_after_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'retry_after_attempt' => $attemptNumber,
            'retry_reason' => $retryReason,
            'max_attempts' => $maxAttempts === PHP_INT_MAX ? null : $maxAttempts,
            'retry_policy' => $execution->retry_policy,
            'timeout_kind' => $timeoutKind,
            'exception_type' => $exceptionPayload['type'] ?? null,
            'exception_class' => $exceptionPayload['class'] ?? $throwable::class,
            'message' => $exceptionPayload['message'] ?? $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'exception' => $exceptionPayload,
            'workflow_task_id' => $task->id,
            'activity' => ActivitySnapshot::fromExecution($execution),
        ]), $task);

        self::projectRun($run);

        return $retryTask;
    }

    private function recordTerminalFailure(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        ?ActivityAttempt $attempt,
        Throwable $throwable,
    ): WorkflowHistoryEvent {
        $exceptionPayload = FailureFactory::payload($throwable);
        $failureCategory = $throwable instanceof StructuralLimitExceededException
            ? FailureCategory::StructuralLimit
            : FailureFactory::classify('activity', 'activity_execution', $throwable);
        $nonRetryable = ActivityRetryPolicy::isNonRetryableFailure($execution, $throwable);
        $runCodec = is_string($run->payload_codec) && $run->payload_codec !== '' ? $run->payload_codec : null;

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create(array_merge(
            FailureFactory::make($throwable),
            [
                'workflow_run_id' => $run->id,
                'source_kind' => 'activity_execution',
                'source_id' => $execution->id,
                'propagation_kind' => 'activity',
                'failure_category' => $failureCategory->value,
                'non_retryable' => $nonRetryable,
                'handled' => false,
            ],
        ));

        $execution->forceFill([
            'status' => ActivityStatus::Failed,
            'exception' => Serializer::serializeWithCodec($runCodec ?? CodecRegistry::defaultCodec(), $exceptionPayload),
            'closed_at' => now(),
            'close_deadline_at' => null,
            'heartbeat_deadline_at' => null,
        ])->save();

        if ($attempt instanceof ActivityAttempt) {
            self::closeAttempt($attempt, ActivityAttemptStatus::Failed);
        }

        $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityFailed, LocalActivityRuntime::eventPayload(array_merge([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attempt?->attempt_number ?? $execution->attempt_count,
            'failure_id' => $failure->id,
            'failure_category' => $failureCategory->value,
            'non_retryable' => $nonRetryable,
            'exception_type' => $exceptionPayload['type'] ?? null,
            'exception_class' => $failure->exception_class,
            'message' => $failure->message,
            'code' => $throwable->getCode(),
            'exception' => $exceptionPayload,
            'workflow_task_id' => $task->id,
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => $attempt instanceof ActivityAttempt
                ? self::attemptSnapshot($attempt->fresh() ?? $attempt)
                : null,
        ], self::structuralLimitPayload($throwable))), $task);

        LifecycleEventDispatcher::activityFailed(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            (int) ($attempt?->attempt_number ?? $execution->attempt_count),
            $failure->exception_class,
            $failure->message,
        );
        LifecycleEventDispatcher::failureRecorded(
            $run,
            (string) $failure->id,
            'activity_execution',
            (string) $execution->id,
            $failure->exception_class,
            $failure->message,
        );

        self::projectRun($run);

        return $event;
    }

    /**
     * @return array{event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null}
     */
    private function recordTimeoutOutcome(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
        string $timeoutKind,
    ): array {
        $attempt = self::currentAttempt($execution);
        $attemptNumber = max(1, (int) ($attempt?->attempt_number ?? $execution->attempt_count));
        $maxAttempts = ActivityRetryPolicy::maxAttemptsFromSnapshot($execution);
        $canRetry = $attemptNumber < $maxAttempts && $timeoutKind !== 'schedule_to_close';
        $throwable = new RuntimeException(self::timeoutMessage($execution, $timeoutKind));

        if ($canRetry) {
            return [
                'event' => null,
                'next_task' => $this->scheduleRetry(
                    $run,
                    $task,
                    $execution,
                    $attempt,
                    $throwable,
                    LocalActivityRuntime::RETRY_REASON_TIMEOUT,
                    $timeoutKind,
                ),
            ];
        }

        if ($attempt instanceof ActivityAttempt) {
            self::closeAttempt($attempt, ActivityAttemptStatus::Failed);
        }

        $now = now();
        $failureCategory = FailureCategory::Timeout;
        $exceptionClass = 'Workflow\\V2\\Exceptions\\ActivityTimeoutException';

        $execution->forceFill([
            'status' => ActivityStatus::Failed,
            'closed_at' => $now,
            'close_deadline_at' => null,
            'heartbeat_deadline_at' => null,
        ])->save();

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->create([
            'workflow_run_id' => $run->id,
            'source_kind' => 'activity_execution',
            'source_id' => $execution->id,
            'propagation_kind' => 'timeout',
            'failure_category' => $failureCategory->value,
            'handled' => false,
            'exception_class' => $exceptionClass,
            'message' => $throwable->getMessage(),
            'file' => '',
            'line' => 0,
            'trace_preview' => '',
        ]);

        $event = WorkflowHistoryEvent::record($run, HistoryEventType::ActivityTimedOut, LocalActivityRuntime::eventPayload([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id ?? $execution->current_attempt_id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => $execution->sequence,
            'attempt_number' => $attemptNumber,
            'failure_id' => $failure->id,
            'failure_category' => $failureCategory->value,
            'timeout_kind' => $timeoutKind,
            'message' => $throwable->getMessage(),
            'exception_class' => $exceptionClass,
            'schedule_deadline_at' => $execution->schedule_deadline_at?->toIso8601String(),
            'close_deadline_at' => $execution->close_deadline_at?->toIso8601String(),
            'schedule_to_close_deadline_at' => $execution->schedule_to_close_deadline_at?->toIso8601String(),
            'heartbeat_deadline_at' => $execution->heartbeat_deadline_at?->toIso8601String(),
            'workflow_task_id' => $task->id,
            'activity' => ActivitySnapshot::fromExecution($execution),
            'activity_attempt' => $attempt instanceof ActivityAttempt
                ? self::attemptSnapshot($attempt->fresh() ?? $attempt)
                : null,
        ]), $task);

        LifecycleEventDispatcher::activityFailed(
            $run,
            (string) $execution->id,
            (string) ($execution->activity_type ?? $execution->activity_class),
            (string) $execution->activity_class,
            (int) $execution->sequence,
            $attemptNumber,
            $exceptionClass,
            $throwable->getMessage(),
        );
        LifecycleEventDispatcher::failureRecorded(
            $run,
            (string) $failure->id,
            'activity_execution',
            (string) $execution->id,
            $exceptionClass,
            $throwable->getMessage(),
        );

        self::projectRun($run);

        return [
            'event' => $event,
            'next_task' => null,
        ];
    }

    /**
     * @return array{event: WorkflowHistoryEvent|null, next_task: WorkflowTask|null}
     */
    private function recordInterruptedAttempt(
        WorkflowRun $run,
        WorkflowTask $task,
        ActivityExecution $execution,
    ): array {
        $attempt = self::currentAttempt($execution);
        $attemptNumber = max(1, (int) ($attempt?->attempt_number ?? $execution->attempt_count));

        if ($attempt instanceof ActivityAttempt && $attempt->status === ActivityAttemptStatus::Running) {
            self::closeAttempt($attempt, ActivityAttemptStatus::Expired);
        }

        $maxAttempts = ActivityRetryPolicy::maxAttemptsFromSnapshot($execution);
        $throwable = new RuntimeException(
            'Local activity attempt was interrupted before a terminal event and will be replayed from durable history.'
        );

        if ($attemptNumber >= $maxAttempts) {
            return [
                'event' => $this->recordTerminalFailure($run, $task, $execution, $attempt, $throwable),
                'next_task' => null,
            ];
        }

        return [
            'event' => null,
            'next_task' => $this->scheduleRetry(
                $run,
                $task,
                $execution,
                $attempt,
                $throwable,
                LocalActivityRuntime::RETRY_REASON_COLD_REPLAY,
            ),
        ];
    }

    private static function terminalEvent(WorkflowRun $run, ActivityExecution $execution): ?WorkflowHistoryEvent
    {
        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::ActivityCompleted->value,
                HistoryEventType::ActivityFailed->value,
                HistoryEventType::ActivityCancelled->value,
                HistoryEventType::ActivityTimedOut->value,
            ])
            ->get()
            ->first(static fn (WorkflowHistoryEvent $event): bool => ($event->payload['activity_execution_id'] ?? null) === $execution->id);

        return $event;
    }

    private static function currentAttempt(ActivityExecution $execution): ?ActivityAttempt
    {
        $attemptId = $execution->current_attempt_id;

        if (! is_string($attemptId) || $attemptId === '') {
            return null;
        }

        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->where('workflow_run_id', $execution->workflow_run_id)
            ->where('activity_execution_id', $execution->id)
            ->whereKey($attemptId)
            ->first();

        return $attempt;
    }

    private static function closeAttempt(ActivityAttempt $attempt, ActivityAttemptStatus $status): void
    {
        $attempt->forceFill([
            'status' => $status,
            'lease_expires_at' => null,
            'closed_at' => $attempt->closed_at ?? now(),
        ])->save();
    }

    private static function timeoutKind(ActivityExecution $execution): ?string
    {
        $now = now();

        if ($execution->schedule_to_close_deadline_at !== null && $now->gte($execution->schedule_to_close_deadline_at)) {
            return 'schedule_to_close';
        }

        if ($execution->heartbeat_deadline_at !== null && $now->gte($execution->heartbeat_deadline_at)) {
            return 'heartbeat';
        }

        if ($execution->close_deadline_at !== null && $now->gte($execution->close_deadline_at)) {
            return 'start_to_close';
        }

        return null;
    }

    private static function timeoutMessage(ActivityExecution $execution, string $timeoutKind): string
    {
        $label = $execution->activity_type ?? $execution->activity_class;

        return match ($timeoutKind) {
            'schedule_to_close' => sprintf(
                'Local activity %s schedule-to-close deadline expired at %s.',
                $label,
                $execution->schedule_to_close_deadline_at?->toIso8601String() ?? 'unknown'
            ),
            'heartbeat' => sprintf(
                'Local activity %s heartbeat deadline expired at %s (last heartbeat: %s).',
                $label,
                $execution->heartbeat_deadline_at?->toIso8601String() ?? 'unknown',
                $execution->last_heartbeat_at?->toIso8601String() ?? 'never'
            ),
            default => sprintf(
                'Local activity %s start-to-close deadline expired at %s.',
                $label,
                $execution->close_deadline_at?->toIso8601String() ?? 'unknown'
            ),
        };
    }

    /**
     * @return array{blob: string, codec: string}
     */
    private static function serializeWithCodec(mixed $value, ?string $preferredCodec): array
    {
        $codec = is_string($preferredCodec) && $preferredCodec !== ''
            ? Serializer::chooseCodecForData($preferredCodec, $value)
            : Serializer::chooseCodecForData(CodecRegistry::defaultCodec(), $value);

        return [
            'blob' => Serializer::serializeWithCodec($codec, $value),
            'codec' => $codec,
        ];
    }

    private static function preferredPayloadCodec(ActivityExecution $execution, WorkflowRun $run): ?string
    {
        if (is_string($execution->payload_codec) && $execution->payload_codec !== '') {
            return $execution->payload_codec;
        }

        if (is_string($run->payload_codec) && $run->payload_codec !== '') {
            return $run->payload_codec;
        }

        return CodecRegistry::defaultCodec();
    }

    /**
     * @return array<string, mixed>
     */
    private static function structuralLimitPayload(Throwable $throwable): array
    {
        if (! $throwable instanceof StructuralLimitExceededException) {
            return [];
        }

        return [
            'structural_limit_kind' => $throwable->limitKind->value,
            'structural_limit_value' => $throwable->currentValue,
            'structural_limit_configured' => $throwable->configuredLimit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function attemptSnapshot(ActivityAttempt $attempt): array
    {
        return array_filter([
            'id' => $attempt->id,
            'activity_execution_id' => $attempt->activity_execution_id,
            'task_id' => $attempt->workflow_task_id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status?->value,
            'lease_owner' => $attempt->lease_owner,
            'started_at' => $attempt->started_at?->toJSON(),
            'last_heartbeat_at' => $attempt->last_heartbeat_at?->toJSON(),
            'lease_expires_at' => $attempt->lease_expires_at?->toJSON(),
            'closed_at' => $attempt->closed_at?->toJSON(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function projectRun(WorkflowRun $run): void
    {
        /** @var \Workflow\V2\Contracts\HistoryProjectionRole $role */
        $role = app(\Workflow\V2\Contracts\HistoryProjectionRole::class);
        $role->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures', 'historyEvents']) ?? $run);
    }
}
