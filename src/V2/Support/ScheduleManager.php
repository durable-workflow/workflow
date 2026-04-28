<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\WorkflowStub;

/**
 * Single source of truth for workflow schedule lifecycle.
 *
 * Schedules store a rich Temporal-style descriptor:
 *   - `spec`   → { cron_expressions: [], intervals: [{every, offset}], timezone }
 *   - `action` → { workflow_type, workflow_class, task_queue, input, timeouts }
 *
 * `ScheduleManager` provides two create paths:
 *   - {@see create()}           — single-cron convenience (by workflowClass)
 *   - {@see createFromSpec()}   — rich spec/action form (used by the HTTP layer)
 *
 * All overlap and buffer semantics live in this class — no external enforcer.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static and instance method signatures on this class are
 *      covered by the workflow package's semver guarantee. See
 *      docs/api-stability.md.
 */
final class ScheduleManager
{
    /**
     * @param  class-string         $workflowClass
     * @param  array<int, mixed>    $arguments
     * @param  array<string, string> $labels
     * @param  array<string, mixed> $memo
     * @param  array<string, scalar|null> $searchAttributes
     */
    public static function create(
        string $scheduleId,
        string $workflowClass,
        string $cronExpression,
        array $arguments = [],
        string $timezone = 'UTC',
        ScheduleOverlapPolicy $overlapPolicy = ScheduleOverlapPolicy::Skip,
        array $labels = [],
        array $memo = [],
        array $searchAttributes = [],
        int $jitterSeconds = 0,
        ?int $maxRuns = null,
        ?string $connection = null,
        ?string $queue = null,
        ?string $notes = null,
        ?string $namespace = null,
    ): WorkflowSchedule {
        self::assertValidCron($cronExpression);

        $workflowType = TypeRegistry::for($workflowClass);

        return self::createFromSpec(
            scheduleId: $scheduleId,
            spec: [
                'cron_expressions' => [$cronExpression],
                'timezone' => $timezone,
            ],
            action: [
                'workflow_type' => $workflowType,
                'workflow_class' => $workflowClass,
                'input' => $arguments,
            ],
            overlapPolicy: $overlapPolicy,
            labels: $labels,
            memo: $memo,
            searchAttributes: $searchAttributes,
            jitterSeconds: $jitterSeconds,
            maxRuns: $maxRuns,
            connection: $connection,
            queue: $queue,
            note: $notes,
            namespace: $namespace,
        );
    }

    /**
     * @param  array<string, mixed> $spec
     * @param  array<string, mixed> $action
     * @param  array<string, string> $labels
     * @param  array<string, mixed> $memo
     * @param  array<string, scalar|null> $searchAttributes
     */
    public static function createFromSpec(
        string $scheduleId,
        array $spec,
        array $action,
        ScheduleOverlapPolicy $overlapPolicy = ScheduleOverlapPolicy::Skip,
        array $labels = [],
        array $memo = [],
        array $searchAttributes = [],
        int $jitterSeconds = 0,
        ?int $maxRuns = null,
        ?string $connection = null,
        ?string $queue = null,
        ?string $note = null,
        ?string $namespace = null,
        ?CommandContext $context = null,
    ): WorkflowSchedule {
        $namespace ??= config('workflows.v2.namespace') ?? 'default';
        $action = WorkflowSchedule::normalizeActionTimeouts($action);

        self::assertValidSpec($spec);

        /** @var WorkflowSchedule $schedule */
        $schedule = WorkflowSchedule::query()->create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => $spec,
            'action' => $action,
            'status' => ScheduleStatus::Active->value,
            'overlap_policy' => $overlapPolicy->value,
            'memo' => $memo !== [] ? $memo : null,
            'search_attributes' => $searchAttributes !== [] ? $searchAttributes : null,
            'visibility_labels' => $labels !== [] ? $labels : null,
            'jitter_seconds' => $jitterSeconds,
            'max_runs' => $maxRuns,
            'remaining_actions' => $maxRuns,
            'fires_count' => 0,
            'failures_count' => 0,
            'connection' => $connection,
            'queue' => $queue,
            'note' => $note,
        ]);

        $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
        $schedule->save();

        self::recordScheduleEvent($schedule, HistoryEventType::ScheduleCreated, [
            'spec' => is_array($schedule->spec) ? $schedule->spec : [],
            'action' => is_array($schedule->action) ? $schedule->action : [],
            'overlap_policy' => $schedule->overlap_policy,
            'next_fire_at' => $schedule->next_fire_at?->toIso8601String(),
        ], $context);

        return $schedule;
    }

    public static function pause(
        WorkflowSchedule $schedule,
        ?string $reason = null,
        ?CommandContext $context = null,
    ): WorkflowSchedule {
        if ($schedule->status === ScheduleStatus::Deleted) {
            throw new LogicException(sprintf('Cannot pause deleted schedule [%s].', $schedule->schedule_id));
        }

        $updates = [
            'status' => ScheduleStatus::Paused->value,
            'paused_at' => now(),
        ];

        if ($reason !== null) {
            $updates['note'] = $reason;
        }

        $schedule->forceFill($updates)
            ->save();

        self::recordScheduleEvent($schedule, HistoryEventType::SchedulePaused, array_filter([
            'reason' => $reason,
            'paused_at' => $schedule->paused_at?->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null), $context);

        return $schedule;
    }

    public static function resume(WorkflowSchedule $schedule, ?CommandContext $context = null): WorkflowSchedule
    {
        if ($schedule->status !== ScheduleStatus::Paused) {
            throw new LogicException(sprintf('Schedule [%s] is not paused.', $schedule->schedule_id));
        }

        $schedule->forceFill([
            'status' => ScheduleStatus::Active->value,
            'paused_at' => null,
        ])->save();

        $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
        $schedule->save();

        self::recordScheduleEvent($schedule, HistoryEventType::ScheduleResumed, [
            'next_fire_at' => $schedule->next_fire_at?->toIso8601String(),
        ], $context);

        return $schedule;
    }

    /**
     * @param  array<string, mixed>|null $spec
     * @param  array<string, mixed>|null $action
     */
    public static function update(
        WorkflowSchedule $schedule,
        ?string $cronExpression = null,
        ?string $timezone = null,
        ?ScheduleOverlapPolicy $overlapPolicy = null,
        ?int $jitterSeconds = null,
        ?string $notes = null,
        ?array $spec = null,
        ?array $action = null,
        ?array $memo = null,
        ?array $searchAttributes = null,
        ?int $maxRuns = null,
        ?CommandContext $context = null,
    ): WorkflowSchedule {
        if ($schedule->status === ScheduleStatus::Deleted) {
            throw new LogicException(sprintf('Cannot update deleted schedule [%s].', $schedule->schedule_id));
        }

        $currentSpec = is_array($schedule->spec) ? $schedule->spec : [];
        $currentAction = is_array($schedule->action) ? $schedule->action : [];

        if ($cronExpression !== null) {
            self::assertValidCron($cronExpression);
            $currentSpec['cron_expressions'] = [$cronExpression];
        }

        if ($timezone !== null) {
            $currentSpec['timezone'] = $timezone;
        }

        if ($spec !== null) {
            self::assertValidSpec($spec);
            $currentSpec = $spec;
        }

        if ($action !== null) {
            $currentAction = WorkflowSchedule::normalizeActionTimeouts($action);
        }

        $updates = [
            'spec' => $currentSpec,
            'action' => $currentAction,
        ];

        if ($overlapPolicy !== null) {
            $updates['overlap_policy'] = $overlapPolicy->value;
        }

        if ($jitterSeconds !== null) {
            $updates['jitter_seconds'] = $jitterSeconds;
        }

        if ($notes !== null) {
            $updates['note'] = $notes;
        }

        if ($memo !== null) {
            $updates['memo'] = $memo !== [] ? $memo : null;
        }

        if ($searchAttributes !== null) {
            $updates['search_attributes'] = $searchAttributes !== [] ? $searchAttributes : null;
        }

        if ($maxRuns !== null) {
            $updates['max_runs'] = $maxRuns;
            $updates['remaining_actions'] = ($schedule->remaining_actions === null || $maxRuns > (int) $schedule->fires_count)
                ? $maxRuns - (int) $schedule->fires_count
                : (int) $schedule->remaining_actions;
        }

        $changedFields = array_values(array_keys($updates));

        $schedule->forceFill($updates)
            ->save();

        $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
        $schedule->save();

        self::recordScheduleEvent($schedule, HistoryEventType::ScheduleUpdated, [
            'changed_fields' => $changedFields,
            'spec' => is_array($schedule->spec) ? $schedule->spec : [],
            'action' => is_array($schedule->action) ? $schedule->action : [],
            'overlap_policy' => $schedule->overlap_policy,
            'next_fire_at' => $schedule->next_fire_at?->toIso8601String(),
        ], $context);

        return $schedule;
    }

    public static function delete(WorkflowSchedule $schedule, ?CommandContext $context = null): WorkflowSchedule
    {
        if ($schedule->status === ScheduleStatus::Deleted) {
            return $schedule;
        }

        $schedule->forceFill([
            'status' => ScheduleStatus::Deleted->value,
            'deleted_at' => now(),
            'next_fire_at' => null,
        ])->save();

        self::recordScheduleEvent($schedule, HistoryEventType::ScheduleDeleted, [
            'reason' => 'deleted',
            'deleted_at' => $schedule->deleted_at?->toIso8601String(),
        ], $context);

        return $schedule;
    }

    /**
     * Trigger a schedule once. Returns the started workflow instance id, or null
     * if the trigger was skipped (exhausted, overlap policy blocked it, etc.).
     */
    public static function trigger(
        WorkflowSchedule $schedule,
        ?ScheduleOverlapPolicy $overlapPolicyOverride = null,
        ?CommandContext $context = null,
    ): ?string {
        return self::triggerDetailed($schedule, $overlapPolicyOverride, $context)->instanceId;
    }

    public static function triggerDetailed(
        WorkflowSchedule $schedule,
        ?ScheduleOverlapPolicy $overlapPolicyOverride = null,
        ?CommandContext $context = null,
    ): ScheduleTriggerResult {
        return DB::transaction(static function () use (
            $schedule,
            $overlapPolicyOverride,
            $context
        ): ScheduleTriggerResult {
            /** @var WorkflowSchedule $schedule */
            $schedule = WorkflowSchedule::query()
                ->lockForUpdate()
                ->findOrFail($schedule->id);

            if (! $schedule->status->allowsTrigger()) {
                self::recordSkip($schedule, 'status_not_triggerable', $context);

                return new ScheduleTriggerResult('skipped', null, null, 'status_not_triggerable');
            }

            if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
                self::recordSkip($schedule, 'remaining_actions_exhausted', $context);

                return new ScheduleTriggerResult('skipped', null, null, 'remaining_actions_exhausted');
            }

            $overlapPolicy = $overlapPolicyOverride
                ?? ScheduleOverlapPolicy::tryFrom($schedule->overlap_policy ?? '')
                ?? ScheduleOverlapPolicy::Skip;

            if ($overlapPolicy->isBuffer() && self::runningRunExists($schedule)) {
                if ($schedule->isAtBufferCapacity($overlapPolicy->value)) {
                    self::recordSkip($schedule, 'buffer_full', $context);
                    $schedule->forceFill([
                        'next_fire_at' => $schedule->computeNextFireAtWithJitter(),
                    ])->save();

                    return new ScheduleTriggerResult('buffer_full', null, null, 'buffer_full');
                }

                $schedule->bufferAction();
                $schedule->next_fire_at = $schedule->computeNextFireAtWithJitter();
                $schedule->save();

                return new ScheduleTriggerResult('buffered', null, null, null);
            }

            if (! self::overlapAllowed($schedule, $overlapPolicy)) {
                $reason = 'overlap_policy_' . $overlapPolicy->value;
                self::recordSkip($schedule, $reason, $context);
                $schedule->forceFill([
                    'next_fire_at' => $schedule->computeNextFireAtWithJitter(),
                ])->save();

                return new ScheduleTriggerResult('skipped', null, null, $reason);
            }

            if ($overlapPolicy === ScheduleOverlapPolicy::CancelOther || $overlapPolicy === ScheduleOverlapPolicy::TerminateOther) {
                self::closeExistingRun($schedule, $overlapPolicy);
            }

            try {
                $startResult = self::startRun(
                    $schedule,
                    effectiveOverlapPolicy: $overlapPolicy->value,
                    context: $context,
                );
            } catch (WorkflowExecutionUnavailableException $exception) {
                self::recordSkip($schedule, $exception->blockedReason(), $context);
                $schedule->forceFill([
                    'next_fire_at' => $schedule->computeNextFireAtWithJitter(),
                ])->save();

                return new ScheduleTriggerResult('skipped', null, null, $exception->blockedReason());
            }

            return new ScheduleTriggerResult('triggered', $startResult->instanceId, $startResult->runId, null);
        });
    }

    /**
     * @return list<array{schedule_id: string, instance_id: string|null}>
     */
    public static function tick(int $limit = 100): array
    {
        $results = [];

        // Phase 1: drain buffered actions for schedules whose last run finished.
        $withBuffer = WorkflowSchedule::query()
            ->where('status', ScheduleStatus::Active->value)
            ->whereNotNull('buffered_actions')
            ->limit($limit)
            ->get();

        foreach ($withBuffer as $schedule) {
            if (! $schedule->hasBufferedActions() || self::runningRunExists($schedule)) {
                continue;
            }

            try {
                $instanceId = DB::transaction(static function () use ($schedule): ?string {
                    /** @var WorkflowSchedule $schedule */
                    $schedule = WorkflowSchedule::query()->lockForUpdate()->findOrFail($schedule->id);

                    if ($schedule->drainBuffer() === null) {
                        return null;
                    }

                    $schedule->save();

                    return self::startRun($schedule, outcome: 'drained')->instanceId;
                });

                if ($instanceId !== null) {
                    $results[] = [
                        'schedule_id' => $schedule->schedule_id,
                        'instance_id' => $instanceId,
                    ];
                }
            } catch (WorkflowExecutionUnavailableException $exception) {
                $schedule->refresh();
                self::recordSkip($schedule, $exception->blockedReason());
                $results[] = [
                    'schedule_id' => $schedule->schedule_id,
                    'instance_id' => null,
                    'outcome' => 'skipped',
                    'reason' => $exception->blockedReason(),
                ];
            } catch (\Throwable $e) {
                $schedule->refresh();
                $schedule->recordFailure($e->getMessage());
                $schedule->save();
                $results[] = [
                    'schedule_id' => $schedule->schedule_id,
                    'instance_id' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Phase 2: evaluate due schedules.
        $due = WorkflowSchedule::query()
            ->where('status', ScheduleStatus::Active->value)
            ->whereNotNull('next_fire_at')
            ->where('next_fire_at', '<=', now())
            ->orderBy('next_fire_at')
            ->limit($limit)
            ->get();

        foreach ($due as $schedule) {
            try {
                $detail = self::triggerDetailed($schedule);
                $results[] = [
                    'schedule_id' => $schedule->schedule_id,
                    'instance_id' => $detail->instanceId,
                    'outcome' => $detail->outcome,
                ];
            } catch (\Throwable $e) {
                $schedule->refresh();
                $schedule->recordFailure($e->getMessage());
                $schedule->save();
                $results[] = [
                    'schedule_id' => $schedule->schedule_id,
                    'instance_id' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public static function describe(WorkflowSchedule $schedule): ScheduleDescription
    {
        return new ScheduleDescription(
            id: $schedule->id,
            scheduleId: $schedule->schedule_id,
            spec: is_array($schedule->spec) ? $schedule->spec : [],
            action: is_array($schedule->action) ? $schedule->action : [],
            status: $schedule->status ?? ScheduleStatus::Active,
            overlapPolicy: ScheduleOverlapPolicy::tryFrom($schedule->overlap_policy ?? '')
                ?? ScheduleOverlapPolicy::Skip,
            firesCount: (int) $schedule->fires_count,
            failuresCount: (int) $schedule->failures_count,
            remainingActions: $schedule->remaining_actions !== null ? (int) $schedule->remaining_actions : null,
            nextFireAt: $schedule->next_fire_at,
            lastFiredAt: $schedule->last_fired_at,
            latestInstanceId: $schedule->latest_workflow_instance_id,
            jitterSeconds: (int) $schedule->jitter_seconds,
            note: $schedule->note,
            skippedTriggerCount: (int) ($schedule->skipped_trigger_count ?? 0),
            lastSkipReason: $schedule->last_skip_reason,
            lastSkippedAt: $schedule->last_skipped_at,
            namespace: $schedule->namespace ?? 'default',
        );
    }

    public static function findByScheduleId(string $scheduleId, ?string $namespace = null): ?WorkflowSchedule
    {
        $query = WorkflowSchedule::query()->where('schedule_id', $scheduleId);

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        /** @var WorkflowSchedule|null */
        return $query->first();
    }

    /**
     * @return list<array{schedule_id: string, instance_id: string|null, cron_time: string}>
     */
    public static function backfill(
        WorkflowSchedule $schedule,
        DateTimeInterface $from,
        DateTimeInterface $to,
        ?ScheduleOverlapPolicy $overlapPolicyOverride = null,
        ?CommandContext $context = null,
    ): array {
        if ($schedule->status === ScheduleStatus::Deleted) {
            throw new LogicException(sprintf('Cannot backfill deleted schedule [%s].', $schedule->schedule_id));
        }

        $occurrences = self::enumerateOccurrences($schedule, $from, $to);
        $results = [];

        foreach ($occurrences as $occurrence) {
            $originalPolicy = ScheduleOverlapPolicy::tryFrom($schedule->overlap_policy ?? '')
                ?? ScheduleOverlapPolicy::Skip;
            $effectivePolicy = $overlapPolicyOverride ?? $originalPolicy;

            try {
                $instanceId = self::triggerForBackfill($schedule, $effectivePolicy, $occurrence['at'], $context);
                $schedule->refresh();

                $results[] = [
                    'schedule_id' => $schedule->schedule_id,
                    'instance_id' => $instanceId,
                    'cron_time' => $occurrence['cron_time'],
                ];
            } catch (\Throwable $e) {
                $schedule->refresh();
                $schedule->recordFailure($e->getMessage());
                $schedule->save();

                $results[] = [
                    'schedule_id' => $schedule->schedule_id,
                    'instance_id' => null,
                    'cron_time' => $occurrence['cron_time'],
                    'error' => $e->getMessage(),
                ];
            }

            if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
                break;
            }
        }

        return $results;
    }

    // ── Internals ────────────────────────────────────────────────────

    private static function triggerForBackfill(
        WorkflowSchedule $schedule,
        ScheduleOverlapPolicy $overlapPolicy,
        DateTimeInterface $occurrenceTime,
        ?CommandContext $context = null,
    ): ?string {
        $effectivePolicy = $overlapPolicy->isBuffer()
            ? ScheduleOverlapPolicy::AllowAll
            : $overlapPolicy;

        return DB::transaction(static function () use (
            $schedule,
            $effectivePolicy,
            $occurrenceTime,
            $context
        ): ?string {
            /** @var WorkflowSchedule $schedule */
            $schedule = WorkflowSchedule::query()->lockForUpdate()->findOrFail($schedule->id);

            if (! $schedule->status->allowsTrigger()) {
                self::recordSkip($schedule, 'status_not_triggerable', $context);

                return null;
            }

            if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
                self::recordSkip($schedule, 'remaining_actions_exhausted', $context);

                return null;
            }

            if (! self::overlapAllowed($schedule, $effectivePolicy)) {
                self::recordSkip($schedule, 'overlap_policy_' . $effectivePolicy->value, $context);

                return null;
            }

            if ($effectivePolicy === ScheduleOverlapPolicy::CancelOther || $effectivePolicy === ScheduleOverlapPolicy::TerminateOther) {
                self::closeExistingRun($schedule, $effectivePolicy);
            }

            try {
                return self::startRun(
                    $schedule,
                    occurrenceTime: $occurrenceTime,
                    outcome: 'backfilled',
                    effectiveOverlapPolicy: $effectivePolicy->value,
                    context: $context,
                )->instanceId;
            } catch (WorkflowExecutionUnavailableException $exception) {
                self::recordSkip($schedule, $exception->blockedReason(), $context);

                return null;
            }
        });
    }

    /**
     * Start a workflow run for this schedule. Caller is inside a transaction
     * with a row lock, and has already confirmed overlap policy allows this.
     */
    private static function startRun(
        WorkflowSchedule $schedule,
        ?DateTimeInterface $occurrenceTime = null,
        string $outcome = 'scheduled',
        ?string $effectiveOverlapPolicy = null,
        ?CommandContext $context = null,
    ): ScheduleStartResult {
        $starter = app(ScheduleWorkflowStarter::class);

        try {
            $result = $starter->start($schedule, $occurrenceTime, $outcome, $effectiveOverlapPolicy);
        } catch (WorkflowExecutionUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable $e) {
            $schedule->recordFailure($e->getMessage());
            $schedule->save();

            throw $e;
        }

        self::recordScheduleTriggered($schedule, $result->runId, $occurrenceTime);
        self::recordScheduleEvent($schedule, HistoryEventType::ScheduleTriggered, array_filter([
            'workflow_instance_id' => $result->instanceId,
            'workflow_run_id' => $result->runId,
            'outcome' => $outcome,
            'effective_overlap_policy' => $effectiveOverlapPolicy ?? $schedule->overlap_policy,
            'trigger_number' => (int) $schedule->fires_count + 1,
            'occurrence_time' => $occurrenceTime?->format('Y-m-d\TH:i:s.uP'),
        ], static fn (mixed $value): bool => $value !== null), $context);

        $schedule->recordFire($result->instanceId, $result->runId, $outcome);
        $schedule->forceFill([
            'recent_actions' => $schedule->recent_actions,
            'fires_count' => $schedule->fires_count,
            'last_fired_at' => $schedule->last_fired_at,
            'next_fire_at' => $schedule->next_fire_at,
            'remaining_actions' => $schedule->remaining_actions !== null
                ? max(0, (int) $schedule->remaining_actions - 1)
                : null,
            'latest_workflow_instance_id' => $result->instanceId,
        ])->save();

        if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
            $schedule->forceFill([
                'status' => ScheduleStatus::Deleted->value,
                'deleted_at' => now(),
                'next_fire_at' => null,
            ])->save();
            self::recordScheduleEvent($schedule, HistoryEventType::ScheduleDeleted, [
                'reason' => 'max_runs_exhausted',
                'deleted_at' => $schedule->deleted_at?->toIso8601String(),
            ], $context);
        }

        return $result;
    }

    private static function recordScheduleTriggered(
        WorkflowSchedule $schedule,
        ?string $runId,
        ?DateTimeInterface $occurrenceTime = null,
    ): void {
        if ($runId === null) {
            return;
        }

        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($runId);

        if ($run === null) {
            return;
        }

        $spec = is_array($schedule->spec) ? $schedule->spec : [];
        $cronExpressions = $spec['cron_expressions'] ?? [];
        $primaryCron = is_array($cronExpressions) && $cronExpressions !== []
            ? (string) reset($cronExpressions)
            : null;

        WorkflowHistoryEvent::record($run, HistoryEventType::ScheduleTriggered, array_filter([
            'schedule_id' => $schedule->schedule_id,
            'schedule_ulid' => $schedule->id,
            'cron_expression' => $primaryCron,
            'timezone' => $spec['timezone'] ?? null,
            'overlap_policy' => $schedule->overlap_policy,
            'trigger_number' => (int) $schedule->fires_count + 1,
            'occurrence_time' => $occurrenceTime?->format('Y-m-d\TH:i:s.uP'),
        ], static fn (mixed $v): bool => $v !== null));
    }

    private static function recordSkip(
        WorkflowSchedule $schedule,
        string $reason,
        ?CommandContext $context = null,
    ): void {
        $schedule->forceFill([
            'last_skip_reason' => $reason,
            'last_skipped_at' => now(),
            'skipped_trigger_count' => ($schedule->skipped_trigger_count ?? 0) + 1,
        ])->save();

        self::recordScheduleEvent($schedule, HistoryEventType::ScheduleTriggerSkipped, [
            'reason' => $reason,
            'skipped_trigger_count' => (int) $schedule->skipped_trigger_count,
            'last_skipped_at' => $schedule->last_skipped_at?->toIso8601String(),
        ], $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function recordScheduleEvent(
        WorkflowSchedule $schedule,
        HistoryEventType $eventType,
        array $payload = [],
        ?CommandContext $context = null,
    ): void {
        if ($context !== null && ! array_key_exists('command_context', $payload)) {
            $payload['command_context'] = $context->attributes();
        }

        WorkflowScheduleHistoryEvent::record($schedule, $eventType, $payload);
    }

    /**
     * @return list<array{at: DateTimeInterface, cron_time: string}>
     */
    private static function enumerateOccurrences(
        WorkflowSchedule $schedule,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        $occurrences = [];
        $cursor = \DateTimeImmutable::createFromInterface($from)->modify('-1 second');

        while (true) {
            $next = $schedule->computeNextFireAt($cursor);

            if ($next === null || $next >= $to) {
                break;
            }

            $occurrences[] = [
                'at' => $next,
                'cron_time' => $next->format('Y-m-d\TH:i:sP'),
            ];

            $cursor = $next;
        }

        return $occurrences;
    }

    private static function runningRunExists(WorkflowSchedule $schedule): bool
    {
        $latestInstanceId = $schedule->latest_workflow_instance_id;

        if ($latestInstanceId === null) {
            return false;
        }

        $instance = ConfiguredV2Models::query('instance_model', \Workflow\V2\Models\WorkflowInstance::class)
            ->find($latestInstanceId);

        if ($instance === null) {
            return false;
        }

        $latestRun = CurrentRunResolver::forInstance($instance);

        if ($latestRun === null) {
            return false;
        }

        return in_array($latestRun->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);
    }

    private static function overlapAllowed(WorkflowSchedule $schedule, ScheduleOverlapPolicy $policy): bool
    {
        if ($policy === ScheduleOverlapPolicy::AllowAll) {
            return true;
        }

        if (! self::runningRunExists($schedule)) {
            return true;
        }

        return match ($policy) {
            ScheduleOverlapPolicy::Skip => false,
            ScheduleOverlapPolicy::CancelOther, ScheduleOverlapPolicy::TerminateOther, ScheduleOverlapPolicy::AllowAll => true,
            ScheduleOverlapPolicy::BufferOne, ScheduleOverlapPolicy::BufferAll => false,
        };
    }

    private static function closeExistingRun(WorkflowSchedule $schedule, ScheduleOverlapPolicy $policy): void
    {
        $latestInstanceId = $schedule->latest_workflow_instance_id;

        if ($latestInstanceId === null) {
            return;
        }

        try {
            $stub = WorkflowStub::load($latestInstanceId);

            match ($policy) {
                ScheduleOverlapPolicy::CancelOther => $stub->attemptCancel(
                    'Schedule overlap: cancel_other policy applied.'
                ),
                ScheduleOverlapPolicy::TerminateOther => $stub->attemptTerminate(
                    'Schedule overlap: terminate_other policy applied.'
                ),
                default => null,
            };
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    private static function assertValidCron(string $expression): void
    {
        if (! CronExpression::isValidExpression($expression)) {
            throw new LogicException(sprintf('Invalid cron expression: [%s].', $expression));
        }
    }

    /**
     * @param  array<string, mixed> $spec
     */
    private static function assertValidSpec(array $spec): void
    {
        $cronExpressions = $spec['cron_expressions'] ?? [];
        $intervals = $spec['intervals'] ?? [];

        if (! is_array($cronExpressions) || ! is_array($intervals)) {
            throw new LogicException('Schedule spec cron_expressions/intervals must be arrays.');
        }

        if ($cronExpressions === [] && $intervals === []) {
            throw new LogicException('Schedule spec must include at least one cron_expression or interval.');
        }

        foreach ($cronExpressions as $expression) {
            if (! is_string($expression) || ! CronExpression::isValidExpression($expression)) {
                throw new LogicException(sprintf('Invalid cron expression: [%s].', (string) $expression));
            }
        }
    }
}
