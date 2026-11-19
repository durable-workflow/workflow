<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\StructuralLimitKind;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
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

    public const DEFAULT_MEMO_SIZE_BYTES = 65536;            // 64 KiB, the fixed memo total

    public const DEFAULT_SEARCH_ATTRIBUTE_SIZE_BYTES = 40960; // 40 KiB

    public const DEFAULT_HISTORY_TRANSACTION_SIZE = 5000;

    public const DEFAULT_HISTORY_TRANSACTION_BYTES = 16777216; // 16 MiB

    public const DEFAULT_HISTORY_TRANSACTION_ROWS = 20000;

    /**
     * Rows a completion may write beyond MAX_ROWS_PER_HISTORY_EVENT per
     * event: its fixed task, run and summary rows, and the pending inbound
     * messages continue-as-new hands to the next run (one row each).
     */
    public const COMPLETION_ROWS_HEADROOM = self::DEFAULT_PENDING_SIGNAL_COUNT;

    /**
     * The completion row limit when none is configured, at the default event
     * limit: 103 rows per event x 5,000 events + 5,000 = 520,000. It never
     * binds before the event limit for any first-party command mix (see
     * {@see self::completionRowsLimit()}).
     */
    public const DEFAULT_COMPLETION_ROWS = CompletionRowEstimate::MAX_ROWS_PER_HISTORY_EVENT
        * self::DEFAULT_HISTORY_TRANSACTION_SIZE
        + self::COMPLETION_ROWS_HEADROOM;

    public const DEFAULT_EXTERNAL_PAYLOAD_TRANSACTION_BYTES = 16777216; // 16 MiB

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

    /**
     * The configured memo limit can tighten the fixed memo total but not
     * raise it. Zero turns the configured limit off; the fixed caps stay.
     */
    public static function memoSizeLimit(): int
    {
        return self::cappedLimit(
            self::intConfig('memo_size_bytes', self::DEFAULT_MEMO_SIZE_BYTES),
            WorkflowMemo::MAX_TOTAL_SIZE_BYTES,
        );
    }

    /**
     * The configured search attribute limit can tighten the fixed total but
     * not raise it. Zero turns the configured limit off; the fixed caps stay.
     */
    public static function searchAttributeSizeLimit(): int
    {
        return self::cappedLimit(
            self::intConfig('search_attribute_size_bytes', self::DEFAULT_SEARCH_ATTRIBUTE_SIZE_BYTES),
            WorkflowSearchAttribute::MAX_TOTAL_SIZE_BYTES,
        );
    }

    /**
     * Checks the memo a write would leave on the run, before anything is
     * written. Start, embedded upserts and worker upserts all call this,
     * so the same memo is refused the same way on every path.
     *
     * @param array<string, mixed> $merged
     */
    public static function guardMemoMap(array $merged): void
    {
        ksort($merged);
        $total = 0;

        foreach ($merged as $key => $value) {
            $bytes = MemoPayload::encodedEnvelopeSize(MemoPayload::envelope($value));

            if ($bytes > WorkflowMemo::MAX_VALUE_SIZE_BYTES) {
                throw StructuralLimitExceededException::memoLimit(
                    'memo_value_bytes',
                    $bytes,
                    WorkflowMemo::MAX_VALUE_SIZE_BYTES,
                    sprintf('memo value bytes for key "%s"', $key),
                );
            }

            $total += $bytes;
        }

        if (count($merged) > WorkflowMemo::MAX_MEMOS_PER_RUN) {
            throw StructuralLimitExceededException::memoLimit(
                'memo_keys',
                count($merged),
                WorkflowMemo::MAX_MEMOS_PER_RUN,
                'memo keys',
            );
        }

        if ($total > WorkflowMemo::MAX_TOTAL_SIZE_BYTES) {
            throw StructuralLimitExceededException::memoLimit(
                'memo_total_bytes',
                $total,
                WorkflowMemo::MAX_TOTAL_SIZE_BYTES,
                'total memo bytes',
            );
        }

        self::guardMemoSize(MemoPayload::encodedMapBytes($merged));
    }

    /**
     * Checks a start's memo and search attributes with the limits upserts use.
     *
     * @param array<string, mixed>|null $memo
     * @param array<string, mixed>|null $searchAttributes
     */
    public static function guardStartMetadata(?array $memo, ?array $searchAttributes): void
    {
        if ($memo !== null && $memo !== []) {
            self::guardMemoMap($memo);
        }

        if ($searchAttributes !== null && $searchAttributes !== []) {
            self::guardSearchAttributeMap($searchAttributes);
        }
    }

    /**
     * Checks the search attributes a write would leave on the run, before
     * anything is written. Per-value caps are checked when each value is
     * typed and stored.
     *
     * @param array<string, mixed> $merged
     */
    public static function guardSearchAttributeMap(array $merged): void
    {
        ksort($merged);

        if (count($merged) > WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN) {
            throw StructuralLimitExceededException::searchAttributeLimit(
                'search_attribute_count',
                count($merged),
                WorkflowSearchAttribute::MAX_ATTRIBUTES_PER_RUN,
                'search attributes',
            );
        }

        self::guardSearchAttributeSize(json_encode($merged, JSON_THROW_ON_ERROR));
    }

    public static function historyTransactionSizeLimit(): int
    {
        return self::intConfig('history_transaction_size', self::DEFAULT_HISTORY_TRANSACTION_SIZE);
    }

    /**
     * History bytes one workflow task completion may write, measured as
     * {@see HistoryBudget} measures an event. 0 disables the check.
     */
    public static function historyTransactionBytesLimit(): int
    {
        return self::intConfig('history_transaction_bytes', self::DEFAULT_HISTORY_TRANSACTION_BYTES);
    }

    /**
     * History rows one workflow task completion may write, with its 2.29
     * meaning: two per history event (the event and the run position it
     * advances), plus each local activity execution, attempt and failure row.
     * A configuration published from 2.29 keeps working unchanged. 0
     * disables the check. Every other row is bounded by
     * {@see self::completionRowsLimit()}.
     */
    public static function historyTransactionRowsLimit(): int
    {
        return self::intConfig('history_transaction_rows', self::DEFAULT_HISTORY_TRANSACTION_ROWS);
    }

    /**
     * Rows one workflow task completion may insert, update or delete, each
     * charged before its statement runs (#1116). 0 disables the check.
     *
     * A configured value is honored as is. Without one, the limit follows the
     * event limit so it never refuses a completion the event limit admits:
     * at most {@see CompletionRowEstimate::MAX_ROWS_PER_HISTORY_EVENT} rows
     * per event, plus {@see self::COMPLETION_ROWS_HEADROOM}. With the event
     * limit disabled, so is the derived row limit.
     */
    public static function completionRowsLimit(): int
    {
        $configured = config('workflows.v2.structural_limits.completion_rows');
        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return self::derivedCompletionRowsLimit(self::historyTransactionSizeLimit());
    }

    public static function derivedCompletionRowsLimit(int $eventLimit): int
    {
        if ($eventLimit <= 0) {
            return 0;
        }

        return HistoryTransactionMeter::checkedAdd(
            HistoryTransactionMeter::checkedMultiply($eventLimit, CompletionRowEstimate::MAX_ROWS_PER_HISTORY_EVENT),
            self::COMPLETION_ROWS_HEADROOM,
        );
    }

    /**
     * Raw payload bytes one workflow task completion may externalize, each
     * occurrence counted even when objects deduplicate. 0 disables the
     * check; the per-payload size limit still applies.
     */
    public static function externalPayloadTransactionBytesLimit(): int
    {
        return self::intConfig(
            'external_payload_transaction_bytes',
            self::DEFAULT_EXTERNAL_PAYLOAD_TRANSACTION_BYTES,
        );
    }

    public static function warningThresholdPercent(): int
    {
        return max(0, min(100, self::intConfig('warning_threshold_percent', self::DEFAULT_WARNING_THRESHOLD_PERCENT)));
    }

    // ── Enforcement helpers ─────────────────────────────────────────

    public static function guardPendingActivities(WorkflowRun $run): void
    {
        if (self::pendingActivityLimit() <= 0) {
            return;
        }

        self::guardPendingActivityCount(self::pendingActivityCount($run));
    }

    /**
     * Refuses one more activity when $count are already pending.
     */
    public static function guardPendingActivityCount(int $count): void
    {
        $limit = self::pendingActivityLimit();

        if ($limit > 0 && $count >= $limit) {
            throw StructuralLimitExceededException::pendingActivityCount($count, $limit);
        }
    }

    public static function pendingActivityCount(WorkflowRun $run): int
    {
        return ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
            ->count();
    }

    public static function guardPendingChildren(WorkflowRun $run): void
    {
        if (self::pendingChildLimit() <= 0) {
            return;
        }

        self::guardPendingChildCount(self::pendingChildCount($run));
    }

    /**
     * Refuses one more child when $count are already open.
     */
    public static function guardPendingChildCount(int $count): void
    {
        $limit = self::pendingChildLimit();

        if ($limit > 0 && $count >= $limit) {
            throw StructuralLimitExceededException::pendingChildCount($count, $limit);
        }
    }

    public static function pendingChildCount(WorkflowRun $run): int
    {
        return WorkflowLink::query()
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
    }

    public static function guardPendingTimers(WorkflowRun $run): void
    {
        $limit = self::pendingTimerLimit();

        if ($limit <= 0) {
            return;
        }

        $count = self::pendingTimerCount($run);

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingTimerCount($count, $limit);
        }
    }

    public static function pendingTimerCount(WorkflowRun $run): int
    {
        return WorkflowTimer::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', TimerStatus::Pending->value)
            ->count();
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
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingPayloadSize(string $serialized): ?array
    {
        return self::checkApproaching(StructuralLimitKind::PayloadSize, strlen($serialized));
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingMemoSize(string $serialized): ?array
    {
        return self::checkApproaching(StructuralLimitKind::MemoSize, strlen($serialized));
    }

    /**
     * @return array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null
     */
    public static function warnApproachingSearchAttributeSize(string $serialized): ?array
    {
        return self::checkApproaching(StructuralLimitKind::SearchAttributeSize, strlen($serialized));
    }

    /**
     * Emit a structured Laravel log warning when a soft-limit check
     * returns a snapshot. Call sites pass the result of
     * checkApproaching / warnApproaching* directly; null is a no-op so
     * callers don't need to branch.
     *
     * @param array{limit_kind: string, current: int, limit: int, threshold_percent: int, utilization_percent: int}|null $warning
     * @param array<string, mixed> $context
     */
    public static function logWarning(?array $warning, array $context = []): void
    {
        if ($warning === null) {
            return;
        }

        Log::warning(sprintf(
            '[Durable Workflow] Approaching structural limit [%s]: %d / %d (%d%% utilization, warning at %d%%).',
            $warning['limit_kind'],
            $warning['current'],
            $warning['limit'],
            $warning['utilization_percent'],
            $warning['threshold_percent'],
        ), array_merge([
            'limit_kind' => $warning['limit_kind'],
            'current' => $warning['current'],
            'limit' => $warning['limit'],
            'utilization_percent' => $warning['utilization_percent'],
            'threshold_percent' => $warning['threshold_percent'],
        ], $context));
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
            StructuralLimitKind::HistoryTransactionBytes => self::historyTransactionBytesLimit(),
            StructuralLimitKind::HistoryTransactionRows => self::historyTransactionRowsLimit(),
            StructuralLimitKind::CompletionRows => self::completionRowsLimit(),
            StructuralLimitKind::ExternalPayloadTransactionBytes => self::externalPayloadTransactionBytesLimit(),
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
            'history_transaction_bytes' => self::historyTransactionBytesLimit(),
            'history_transaction_rows' => self::historyTransactionRowsLimit(),
            'completion_rows' => self::completionRowsLimit(),
            'external_payload_transaction_bytes' => self::externalPayloadTransactionBytesLimit(),
            'warning_threshold_percent' => self::warningThresholdPercent(),
        ];
    }

    // ── Internal ────��───────────────────────────────────────────────

    private static function cappedLimit(int $configured, int $fixedCap): int
    {
        return $configured > 0 ? min($configured, $fixedCap) : 0;
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config("workflows.v2.structural_limits.{$key}", $default);

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $default;
    }
}
