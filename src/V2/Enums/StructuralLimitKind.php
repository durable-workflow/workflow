<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

/**
 * Machine-readable kinds for structural-limit enforcement.
 *
 * Each case represents a specific resource ceiling. When a workflow
 * operation would exceed the configured threshold the engine records
 * a typed failure with one of these kinds so operators, Waterline,
 * and external tooling can identify the root cause without parsing
 * free-text messages.
 */
enum StructuralLimitKind: string
{
    /** Serialized payload exceeds the per-argument size ceiling. */
    case PayloadSize = 'payload_size';

    /** A single workflow task would produce more history events than allowed. */
    case HistoryTransactionSize = 'history_transaction_size';

    /** Indexed search-attribute metadata exceeds the size ceiling. */
    case SearchAttributeSize = 'search_attribute_size';

    /** Non-indexed memo metadata exceeds the size ceiling. */
    case MemoSize = 'memo_size';

    /** Too many non-terminal activity executions are open simultaneously. */
    case PendingActivityCount = 'pending_activity_count';

    /** Too many non-terminal child workflows are open simultaneously. */
    case PendingChildCount = 'pending_child_count';

    /** Too many non-terminal timers are open simultaneously. */
    case PendingTimerCount = 'pending_timer_count';

    /** Too many unprocessed signals are pending simultaneously. */
    case PendingSignalCount = 'pending_signal_count';

    /** Too many unresolved updates are pending simultaneously. */
    case PendingUpdateCount = 'pending_update_count';

    /** A single command batch (parallel fan-out) exceeds the max size. */
    case CommandBatchSize = 'command_batch_size';
}
