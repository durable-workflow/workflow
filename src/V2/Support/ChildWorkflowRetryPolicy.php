<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class ChildWorkflowRetryPolicy
{
    public const SNAPSHOT_VERSION = 1;

    /**
     * @param array<string, mixed>|null $retryPolicy
     * @return array{
     *     snapshot_version: int,
     *     max_attempts: int,
     *     backoff_seconds: list<int>,
     *     non_retryable_error_types: list<string>
     * }|null
     */
    public static function snapshotExternal(?array $retryPolicy): ?array
    {
        $retryPolicy ??= [];

        if ($retryPolicy === []) {
            return null;
        }

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'max_attempts' => is_int($retryPolicy['max_attempts'] ?? null)
                ? max(1, (int) $retryPolicy['max_attempts'])
                : 1,
            'backoff_seconds' => is_array($retryPolicy['backoff_seconds'] ?? null)
                ? self::normalizeBackoff($retryPolicy['backoff_seconds'])
                : [],
            'non_retryable_error_types' => is_array($retryPolicy['non_retryable_error_types'] ?? null)
                ? self::normalizeErrorTypes($retryPolicy['non_retryable_error_types'])
                : [],
        ];
    }

    /**
     * @return array{
     *     snapshot_version: int,
     *     execution_timeout_seconds: int|null,
     *     run_timeout_seconds: int|null
     * }|null
     */
    public static function timeoutSnapshot(?int $executionTimeoutSeconds, ?int $runTimeoutSeconds): ?array
    {
        if ($executionTimeoutSeconds === null && $runTimeoutSeconds === null) {
            return null;
        }

        return [
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'execution_timeout_seconds' => $executionTimeoutSeconds,
            'run_timeout_seconds' => $runTimeoutSeconds,
        ];
    }

    /**
     * @param array<string, mixed>|null $policy
     */
    public static function maxAttempts(?array $policy): int
    {
        return is_int($policy['max_attempts'] ?? null) ? max(1, (int) $policy['max_attempts']) : 1;
    }

    /**
     * @param array<string, mixed>|null $policy
     */
    public static function backoffSeconds(?array $policy, int $completedAttemptCount): int
    {
        $backoff = is_array($policy['backoff_seconds'] ?? null)
            ? self::normalizeBackoff($policy['backoff_seconds'])
            : [];

        if ($backoff === []) {
            return 0;
        }

        $index = max(0, $completedAttemptCount - 1);

        return $backoff[min($index, count($backoff) - 1)];
    }

    /**
     * @param array<string, mixed>|null $policy
     */
    public static function isNonRetryableFailure(?array $policy, WorkflowRun $childRun): bool
    {
        $childRun->loadMissing(['historyEvents', 'failures']);

        /** @var WorkflowFailure|null $failure */
        $failure = $childRun->failures->first();

        if ((bool) ($failure?->non_retryable ?? false)) {
            return true;
        }

        $types = self::normalizeErrorTypes($policy['non_retryable_error_types'] ?? []);

        if ($types === []) {
            return false;
        }

        /** @var WorkflowHistoryEvent|null $terminalEvent */
        $terminalEvent = $childRun->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowFailed
            )
            ->sortByDesc('sequence')
            ->first();

        $candidates = array_values(array_filter([
            is_string($terminalEvent?->payload['exception_type'] ?? null)
                ? $terminalEvent->payload['exception_type']
                : null,
            is_string($terminalEvent?->payload['exception_class'] ?? null)
                ? $terminalEvent->payload['exception_class']
                : null,
            is_string($failure?->exception_class) ? $failure->exception_class : null,
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function normalizeBackoff(mixed $backoff): array
    {
        if (! is_array($backoff)) {
            return [];
        }

        return array_values(array_map(
            static fn (int|string $value): int => max(0, (int) $value),
            array_filter(
                $backoff,
                static fn (mixed $value): bool => is_int($value) || (is_string($value) && is_numeric($value)),
            ),
        ));
    }

    /**
     * @return list<string>
     */
    private static function normalizeErrorTypes(mixed $types): array
    {
        if (! is_array($types)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $types),
            static fn (string $value): bool => $value !== '',
        )));
    }
}
