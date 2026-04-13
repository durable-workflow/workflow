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

    // ── Enforcement helpers ─────────────────────────────────────────

    /**
     * @throws StructuralLimitExceededException
     */
    public static function guardPendingActivities(WorkflowRun $run): void
    {
        $limit = self::pendingActivityLimit();

        if ($limit <= 0) {
            return;
        }

        $count = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('status', [
                ActivityStatus::Pending->value,
                ActivityStatus::Running->value,
            ])
            ->count();

        if ($count >= $limit) {
            throw StructuralLimitExceededException::pendingActivityCount($count, $limit);
        }
    }

    /**
     * @throws StructuralLimitExceededException
     */
    public static function guardPendingChildren(WorkflowRun $run): void
    {
        $limit = self::pendingChildLimit();

        if ($limit <= 0) {
            return;
        }

        $count = WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'child_workflow')
            ->whereHas('childRun', function ($query) {
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

    /**
     * @throws StructuralLimitExceededException
     */
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

    /**
     * @throws StructuralLimitExceededException
     */
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

    /**
     * @throws StructuralLimitExceededException
     */
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

    /**
     * @throws StructuralLimitExceededException
     */
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

    /**
     * @throws StructuralLimitExceededException
     */
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
