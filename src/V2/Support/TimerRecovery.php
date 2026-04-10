<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;

final class TimerRecovery
{
    public static function restore(WorkflowRun $run, string $timerId): ?WorkflowTimer
    {
        $run->loadMissing(['timers', 'historyEvents']);

        /** @var WorkflowTimer|null $timer */
        $timer = $run->timers->firstWhere('id', $timerId);

        if ($timer instanceof WorkflowTimer) {
            return $timer;
        }

        /** @var WorkflowTimer|null $timer */
        $timer = WorkflowTimer::query()->find($timerId);

        if ($timer instanceof WorkflowTimer) {
            return $timer;
        }

        $snapshot = RunTimerView::timerById($run, $timerId);

        if ($snapshot === null) {
            return null;
        }

        $sequence = self::intValue($snapshot['sequence'] ?? null);
        $status = self::timerStatus($snapshot['status'] ?? null);

        if ($sequence === null || ! $status instanceof TimerStatus) {
            return null;
        }

        /** @var WorkflowTimer $timer */
        $timer = WorkflowTimer::query()->create([
            'id' => $timerId,
            'workflow_run_id' => $run->id,
            'sequence' => $sequence,
            'status' => $status->value,
            'delay_seconds' => self::intValue($snapshot['delay_seconds'] ?? null),
            'fire_at' => self::timestamp($snapshot['fire_at'] ?? null),
            'fired_at' => self::timestamp($snapshot['fired_at'] ?? null),
            'created_at' => self::timestamp($snapshot['created_at'] ?? null) ?? now(),
            'updated_at' => self::timestamp($snapshot['fired_at'] ?? null)
                ?? self::timestamp($snapshot['created_at'] ?? null)
                ?? now(),
        ]);

        return $timer;
    }

    private static function timerStatus(mixed $value): ?TimerStatus
    {
        return is_string($value)
            ? TimerStatus::tryFrom($value)
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function timestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : null;
    }
}
