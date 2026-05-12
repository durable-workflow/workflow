<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;

/**
 * Atomically writes the host run + activity execution + ready activity
 * task that back a standalone-activity start request.
 *
 * The host run is anchored by {@see StandaloneActivityHostType::WORKFLOW_TYPE}
 * so the same activity dispatch path that workflows use to schedule
 * activities (poll → claim → complete/fail/heartbeat) carries the
 * standalone activity through retry, timeout enforcement, cancellation,
 * and history projection without a parallel "job system" runtime.
 *
 * The host run starts in {@see RunStatus::Running} because there is no
 * workflow task to claim — the activity execution is the work, and
 * {@see \Workflow\V2\Support\ActivityOutcomeRecorder::closeStandaloneHostRun()}
 * closes the run on the activity's terminal outcome.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public start() signature is covered by the workflow package's
 *      semver guarantee. See docs/api-stability.md.
 */
final class StandaloneActivityStartService
{
    /**
     * @param array{
     *     namespace?: string|null,
     *     activity_id?: string|null,
     *     activity_type: string,
     *     activity_class?: string|null,
     *     task_queue: string,
     *     arguments?: string|null,
     *     payload_codec?: string|null,
     *     business_key?: string|null,
     *     retry_policy?: array<string, mixed>|null,
     *     start_to_close_timeout_seconds?: int|null,
     *     schedule_to_start_timeout_seconds?: int|null,
     *     schedule_to_close_timeout_seconds?: int|null,
     *     heartbeat_timeout_seconds?: int|null,
     *     command_context?: CommandContext|null,
     * } $options
     *
     * @return array{
     *     started: bool,
     *     activity_id: string,
     *     activity_execution_id: string,
     *     workflow_run_id: string,
     *     workflow_type: string,
     *     activity_type: string,
     *     activity_class: string,
     *     task_queue: string,
     *     status: string,
     *     payload_codec: string,
     *     started_at: string|null,
     *     schedule_to_start_deadline_at: string|null,
     *     schedule_to_close_deadline_at: string|null,
     * }
     */
    public function start(array $options): array
    {
        return DB::transaction(function () use ($options): array {
            $namespace = $options['namespace'] ?? null;
            $providedActivityId = $options['activity_id'] ?? null;
            $activityType = $options['activity_type'];
            $activityClass = $options['activity_class'] ?? $activityType;
            $taskQueue = $options['task_queue'];
            $arguments = $options['arguments'] ?? null;
            $payloadCodec = is_string($options['payload_codec'] ?? null) && $options['payload_codec'] !== ''
                ? CodecRegistry::canonicalize($options['payload_codec'])
                : CodecRegistry::defaultCodec();
            $businessKey = $options['business_key'] ?? null;
            $commandContext = self::commandContext($options);

            $activityOptions = new ActivityOptions(
                queue: $taskQueue,
                maxAttempts: self::intOrNull($options['retry_policy']['max_attempts'] ?? null),
                backoff: self::backoffOrNull($options['retry_policy']['backoff_seconds'] ?? null),
                startToCloseTimeout: self::intOrNull($options['start_to_close_timeout_seconds'] ?? null),
                scheduleToStartTimeout: self::intOrNull($options['schedule_to_start_timeout_seconds'] ?? null),
                scheduleToCloseTimeout: self::intOrNull($options['schedule_to_close_timeout_seconds'] ?? null),
                heartbeatTimeout: self::intOrNull($options['heartbeat_timeout_seconds'] ?? null),
                nonRetryableErrorTypes: self::stringList(
                    $options['retry_policy']['non_retryable_error_types'] ?? [],
                ),
            );

            $retryPolicySnapshot = ActivityRetryPolicy::snapshotExternal(
                is_array($options['retry_policy'] ?? null) ? $options['retry_policy'] : null,
                $activityOptions,
            );

            $now = now();
            $instance = $this->resolveOrCreateInstance($providedActivityId, $namespace, $now);
            $nextRunNumber = self::nextRunNumber($instance);

            $scheduleToStartDeadlineAt = $activityOptions->scheduleToStartTimeout !== null
                ? $now->copy()->addSeconds($activityOptions->scheduleToStartTimeout)
                : null;
            $scheduleToCloseDeadlineAt = $activityOptions->scheduleToCloseTimeout !== null
                ? $now->copy()->addSeconds($activityOptions->scheduleToCloseTimeout)
                : null;

            /** @var WorkflowRun $run */
            $run = WorkflowRun::query()->create([
                'workflow_instance_id' => $instance->id,
                'run_number' => $nextRunNumber,
                'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'namespace' => $namespace,
                'business_key' => $businessKey,
                'status' => RunStatus::Running->value,
                'compatibility' => WorkerCompatibility::current(),
                'payload_codec' => $payloadCodec,
                'arguments' => $arguments,
                'connection' => null,
                'queue' => $taskQueue,
                'started_at' => $now,
                'last_progress_at' => $now,
                'last_history_sequence' => 0,
            ]);

            $command = WorkflowCommand::record($instance, $run, [
                ...$commandContext->attributes(),
                'command_type' => CommandType::Start->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::StartedNew->value,
                'payload_codec' => $payloadCodec,
                'payload' => $arguments,
                'accepted_at' => $now,
                'applied_at' => $now,
            ]);

            $instance->forceFill([
                'current_run_id' => $run->id,
                'run_count' => $run->run_number,
                'started_at' => $instance->started_at ?? $now,
            ])->save();

            WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'business_key' => $businessKey,
                'visibility_labels' => null,
                'memo' => null,
                'search_attributes' => null,
                'outcome' => $command->outcome?->value,
            ], null, $command);

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
                'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_command_id' => $command->id,
                'business_key' => $businessKey,
            ], null, $command);

            /** @var ActivityExecution $execution */
            $execution = ActivityExecution::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => 1,
                'activity_class' => $activityClass,
                'activity_type' => $activityType,
                'status' => ActivityStatus::Pending->value,
                'attempt_count' => 0,
                'arguments' => $arguments,
                'payload_codec' => $payloadCodec,
                'connection' => null,
                'queue' => $taskQueue,
                'retry_policy' => $retryPolicySnapshot,
                'activity_options' => $activityOptions->toSnapshot(),
                'schedule_deadline_at' => $scheduleToStartDeadlineAt,
                'schedule_to_close_deadline_at' => $scheduleToCloseDeadlineAt,
            ]);

            WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
                'activity_execution_id' => $execution->id,
                'activity_class' => $execution->activity_class,
                'activity_type' => $execution->activity_type,
                'sequence' => 1,
                'activity' => ActivitySnapshot::fromExecution($execution),
            ]);

            /** @var WorkflowTask $activityTask */
            $activityTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $namespace,
                'task_type' => TaskType::Activity->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => $now,
                'payload' => [
                    'activity_execution_id' => $execution->id,
                ],
                'connection' => null,
                'queue' => $taskQueue,
                'compatibility' => $run->compatibility,
                ...TaskSchedulingFields::forActivity($run, $execution),
            ]);

            $this->projectRun($run);
            TaskDispatcher::dispatch($activityTask);

            return [
                'started' => true,
                'activity_id' => $instance->id,
                'activity_execution_id' => $execution->id,
                'workflow_run_id' => $run->id,
                'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'activity_type' => $activityType,
                'activity_class' => $execution->activity_class,
                'task_queue' => $taskQueue,
                'status' => RunStatus::Running->value,
                'payload_codec' => $payloadCodec,
                'started_at' => $run->started_at?->toJSON(),
                'schedule_to_start_deadline_at' => $scheduleToStartDeadlineAt?->toJSON(),
                'schedule_to_close_deadline_at' => $scheduleToCloseDeadlineAt?->toJSON(),
            ];
        });
    }

    private function resolveOrCreateInstance(
        ?string $providedActivityId,
        ?string $namespace,
        Carbon $now,
    ): WorkflowInstance {
        if ($providedActivityId === null) {
            /** @var WorkflowInstance $instance */
            $instance = WorkflowInstance::query()->create([
                'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
                'namespace' => $namespace,
                'reserved_at' => $now,
                'started_at' => $now,
                'run_count' => 0,
            ]);

            return $instance->fresh();
        }

        WorkflowInstanceId::assertValid($providedActivityId);

        WorkflowInstance::query()->insertOrIgnore([
            'id' => $providedActivityId,
            'workflow_class' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'workflow_type' => StandaloneActivityHostType::WORKFLOW_TYPE,
            'namespace' => $namespace,
            'reserved_at' => $now,
            'started_at' => $now,
            'run_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()
            ->lockForUpdate()
            ->findOrFail($providedActivityId);

        if ($instance->workflow_type !== StandaloneActivityHostType::WORKFLOW_TYPE) {
            throw new InvalidArgumentException(sprintf(
                'Identifier [%s] is already reserved for workflow type [%s] and cannot be reused for a standalone activity.',
                $providedActivityId,
                $instance->workflow_type,
            ));
        }

        $currentRun = CurrentRunResolver::forInstance($instance, lockForUpdate: true);
        CurrentRunResolver::syncPointer($instance, $currentRun);

        if ($currentRun instanceof WorkflowRun && ! $currentRun->status->isTerminal()) {
            throw new InvalidArgumentException(sprintf(
                'Standalone activity [%s] is already running.',
                $providedActivityId,
            ));
        }

        return $instance;
    }

    private static function nextRunNumber(WorkflowInstance $instance): int
    {
        $resolvedRun = $instance->relationLoaded('currentRun')
            ? $instance->getRelation('currentRun')
            : null;

        return max(
            0,
            (int) ($instance->run_count ?? 0),
            $resolvedRun instanceof WorkflowRun ? (int) $resolvedRun->run_number : 0,
        ) + 1;
    }

    private function projectRun(WorkflowRun $run): void
    {
        /** @var HistoryProjectionRole $role */
        $role = app(HistoryProjectionRole::class);
        $role->projectRun($run->fresh(['instance', 'tasks', 'activityExecutions', 'failures']));
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function commandContext(array $options): CommandContext
    {
        $commandContext = $options['command_context'] ?? null;

        return $commandContext instanceof CommandContext
            ? $commandContext
            : CommandContext::controlPlane();
    }

    /**
     * @return list<int>|int|null
     */
    private static function backoffOrNull(mixed $value): array|int|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (is_int($entry)) {
                $normalized[] = $entry;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }
}
