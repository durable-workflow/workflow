<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;

/** @internal Read already-claimed metadata without querying or changing durable state. */
final class TaskTraceTimings
{
    /**
     * @return array<string, int|float>
     */
    public static function forTask(WorkflowTask $task, ?WorkflowTimer $timer = null): array
    {
        $attributes = [];
        try {
            $seconds = self::elapsed(self::date($task, 'available_at'), self::date($task, 'leased_at'));
            if ($seconds !== null) {
                $attributes['durable.task.queue_wait_seconds'] = $seconds;
            }
        } catch (Throwable) {
            // Optional date casts must not prevent execution.
        }
        try {
            $payload = $task->payload;
            $backoff = is_array($payload) ? ($payload['retry_backoff_seconds'] ?? null) : null;
            if ($task->task_type === TaskType::Activity && is_int($backoff) && $backoff >= 0
                && is_int($payload['retry_after_attempt'] ?? null) && $payload['retry_after_attempt'] > 0
                && is_string($payload['retry_after_attempt_id'] ?? null) && $payload['retry_after_attempt_id'] !== '') {
                $attributes['durable.task.retry_backoff_seconds'] = $backoff;
            }
        } catch (Throwable) {
            // Unreadable retry metadata supplies no inferred backoff.
        }
        try {
            if ($timer === null || $task->task_type !== TaskType::Timer
                || $timer->workflow_run_id !== $task->workflow_run_id
                || $timer->id !== ($task->payload['timer_id'] ?? null)) {
                return $attributes;
            }
            $delay = $timer->delay_seconds;
            if (is_int($delay) && $delay >= 0) {
                $attributes['durable.timer.requested_wait_seconds'] = $delay;
            }
            $seconds = self::elapsed(self::date($timer, 'fire_at'), self::date($task, 'leased_at'));
            if ($seconds !== null) {
                $attributes['durable.timer.lateness_seconds'] = $seconds;
            }
        } catch (Throwable) {
            // Timer authority and outcomes do not depend on telemetry reads.
        }

        return $attributes;
    }

    private static function date(WorkflowTask|WorkflowTimer $model, string $field): ?DateTimeInterface
    {
        $value = $model->getAttribute($field);
        if (! $value instanceof DateTimeInterface) {
            return null;
        }
        $raw = $model->getRawOriginal($field);
        if ($raw instanceof DateTimeInterface) {
            return $value;
        }
        if (! is_string($raw)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat(
            str_contains($raw, '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s',
            $raw,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d H:i:s.u') !== $value->format('Y-m-d H:i:s.u')) {
            return null;
        }

        return $value;
    }

    private static function elapsed(?DateTimeInterface $start, ?DateTimeInterface $end): ?float
    {
        if (! $start instanceof DateTimeInterface || ! $end instanceof DateTimeInterface) {
            return null;
        }
        $seconds = ($end->getTimestamp() - $start->getTimestamp())
            + ((int) $end->format('u') - (int) $start->format('u')) / 1_000_000;

        return is_finite($seconds) && $seconds >= 0 ? $seconds : null;
    }
}
