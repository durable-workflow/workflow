<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\StructuralLimitKind;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * Reads structural-limit configuration and enforces ceilings.
 *
 * Limits are configurable through `workflows.v2.structural_limits.*`.
 * A value of 0 disables the check for that limit kind.
 *
 * When `warning_threshold_percent` is set (default 80), the engine
 * logs a warning each time a count-based resource crosses that
 * percentage of its hard ceiling. This gives operators time to
 * react (continue-as-new, scale, raise limits) before the hard
 * guard terminates the run.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures and constant names on this class
 *      are covered by the workflow package's semver guarantee. See
 *      docs/api-stability.md.
 */
final class StructuralLimits
{
    // ── Default ceilings ────────────────────────────────────────────
    // Generous defaults that protect against runaway fan-out without
    // restricting typical workloads. Operators can tighten or loosen
    // per-environment through config.

    public const DEFAULT_PENDING_ACTIVITY_COUNT = 2000;

    public const DEFAULT_PENDING_CHILD_COUNT = 1000;

    public const DEFAULT_PENDING_TIMER_COUNT = 2000;

    public const DEFAULT_PENDING_SIGNAL_COUNT = 5000;

    public const DEFAULT_PENDING_UPDATE_COUNT = 500;

    public const DEFAULT_COMMAND_BATCH_SIZE = 1000;

    public const DEFAULT_PAYLOAD_SIZE_BYTES = 2097152;       // 2 MiB

    public const DEFAULT_MEMO_SIZE_BYTES = 262144;           // 256 KiB

    public const DEFAULT_SEARCH_ATTRIBUTE_SIZE_BYTES = 40960; // 40 KiB

    public const DEFAULT_HISTORY_TRANSACTION_SIZE = 5000;

    public const DEFAULT_WARNING_THRESHOLD_PERCENT = 80;

    // ── Config readers ──────────────────────────────────────────────

    public static function pendingActivityLimit(): int
    {
        return self::intConfig('pending_activity_count', self::DEFAULT_PENDING_ACTIVITY_COUNT);
    }

    public static function pendingChildLimit(): int
    {
        return self::intConfig('pending_child_count', self::DEFAULT_PENDING_CHILD_COUNT);
    }

    public static function pendingTimerLimit(): int
    {
        return self::intConfig('pending_timer_count', self::DEFAULT_PENDING_TIMER_COUNT);
    }

    public static function pendingSignalLimit(): int
    {
        return self::intConfig('pending_signal_count', self::DEFAULT_PENDING_SIGNAL_COUNT);
    }

    public static function pendingUpdateLimit(): int
    {
        return self::intConfig('pending_update_count', self::DEFAULT_PENDING_UPDATE_COUNT);
    }

    public static function commandBatchSizeLimit(): int
    {
        return self::intConfig('command_batch_size', self::DEFAULT_COMMAND_BATCH_SIZE);
    }

    public static function payloadSizeLimit(): int
    {
        return self::intConfig('payload_size_bytes', self::DEFAULT_PAYLOAD_SIZE_BYTES);
    }

    public static function memoSizeLimit(): int
    {
        return self::intConfig('memo_size_bytes', self::DEFAULT_MEMO_SIZE_BYTES);
    }

    public static function searchAttributeSizeLimit(): int
    {
        return self::intConfig('search_attribute_size_bytes', self::DEFAULT_SEARCH_ATTRIBUTE_SIZE_BYTES);
    }

    public static function historyTransactionSizeLimit(): int
    {
        return self::intConfig('history_transaction_size', self::DEFAULT_HISTORY_TRANSACTION_SIZE);
    }

    public static function warningThresholdPercent(): int
    {
        return max(0, min(100, self::intConfig('warning_threshold_percent', self::DEFAULT_WARNING_THRESHOLD_PERCENT)));
    }

    // ── Enforcement helpers ─────────────────────────────────────────

    public static function guardPendingActivities(WorkflowRun $run): void
    {
        $limit = self::pendingActivityLimit();

        if ($limit <= 0) {
            return;
        }

        $count = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->count();

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingActivityCount($count, $limit);
        }
    }

    public static function guardPendingChildren(WorkflowRun $run): void
    {
        $limit = self::pendingChildLimit();

        if ($limit <= 0) {
            return;
        }

        $count = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->whereHas('childRun', static function ($query) {
                $query->whereNotIn('status', [
                    RunStatus::Completed->value,
                    RunStatus::Failed->value,
                    RunStatus::Cancelled->value,
                    RunStatus::Terminated->value,
                ]);
            })
            ->count();

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingChildCount($count, $limit);
        }
    }

    public static function guardPendingTimers(WorkflowRun $run): void
    {
        $limit = self::pendingTimerLimit();

        if ($limit <= 0) {
            return;
        }

        $count = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', TimerStatus::Pending->value)
            ->count();

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingTimerCount($count, $limit);
        }
    }

    public static function guardPendingSignals(WorkflowRun $run): void
    {
        $limit = self::pendingSignalLimit();

        if ($limit <= 0) {
            return;
        }

        $count = WorkflowSignal::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', SignalStatus::Received->value)
            ->count();

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingSignalCount($count, $limit);
        }
    }

    public static function guardPendingUpdates(WorkflowRun $run): void
    {
        $limit = self::pendingUpdateLimit();

        if ($limit <= 0) {
            return;
        }

        $count = WorkflowUpdate::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', UpdateStatus::Accepted->value)
            ->count();

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingUpdateCount($count, $limit);
        }
    }

    public static function guardPayloadSize(string $serialized): void
    {
        $limit = self::payloadSizeLimit();

        if ($limit <= 0) {
            return;
        }

        $bytes = strlen($serialized);

        if ($bytes > $limit) {
            throw StructuralLimitExceededException::payloadSize($bytes, $limit);
        }
    }

    public static function guardMemoSize(string $serialized): void
    {
        $limit = self::memoSizeLimit();

        if ($limit <= 0) {
            return;
        }

        $bytes = strlen($serialized);

        if ($bytes > $limit) {
            throw StructuralLimitExceededException::memoSize($bytes, $limit);
        }
    }

    public static function guardSearchAttributeSize(string $serialized): void
    {
        $limit = self::searchAttributeSizeLimit();

        if ($limit <= 0) {
            return;
        }

        $bytes = strlen($serialized);

        if ($bytes > $limit) {
            throw StructuralLimitExceededException::searchAttributeSize($bytes, $limit);
        }
    }

    public static function guardCommandBatchSize(int $batchSize): void
    {
        $limit = self::commandBatchSizeLimit();

        if ($limit <= 0) {
            return;
        }

        if ($batchSize > $limit) {
            throw StructuralLimitExceededException::commandBatchSize($batchSize, $limit);
        }
    }

    public static function guardHistoryTransactionSize(int $eventCount): void
    {
        $limit = self::historyTransactionSizeLimit();

        if ($limit <= 0) {
            return;
        }

        if ($eventCount > $limit) {
            throw StructuralLimitExceededException::historyTransactionSize($eventCount, $limit);
        }
    }

    // ── Soft-limit warnings ──────────────────────────────────────────

    /**
     * Check whether a count-based resource is approaching its hard limit.
     *
     * Returns null when the resource is safely below the warning threshold
     * or when the limit/threshold is disabled. Returns a structured array
     * when the current value >= threshold so callers can log or surface it.
     *
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function checkApproaching(StructuralLimitKind $kind, int $current): ?array
    {
        $thresholdPercent = self::warningThresholdPercent();

        if ($thresholdPercent <= 0) {
            return null;
        }

        $limit = self::limitForKind($kind);

        if ($limit <= 0) {
            return null;
        }

        $threshold = (int) floor($limit * $thresholdPercent / 100);

        if ($current < $threshold) {
            return null;
        }

        return [
            'limit_kind' => $kind->value,
            'current' => $current,
            'limit' => $limit,
            'threshold_percent' => $thresholdPercent,
            'utilization_percent' => (int) floor($current * 100 / $limit),
        ];
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingPendingActivities(WorkflowRun $run): ?array
    {
        $count = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->count();

        return self::checkApproaching(StructuralLimitKind::PendingActivityCount, $count);
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingPendingChildren(WorkflowRun $run): ?array
    {
        $count = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->whereHas('childRun', static function ($query) {
                $query->whereNotIn('status', [
                    RunStatus::Completed->value,
                    RunStatus::Failed->value,
                    RunStatus::Cancelled->value,
                    RunStatus::Terminated->value,
                ]);
            })
            ->count();

        return self::checkApproaching(StructuralLimitKind::PendingChildCount, $count);
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingPendingTimers(WorkflowRun $run): ?array
    {
        $count = WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', TimerStatus::Pending->value)
            ->count();

        return self::checkApproaching(StructuralLimitKind::PendingTimerCount, $count);
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingPendingSignals(WorkflowRun $run): ?array
    {
        $count = WorkflowSignal::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', SignalStatus::Received->value)
            ->count();

        return self::checkApproaching(StructuralLimitKind::PendingSignalCount, $count);
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingPendingUpdates(WorkflowRun $run): ?array
    {
        $count = WorkflowUpdate::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', UpdateStatus::Accepted->value)
            ->count();

        return self::checkApproaching(StructuralLimitKind::PendingUpdateCount, $count);
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingHistoryTransaction(int $eventCount): ?array
    {
        return self::checkApproaching(StructuralLimitKind::HistoryTransactionSize, $eventCount);
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingCommandBatch(int $batchSize): ?array
    {
        return self::checkApproaching(StructuralLimitKind::CommandBatchSize, $batchSize);
    }

    /**
     * Return the configured hard limit for a given kind, or 0 if disabled.
     */
    public static function limitForKind(StructuralLimitKind $kind): int
    {
        return match ($kind) {
            StructuralLimitKind::PendingActivityCount => self::pendingActivityLimit(),
            StructuralLimitKind::PendingChildCount => self::pendingChildLimit(),
            StructuralLimitKind::PendingTimerCount => self::pendingTimerLimit(),
            StructuralLimitKind::PendingSignalCount => self::pendingSignalLimit(),
            StructuralLimitKind::PendingUpdateCount => self::pendingUpdateLimit(),
            StructuralLimitKind::CommandBatchSize => self::commandBatchSizeLimit(),
            StructuralLimitKind::PayloadSize => self::payloadSizeLimit(),
            StructuralLimitKind::MemoSize => self::memoSizeLimit(),
            StructuralLimitKind::SearchAttributeSize => self::searchAttributeSizeLimit(),
            StructuralLimitKind::HistoryTransactionSize => self::historyTransactionSizeLimit(),
        };
    }

    /**
     * Return the full limit contract as a snapshot for health checks
     * and control-plane describe responses.
     *
     * @return array<string, int>
     */
    public static function snapshot(): array
    {
        return [
            'pending_activity_count' => self::pendingActivityLimit(),
            'pending_child_count' => self::pendingChildLimit(),
            'pending_timer_count' => self::pendingTimerLimit(),
            'pending_signal_count' => self::pendingSignalLimit(),
            'pending_update_count' => self::pendingUpdateLimit(),
            'command_batch_size' => self::commandBatchSizeLimit(),
            'payload_size_bytes' => self::payloadSizeLimit(),
            'memo_size_bytes' => self::memoSizeLimit(),
            'search_attribute_size_bytes' => self::searchAttributeSizeLimit(),
            'history_transaction_size' => self::historyTransactionSizeLimit(),
            'warning_threshold_percent' => self::warningThresholdPercent(),
        ];
    }

    // ── Internal ────��───────────────────────────────────────────────

    private static function intConfig(string $key, int $default): int
    {
        $value = config("workflows.v2.structural_limits.{$key}", $default);

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $default;
    }
}
