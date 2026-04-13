<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;
use Workflow\V2\Enums\StructuralLimitKind;

/**
 * Thrown when a workflow operation would exceed a structural limit.
 *
 * The exception carries machine-readable metadata so the failure
 * factory can classify it and projections can surface the limit kind
 * without parsing the message.
 */
final class StructuralLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly StructuralLimitKind $limitKind,
        public readonly int $currentValue,
        public readonly int $configuredLimit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function pendingActivityCount(int $current, int $limit): self
    {
        return new self(
            StructuralLimitKind::PendingActivityCount,
            $current,
            $limit,
            sprintf(
                'Structural limit exceeded: %d pending activities (limit %d).',
                $current,
                $limit,
            ),
        );
    }

    public static function pendingChildCount(int $current, int $limit): self
    {
        return new self(
            StructuralLimitKind::PendingChildCount,
            $current,
            $limit,
            sprintf(
                'Structural limit exceeded: %d pending child workflows (limit %d).',
                $current,
                $limit,
            ),
        );
    }

    public static function pendingTimerCount(int $current, int $limit): self
    {
        return new self(
            StructuralLimitKind::PendingTimerCount,
            $current,
            $limit,
            sprintf(
                'Structural limit exceeded: %d pending timers (limit %d).',
                $current,
                $limit,
            ),
        );
    }

    public static function pendingSignalCount(int $current, int $limit): self
    {
        return new self(
            StructuralLimitKind::PendingSignalCount,
            $current,
            $limit,
            sprintf(
                'Structural limit exceeded: %d pending signals (limit %d).',
                $current,
                $limit,
            ),
        );
    }

    public static function pendingUpdateCount(int $current, int $limit): self
    {
        return new self(
            StructuralLimitKind::PendingUpdateCount,
            $current,
            $limit,
            sprintf(
                'Structural limit exceeded: %d pending updates (limit %d).',
                $current,
                $limit,
            ),
        );
    }

    public static function commandBatchSize(int $current, int $limit): self
    {
        return new self(
            StructuralLimitKind::CommandBatchSize,
            $current,
            $limit,
            sprintf(
                'Structural limit exceeded: command batch contains %d items (limit %d).',
                $current,
                $limit,
            ),
        );
    }

    public static function payloadSize(int $bytes, int $limit): self
    {
        return new self(
            StructuralLimitKind::PayloadSize,
            $bytes,
            $limit,
            sprintf(
                'Structural limit exceeded: payload size %d bytes (limit %d bytes).',
                $bytes,
                $limit,
            ),
        );
    }

    public static function memoSize(int $bytes, int $limit): self
    {
        return new self(
            StructuralLimitKind::MemoSize,
            $bytes,
            $limit,
            sprintf(
                'Structural limit exceeded: memo size %d bytes (limit %d bytes).',
                $bytes,
                $limit,
            ),
        );
    }

    public static function searchAttributeSize(int $bytes, int $limit): self
    {
        return new self(
            StructuralLimitKind::SearchAttributeSize,
            $bytes,
            $limit,
            sprintf(
                'Structural limit exceeded: search attribute size %d bytes (limit %d bytes).',
                $bytes,
                $limit,
            ),
        );
    }

    public static function historyTransactionSize(int $eventCount, int $limit): self
    {
        return new self(
            StructuralLimitKind::HistoryTransactionSize,
            $eventCount,
            $limit,
            sprintf(
                'Structural limit exceeded: workflow task produced %d history events (limit %d).',
                $eventCount,
                $limit,
            ),
        );
    }
}
