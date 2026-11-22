<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** Selects a run's highest-priority open task without hydrating siblings. */
final class RunSummaryTaskSelection
{
    public static function next(WorkflowRun $run): ?WorkflowTask
    {
        /** @var Builder<WorkflowTask> $tasks */
        $tasks = $run->tasks()
            ->getQuery()
            ->reorder();
        $format = $tasks->getModel()
            ->getDateFormat();
        if ($format !== 'Y-m-d H:i:s.u' || ! in_array(date_default_timezone_get(), ['UTC', 'Etc/UTC'], true)) {
            // Other formats or local times crossing DST need PHP date ordering.
            return null;
        }

        $leased = (clone $tasks)->where('status', TaskStatus::Leased->value);
        if ($leased->exists()) {
            $expired = (clone $leased)->where('lease_expires_at', '<=', now()->format($format));
            $tasks = $expired->exists() ? $expired : $leased;
        } else {
            $tasks->where('status', TaskStatus::Ready->value);
        }

        if (! self::restrictToEarliestMillisecond($tasks, 'available_at', $format)
            || ! self::restrictToEarliestMillisecond($tasks, 'created_at', $format)) {
            return null;
        }

        return $tasks->orderBy('id')
            ->first();
    }

    /**
     * The PHP projection rounds to milliseconds and sorts nulls last. Seek to
     * the earliest indexed timestamp, then keep its entire rounded bucket.
     * Most buckets contain one timestamp. Equality lets the index also order
     * the remaining columns, including the common equal-timestamp fan-out.
     *
     * @param Builder<WorkflowTask> $tasks
     * @param 'available_at'|'created_at' $column
     */
    private static function restrictToEarliestMillisecond(Builder $tasks, string $column, string $format): bool
    {
        $first = (clone $tasks)->whereNotNull($column)
            ->orderBy($column)
            ->first([$column]);
        if ($first === null) {
            $tasks->whereNull($column);

            return true;
        }

        $date = $first->getAttribute($column);
        $stored = $first->getRawOriginal($column);
        if (! $date instanceof CarbonInterface || ! is_string($stored)) {
            return false;
        }

        $millisecond = $date->getTimestampMs();
        $upper = Carbon::createFromTimestampMs($millisecond, $date->getTimezone())->addMicroseconds(500);
        if ($upper->getTimestampMs() === $millisecond) {
            // Rounding half away from zero keeps a negative endpoint in the bucket.
            $upper->addMicrosecond();
        }
        if ($upper->getTimestampMs() !== $millisecond + 1) {
            return false;
        }

        $upperBound = $upper->format($format);
        if ((clone $tasks)->where($column, '>', $stored)->where($column, '<', $upperBound)->exists()) {
            $tasks->where($column, '>=', $stored)
                ->where($column, '<', $upperBound);
        } else {
            $tasks->where($column, $stored);
        }

        return true;
    }
}
