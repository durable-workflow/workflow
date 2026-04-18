<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Support\ConfiguredV2Models;

/**
 * Canonical workflow schedule.
 *
 * Storage is two JSON descriptors:
 *   - `spec`   — when/how often to fire (cron expressions + interval specs + timezone)
 *   - `action` — what to start (workflow_type/class + task_queue + input + timeouts)
 *
 * All lifecycle state (status/paused_at/deleted_at, fires_count/failures_count,
 * recent_actions/buffered_actions, next_fire_at/last_fired_at, max_runs/remaining_actions)
 * lives in typed columns.
 */
class WorkflowSchedule extends Model
{
    use HasUlids;

    public const OVERLAP_POLICIES = [
        'skip',
        'buffer_one',
        'buffer_all',
        'cancel_other',
        'terminate_other',
        'allow_all',
    ];

    public $incrementing = false;

    protected $table = 'workflow_schedules';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'spec' => 'array',
        'action' => 'array',
        'status' => ScheduleStatus::class,
        'memo' => 'array',
        'search_attributes' => 'array',
        'visibility_labels' => 'array',
        'recent_actions' => 'array',
        'buffered_actions' => 'array',
        'overlap_policy' => 'string',
        'last_fired_at' => 'datetime',
        'next_fire_at' => 'datetime',
        'paused_at' => 'datetime',
        'deleted_at' => 'datetime',
        'last_skipped_at' => 'datetime',
        'jitter_seconds' => 'integer',
        'max_runs' => 'integer',
        'remaining_actions' => 'integer',
        'fires_count' => 'integer',
        'failures_count' => 'integer',
        'skipped_trigger_count' => 'integer',
    ];

    public function latestInstance(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'latest_workflow_instance_id',
        );
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('schedule_history_event_model', WorkflowScheduleHistoryEvent::class),
            'workflow_schedule_id',
        )->orderBy('sequence');
    }

    // ── Convenience accessors projecting spec/action JSON ────────────

    public function getWorkflowTypeAttribute(): ?string
    {
        $action = is_array($this->action) ? $this->action : [];
        $value = $action['workflow_type'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function getWorkflowClassAttribute(): ?string
    {
        $action = is_array($this->action) ? $this->action : [];
        $value = $action['workflow_class'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<int, mixed>|null
     */
    public function getWorkflowArgumentsAttribute(): ?array
    {
        $action = is_array($this->action) ? $this->action : [];
        $input = $action['input'] ?? null;

        return is_array($input) ? array_values($input) : null;
    }

    public function getCronExpressionAttribute(): ?string
    {
        $spec = is_array($this->spec) ? $this->spec : [];
        $first = $spec['cron_expressions'][0] ?? null;

        return is_string($first) ? $first : null;
    }

    public function getTimezoneAttribute(): string
    {
        $spec = is_array($this->spec) ? $this->spec : [];
        $tz = $spec['timezone'] ?? null;

        return is_string($tz) && $tz !== '' ? $tz : 'UTC';
    }

    public function isPaused(): bool
    {
        return $this->status === ScheduleStatus::Paused;
    }

    /**
     * Compute the next fire time from the schedule spec.
     *
     * Evaluates both cron expressions and interval specs and returns the
     * earliest upcoming fire time across all of them. This returns the
     * canonical (unjittered) time — use {@see computeNextFireAtWithJitter()}
     * when storing `next_fire_at` for tick evaluation.
     */
    public function computeNextFireAt(?DateTimeInterface $after = null): ?DateTimeInterface
    {
        $after ??= now();
        $spec = is_array($this->spec) ? $this->spec : [];
        $timezone = is_string($spec['timezone'] ?? null) ? $spec['timezone'] : 'UTC';

        $earliest = null;

        foreach (($spec['cron_expressions'] ?? []) as $expression) {
            if (! is_string($expression) || $expression === '') {
                continue;
            }
            $next = self::nextCronOccurrence($expression, $after, $timezone);
            if ($next !== null && ($earliest === null || $next < $earliest)) {
                $earliest = $next;
            }
        }

        foreach (($spec['intervals'] ?? []) as $interval) {
            if (! is_array($interval)) {
                continue;
            }
            $next = self::nextIntervalOccurrence($interval, $after);
            if ($next !== null && ($earliest === null || $next < $earliest)) {
                $earliest = $next;
            }
        }

        return $earliest;
    }

    /**
     * Compute the next fire time with bounded random jitter applied.
     *
     * When `jitter_seconds > 0`, a random offset in `[0, jitter_seconds]`
     * is added to the canonical fire time. This spreads schedule triggers
     * across a window to mitigate thundering-herd effects.
     */
    public function computeNextFireAtWithJitter(?DateTimeInterface $after = null): ?DateTimeInterface
    {
        $next = $this->computeNextFireAt($after);

        if ($next === null || (int) $this->jitter_seconds <= 0) {
            return $next;
        }

        return Carbon::instance($next)->addSeconds(random_int(0, (int) $this->jitter_seconds));
    }

    /**
     * @param  array<string, mixed>  $interval
     */
    public static function nextIntervalOccurrence(array $interval, DateTimeInterface $after): ?DateTimeInterface
    {
        $everySpec = $interval['every'] ?? null;

        if (! is_string($everySpec) || $everySpec === '') {
            return null;
        }

        try {
            $duration = new \DateInterval($everySpec);
        } catch (\Exception) {
            return null;
        }

        $everySeconds = self::dateIntervalToSeconds($duration);

        if ($everySeconds <= 0) {
            return null;
        }

        $offsetSeconds = 0;
        $offsetSpec = $interval['offset'] ?? null;

        if (is_string($offsetSpec) && $offsetSpec !== '') {
            try {
                $offsetSeconds = self::dateIntervalToSeconds(new \DateInterval($offsetSpec));
            } catch (\Exception) {
                // ignore invalid offset
            }
        }

        $afterTimestamp = $after->getTimestamp();
        $elapsed = $afterTimestamp - $offsetSeconds;
        $periodsPassed = (int) floor($elapsed / $everySeconds);
        $nextTimestamp = $offsetSeconds + ($periodsPassed + 1) * $everySeconds;

        return Carbon::createFromTimestamp($nextTimestamp, 'UTC');
    }

    public static function dateIntervalToSeconds(\DateInterval $interval): int
    {
        return ($interval->y * 365 * 86400)
            + ($interval->m * 30 * 86400)
            + ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }

    /**
     * Compute the next occurrence of a cron expression after a given time.
     *
     * Uses dragonmantank/cron-expression when available, otherwise falls back
     * to a minute-resolution scanner for standard 5-field cron expressions.
     */
    public static function nextCronOccurrence(
        string $expression,
        DateTimeInterface $after,
        string $timezone = 'UTC'
    ): ?DateTimeInterface {
        if (class_exists(\Cron\CronExpression::class)) {
            $cron = new \Cron\CronExpression($expression);

            return Carbon::instance($cron->getNextRunDate($after, 0, false, $timezone));
        }

        $tz = new DateTimeZone($timezone);
        $candidate = Carbon::instance($after)->setTimezone($tz)->addMinute()->startOfMinute();
        $limit = (clone $candidate)->addHours(48);

        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            return null;
        }

        [$minSpec, $hourSpec, $domSpec, $monSpec, $dowSpec] = $parts;

        while ($candidate <= $limit) {
            if (
                self::cronFieldMatches($minSpec, $candidate->minute, 0, 59)
                && self::cronFieldMatches($hourSpec, $candidate->hour, 0, 23)
                && self::cronFieldMatches($domSpec, $candidate->day, 1, 31)
                && self::cronFieldMatches($monSpec, $candidate->month, 1, 12)
                && self::cronFieldMatches($dowSpec, $candidate->dayOfWeekIso % 7, 0, 6)
            ) {
                return $candidate->setTimezone('UTC');
            }
            $candidate->addMinute();
        }

        return null;
    }

    /**
     * Normalize the `workflow_execution_timeout` / `workflow_run_timeout` aliases
     * into `execution_timeout_seconds` / `run_timeout_seconds`. Accepts the
     * legacy HTTP field names for ergonomics.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public static function normalizeActionTimeouts(array $action): array
    {
        if (! isset($action['execution_timeout_seconds']) && isset($action['workflow_execution_timeout'])) {
            $action['execution_timeout_seconds'] = (int) $action['workflow_execution_timeout'];
        }

        if (! isset($action['run_timeout_seconds']) && isset($action['workflow_run_timeout'])) {
            $action['run_timeout_seconds'] = (int) $action['workflow_run_timeout'];
        }

        unset($action['workflow_execution_timeout'], $action['workflow_run_timeout']);

        return $action;
    }

    // ── Buffer queue ──────────────────────────────────────────────────

    public function bufferAction(): void
    {
        $buffer = $this->buffered_actions ?? [];
        $buffer[] = [
            'buffered_at' => now()
                ->toIso8601String(),
        ];
        $this->buffered_actions = array_values($buffer);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function drainBuffer(): ?array
    {
        $buffer = $this->buffered_actions ?? [];

        if ($buffer === []) {
            return null;
        }

        $next = array_shift($buffer);
        $this->buffered_actions = $buffer === [] ? null : array_values($buffer);

        return is_array($next) ? $next : null;
    }

    public function hasBufferedActions(): bool
    {
        return ! empty($this->buffered_actions);
    }

    public function isAtBufferCapacity(string $overlapPolicy): bool
    {
        if ($overlapPolicy !== 'buffer_one') {
            return false;
        }

        return count($this->buffered_actions ?? []) >= 1;
    }

    // ── Recent-actions ring ──────────────────────────────────────────

    public function recordFire(string $workflowId, ?string $runId, string $outcome): void
    {
        $actions = $this->recent_actions ?? [];
        $actions[] = [
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'outcome' => $outcome,
            'fired_at' => now()
                ->toIso8601String(),
        ];

        if (count($actions) > 10) {
            $actions = array_slice($actions, -10);
        }

        $this->recent_actions = array_values($actions);
        $this->fires_count = (int) $this->fires_count + 1;
        $this->last_fired_at = now();
        $this->next_fire_at = $this->computeNextFireAtWithJitter();
    }

    public function recordFailure(string $reason): void
    {
        $actions = $this->recent_actions ?? [];
        $actions[] = [
            'outcome' => 'failed',
            'reason' => $reason,
            'fired_at' => now()
                ->toIso8601String(),
        ];

        if (count($actions) > 10) {
            $actions = array_slice($actions, -10);
        }

        $this->recent_actions = array_values($actions);
        $this->failures_count = (int) $this->failures_count + 1;
        $this->last_fired_at = now();
        $this->next_fire_at = $this->computeNextFireAtWithJitter();
    }

    // ── HTTP shaping ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function toListItem(): array
    {
        $action = is_array($this->action) ? $this->action : [];

        return [
            'schedule_id' => $this->schedule_id,
            'workflow_type' => $action['workflow_type'] ?? null,
            'status' => $this->status?->value,
            'paused' => $this->isPaused(),
            'next_fire' => $this->next_fire_at?->toIso8601String(),
            'last_fire' => $this->last_fired_at?->toIso8601String(),
            'overlap_policy' => $this->overlap_policy,
            'note' => $this->note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetail(): array
    {
        return [
            'schedule_id' => $this->schedule_id,
            'spec' => $this->spec,
            'action' => is_array($this->action) ? self::normalizeActionTimeouts($this->action) : null,
            'overlap_policy' => $this->overlap_policy,
            'state' => [
                'status' => $this->status?->value,
                'paused' => $this->isPaused(),
                'note' => $this->note,
            ],
            'info' => [
                'next_fire' => $this->next_fire_at?->toIso8601String(),
                'last_fire' => $this->last_fired_at?->toIso8601String(),
                'fires_count' => (int) $this->fires_count,
                'failures_count' => (int) $this->failures_count,
                'recent_actions' => $this->recent_actions ?? [],
                'buffered_actions' => $this->buffered_actions ?? [],
            ],
            'memo' => $this->memo,
            'search_attributes' => $this->search_attributes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private static function cronFieldMatches(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') {
            return true;
        }

        foreach (explode(',', $field) as $part) {
            if (str_contains($part, '/')) {
                [$range, $step] = explode('/', $part, 2);
                $step = (int) $step;
                if ($step < 1) {
                    continue;
                }

                if ($range === '*') {
                    $rangeStart = $min;
                    $rangeEnd = $max;
                } elseif (str_contains($range, '-')) {
                    [$rangeStart, $rangeEnd] = array_map('intval', explode('-', $range, 2));
                } else {
                    continue;
                }

                for ($i = $rangeStart; $i <= $rangeEnd; $i += $step) {
                    if ($i === $value) {
                        return true;
                    }
                }

                continue;
            }

            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                if ($value >= $start && $value <= $end) {
                    return true;
                }

                continue;
            }

            if ((int) $part === $value) {
                return true;
            }
        }

        return false;
    }
}
