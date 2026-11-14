<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowScheduleOccurrence;

/** @internal Retains audit snapshots without forgetting an admitted occurrence. */
final class ScheduleAuditRetention
{
    public const DEFAULT_LIMIT = 1000;

    private const BATCH_SIZE = 100;

    public static function limit(): ?int
    {
        $value = config('workflows.v2.schedules.audit_history_limit', self::DEFAULT_LIMIT);
        if ($value === null) {
            return null;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (! is_int($value) || $value < 2) {
            throw new InvalidArgumentException(
                'Schedule audit history limit must be an integer of at least 2, or null.'
            );
        }
        return $value;
    }

    /**
     * @template T
     * @param Closure(WorkflowSchedule): T $operation
     * @return T
     */
    public static function locked(WorkflowSchedule $schedule, Closure $operation): mixed
    {
        $connection = $schedule->getConnection();
        $model = ConfiguredV2Models::resolve('schedule_model', WorkflowSchedule::class);
        $query = (new $model())->setConnection($connection->getName())
            ->newQuery();

        return SqliteTransaction::run(static function () use ($query, $schedule, $operation): mixed {
            $locked = (clone $query)->whereKey($schedule->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->getKey() !== $schedule->getKey() || $locked->namespace !== $schedule->namespace
                || $locked->schedule_id !== $schedule->schedule_id) {
                throw new LogicException('The schedule identity changed before audit retention acquired its lock.');
            }
            return $operation($locked);
        }, admission: static function () use ($query, $schedule): void {
            $key = $query->getModel()
                ->getKeyName();
            (clone $query)->whereKey($schedule->getKey())
                ->toBase()
                ->update([
                    $key => $schedule->getKey(),
                ]);
        }, connection: $connection);
    }

    public static function prune(WorkflowSchedule $schedule): int
    {
        return self::locked($schedule, self::pruneLocked(...));
    }

    /**
     * Caller holds the schedule's write lock for the entire audit append/prune.
     */
    public static function pruneLocked(WorkflowSchedule $schedule): int
    {
        $limit = self::limit();
        if ($limit === null) {
            return 0;
        }
        $history = self::history($schedule);
        $first = (clone $history)->min('sequence');
        $cutoff = (clone $history)->orderByDesc('sequence')
            ->skip($limit - 2)
            ->value('sequence');
        if ($first === null || $cutoff === null) {
            return 0;
        }
        $events = (clone $history)->where('sequence', '>', $first)
            ->where('sequence', '<', $cutoff)
            ->orderBy('sequence')
            ->limit(self::BATCH_SIZE)->toBase()
            ->get(['id', 'event_type', 'occurrence_at_utc']);
        foreach ($events as $event) {
            if ($event->event_type !== HistoryEventType::ScheduleTriggered->value) {
                continue;
            }
            $key = $event->occurrence_at_utc;
            if ($key === null) {
                // Extract metadata in SQL; do not load or decode the full
                // schedule/action snapshot or invoke an application cast.
                $clock = (clone $history)->whereKey($event->id)
                    ->toBase()
                    ->value('payload->occurrence_time');
                if ($clock === null) {
                    continue; // A manual trigger has no scheduled occurrence.
                }
                if (! is_string($clock) || strlen($clock) > 64
                    || preg_match(
                        '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D',
                        $clock
                    ) !== 1) {
                    throw new LogicException(
                        'A legacy schedule occurrence cannot be established; its audit row was retained.'
                    );
                }
                try {
                    $occurrence = new DateTimeImmutable($clock);
                    $errors = DateTimeImmutable::getLastErrors();
                    if ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)) {
                        throw new LogicException('The legacy occurrence clock is invalid.');
                    }
                    $key = WorkflowScheduleHistoryEvent::utcOccurrenceKey($occurrence);
                } catch (\Exception $error) {
                    throw new LogicException(
                        'A legacy schedule occurrence cannot be established; its audit row was retained.',
                        0,
                        $error
                    );
                }
            }
            self::retainOccurrence($schedule, $key);
        }
        if ($events->isEmpty()) {
            return 0;
        }
        return (clone $history)->whereKey($events->pluck('id')->all())
            ->delete();
    }

    public static function retainOccurrence(WorkflowSchedule $schedule, string $key): void
    {
        $clock = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $key, new DateTimeZone('UTC'));
        if ($clock === false || $clock->format('Y-m-d H:i:s.u') !== $key) {
            throw new LogicException('A noncanonical schedule occurrence cannot replace retained audit evidence.');
        }
        $model = ConfiguredV2Models::resolve('schedule_occurrence_model', WorkflowScheduleOccurrence::class);
        $receipt = (new $model())->setConnection($schedule->getConnectionName())
            ->newQuery()
            ->firstOrCreate([
                'workflow_schedule_id' => $schedule->getKey(),
                'occurrence_at_utc' => $key,
            ], [
                'namespace' => $schedule->namespace,
            ]);
        if ($receipt->namespace !== $schedule->namespace) {
            throw new LogicException('The schedule occurrence receipt has conflicting namespace ownership.');
        }
    }

    /**
     * Delete only rows whose internal schedule owner no longer exists.
     */
    public static function cleanup(?string $namespace, int $limit = 500): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Schedule audit cleanup limit must be between 1 and 1000.');
        }
        $schedule = ConfiguredV2Models::query('schedule_model', WorkflowSchedule::class)->getModel();
        $connection = $schedule->getConnection();

        return SqliteTransaction::run(static function () use ($namespace, $limit, $schedule, $connection): array {
            $report = [
                'audit_events_deleted' => 0,
                'occurrences_deleted' => 0,
            ];
            $remaining = $limit;
            foreach ([
                ['schedule_history_event_model', WorkflowScheduleHistoryEvent::class, 'audit_events_deleted'],
                ['schedule_occurrence_model', WorkflowScheduleOccurrence::class, 'occurrences_deleted'],
            ] as [$config, $default, $field]) {
                if ($remaining === 0) {
                    break;
                }
                $model = ConfiguredV2Models::resolve($config, $default);
                $query = NamespaceIdentity::constrain(
                    (new $model())->setConnection($connection->getName())
                        ->newQuery(),
                    $namespace,
                );
                $owners = $schedule->newQuery()
                    ->toBase()
                    ->selectRaw('1');
                $owners->whereRaw(NamespaceIdentity::columnsEqual(
                    $owners,
                    $schedule->qualifyColumn('id'),
                    $query->getModel()
                        ->qualifyColumn('workflow_schedule_id'),
                ));
                $query->whereNotExists($owners);
                $ids = (clone $query)->orderBy('id')
                    ->limit($remaining)
                    ->pluck('id')
                    ->all();
                if ($ids !== []) {
                    $report[$field] = (clone $query)->whereKey($ids)->delete();
                    $remaining -= $report[$field];
                }
            }
            $report['limit_reached'] = $remaining === 0;
            return $report;
        }, connection: $connection);
    }

    private static function history(WorkflowSchedule $schedule): Builder
    {
        $model = ConfiguredV2Models::resolve('schedule_history_event_model', WorkflowScheduleHistoryEvent::class);
        return (new $model())->setConnection($schedule->getConnectionName())
            ->newQuery()
            ->where('workflow_schedule_id', $schedule->getKey());
    }
}
