<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class ScheduleManager
{
    /**
     * @param class-string $workflowClass
     * @param array<int, mixed> $arguments
     * @param array<string, string> $labels
     * @param array<string, mixed> $memo
     * @param array<string, scalar|null> $searchAttributes
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
    ): WorkflowSchedule {
        self::assertValidCron($cronExpression);

        $workflowType = TypeRegistry::for($workflowClass);
        $namespace = config('workflows.v2.namespace');
        $nextRunAt = self::nextRunTime($cronExpression, $timezone);

        /** @var WorkflowSchedule $schedule */
        $schedule = WorkflowSchedule::query()->create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'workflow_type' => $workflowType,
            'workflow_class' => $workflowClass,
            'cron_expression' => $cronExpression,
            'timezone' => $timezone,
            'status' => ScheduleStatus::Active->value,
            'overlap_policy' => $overlapPolicy->value,
            'workflow_arguments' => $arguments !== [] ? $arguments : null,
            'memo' => $memo !== [] ? $memo : null,
            'search_attributes' => $searchAttributes !== [] ? $searchAttributes : null,
            'visibility_labels' => $labels !== [] ? $labels : null,
            'jitter_seconds' => $jitterSeconds,
            'max_runs' => $maxRuns,
            'total_runs' => 0,
            'remaining_actions' => $maxRuns,
            'connection' => $connection,
            'queue' => $queue,
            'notes' => $notes,
            'next_run_at' => $nextRunAt,
        ]);

        return $schedule;
    }

    public static function pause(WorkflowSchedule $schedule, ?string $reason = null): WorkflowSchedule
    {
        if ($schedule->status === ScheduleStatus::Deleted) {
            throw new LogicException(sprintf('Cannot pause deleted schedule [%s].', $schedule->schedule_id));
        }

        $schedule->forceFill([
            'status' => ScheduleStatus::Paused->value,
            'paused_at' => now(),
        ])->save();

        return $schedule;
    }

    public static function resume(WorkflowSchedule $schedule): WorkflowSchedule
    {
        if ($schedule->status !== ScheduleStatus::Paused) {
            throw new LogicException(sprintf('Schedule [%s] is not paused.', $schedule->schedule_id));
        }

        $nextRunAt = self::nextRunTime($schedule->cron_expression, $schedule->timezone);

        $schedule->forceFill([
            'status' => ScheduleStatus::Active->value,
            'paused_at' => null,
            'next_run_at' => $nextRunAt,
        ])->save();

        return $schedule;
    }

    public static function update(
        WorkflowSchedule $schedule,
        ?string $cronExpression = null,
        ?string $timezone = null,
        ?ScheduleOverlapPolicy $overlapPolicy = null,
        ?int $jitterSeconds = null,
        ?string $notes = null,
    ): WorkflowSchedule {
        if ($schedule->status === ScheduleStatus::Deleted) {
            throw new LogicException(sprintf('Cannot update deleted schedule [%s].', $schedule->schedule_id));
        }

        if ($cronExpression !== null) {
            self::assertValidCron($cronExpression);
        }

        $updates = array_filter([
            'cron_expression' => $cronExpression,
            'timezone' => $timezone,
            'overlap_policy' => $overlapPolicy?->value,
            'jitter_seconds' => $jitterSeconds,
            'notes' => $notes,
        ], static fn (mixed $v): bool => $v !== null);

        if ($updates !== []) {
            $schedule->forceFill($updates)->save();
        }

        if ($cronExpression !== null || $timezone !== null) {
            $nextRunAt = self::nextRunTime(
                $schedule->cron_expression,
                $schedule->timezone,
            );
            $schedule->forceFill(['next_run_at' => $nextRunAt])->save();
        }

        return $schedule;
    }

    public static function delete(WorkflowSchedule $schedule): WorkflowSchedule
    {
        if ($schedule->status === ScheduleStatus::Deleted) {
            return $schedule;
        }

        $schedule->forceFill([
            'status' => ScheduleStatus::Deleted->value,
            'deleted_at' => now(),
            'next_run_at' => null,
        ])->save();

        return $schedule;
    }

    public static function trigger(WorkflowSchedule $schedule): ?string
    {
        return DB::transaction(static function () use ($schedule): ?string {
            /** @var WorkflowSchedule $schedule */
            $schedule = WorkflowSchedule::query()
                ->lockForUpdate()
                ->findOrFail($schedule->id);

            if (! $schedule->status->allowsTrigger()) {
                return null;
            }

            if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
                return null;
            }

            $overlapPolicy = ScheduleOverlapPolicy::tryFrom($schedule->overlap_policy ?? '')
                ?? ScheduleOverlapPolicy::Skip;

            if (! self::overlapAllowed($schedule, $overlapPolicy)) {
                $schedule->forceFill([
                    'next_run_at' => self::nextRunTime($schedule->cron_expression, $schedule->timezone),
                ])->save();

                return null;
            }

            if ($overlapPolicy === ScheduleOverlapPolicy::CancelOther || $overlapPolicy === ScheduleOverlapPolicy::TerminateOther) {
                self::closeExistingRun($schedule, $overlapPolicy);
            }

            $stub = WorkflowStub::make(
                $schedule->workflow_class,
                sprintf('schedule:%s:%s', $schedule->schedule_id, now()->getTimestampMs()),
            );

            $startOptions = new StartOptions(
                labels: is_array($schedule->visibility_labels) ? $schedule->visibility_labels : [],
                memo: is_array($schedule->memo) ? $schedule->memo : [],
                searchAttributes: is_array($schedule->search_attributes) ? $schedule->search_attributes : [],
            );

            $arguments = is_array($schedule->workflow_arguments) ? $schedule->workflow_arguments : [];
            $arguments[] = $startOptions;

            $result = $stub->start(...$arguments);

            $schedule->forceFill([
                'total_runs' => $schedule->total_runs + 1,
                'remaining_actions' => $schedule->remaining_actions !== null
                    ? max(0, $schedule->remaining_actions - 1)
                    : null,
                'last_triggered_at' => now(),
                'latest_workflow_instance_id' => $result->instanceId(),
                'next_run_at' => self::nextRunTime($schedule->cron_expression, $schedule->timezone),
            ])->save();

            if ($schedule->remaining_actions !== null && $schedule->remaining_actions <= 0) {
                $schedule->forceFill([
                    'status' => ScheduleStatus::Deleted->value,
                    'deleted_at' => now(),
                    'next_run_at' => null,
                ])->save();
            }

            return $result->instanceId();
        });
    }

    /**
     * @return list<array{schedule_id: string, instance_id: string|null}>
     */
    public static function tick(): array
    {
        $dueSchedules = WorkflowSchedule::query()
            ->where('status', ScheduleStatus::Active->value)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(100)
            ->get();

        $triggered = [];

        foreach ($dueSchedules as $schedule) {
            $instanceId = self::trigger($schedule);
            $triggered[] = [
                'schedule_id' => $schedule->schedule_id,
                'instance_id' => $instanceId,
            ];
        }

        return $triggered;
    }

    public static function describe(WorkflowSchedule $schedule): ScheduleDescription
    {
        return new ScheduleDescription(
            id: $schedule->id,
            scheduleId: $schedule->schedule_id,
            workflowType: $schedule->workflow_type,
            workflowClass: $schedule->workflow_class,
            cronExpression: $schedule->cron_expression,
            timezone: $schedule->timezone,
            status: $schedule->status,
            overlapPolicy: ScheduleOverlapPolicy::tryFrom($schedule->overlap_policy ?? '')
                ?? ScheduleOverlapPolicy::Skip,
            totalRuns: (int) $schedule->total_runs,
            remainingActions: $schedule->remaining_actions !== null ? (int) $schedule->remaining_actions : null,
            nextRunAt: $schedule->next_run_at,
            lastTriggeredAt: $schedule->last_triggered_at,
            latestInstanceId: $schedule->latest_workflow_instance_id,
            jitterSeconds: (int) $schedule->jitter_seconds,
            notes: $schedule->notes,
        );
    }

    public static function findByScheduleId(string $scheduleId): ?WorkflowSchedule
    {
        /** @var WorkflowSchedule|null */
        return WorkflowSchedule::query()
            ->where('schedule_id', $scheduleId)
            ->first();
    }

    private static function overlapAllowed(WorkflowSchedule $schedule, ScheduleOverlapPolicy $policy): bool
    {
        if ($policy === ScheduleOverlapPolicy::AllowAll) {
            return true;
        }

        $latestInstanceId = $schedule->latest_workflow_instance_id;

        if ($latestInstanceId === null) {
            return true;
        }

        $instance = ConfiguredV2Models::query('instance_model', \Workflow\V2\Models\WorkflowInstance::class)
            ->find($latestInstanceId);

        if ($instance === null) {
            return true;
        }

        $latestRun = CurrentRunResolver::forInstance($instance);

        if ($latestRun === null) {
            return true;
        }

        $isStillRunning = in_array($latestRun->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);

        if (! $isStillRunning) {
            return true;
        }

        return match ($policy) {
            ScheduleOverlapPolicy::Skip, ScheduleOverlapPolicy::BufferOne => false,
            ScheduleOverlapPolicy::CancelOther, ScheduleOverlapPolicy::TerminateOther, ScheduleOverlapPolicy::AllowAll => true,
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
                ScheduleOverlapPolicy::CancelOther => $stub->attemptCancel('Schedule overlap: cancel_other policy applied.'),
                ScheduleOverlapPolicy::TerminateOther => $stub->attemptTerminate('Schedule overlap: terminate_other policy applied.'),
                default => null,
            };
        } catch (\Throwable) {
            // Best-effort: if the run is already closed or unavailable, proceed.
        }
    }

    private static function nextRunTime(string $cronExpression, string $timezone): DateTimeImmutable
    {
        $cron = new CronExpression($cronExpression);
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));

        return DateTimeImmutable::createFromMutable($cron->getNextRunDate($now));
    }

    private static function assertValidCron(string $expression): void
    {
        if (! CronExpression::isValidExpression($expression)) {
            throw new LogicException(sprintf('Invalid cron expression: [%s].', $expression));
        }
    }
}
