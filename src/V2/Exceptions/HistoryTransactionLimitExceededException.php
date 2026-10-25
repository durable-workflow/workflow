<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

/**
 * Thrown by the history transaction meter when a workflow task completion
 * would write past one of its hard limits, or when its measure or external
 * payload staging cannot be produced (#930). The completing transaction
 * rolls back, so nothing it wrote survives and the task keeps its lease.
 */
final class HistoryTransactionLimitExceededException extends RuntimeException
{
    public const MEASURE_UNAVAILABLE = 'history_transaction_measure_unavailable';

    public const STAGING_UNSUPPORTED = 'external_payload_staging_unsupported';

    public const STAGED_URI_MISMATCH = 'external_payload_staged_uri_mismatch';

    public const TIMESTAMP_OUT_OF_RANGE = 'local_activity_timestamp_out_of_range';

    public function __construct(
        public readonly string $dimension,
        public readonly int $used,
        public readonly int $limit,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function over(string $dimension, int $used, int $limit): self
    {
        return new self(
            $dimension,
            $used,
            $limit,
            sprintf('This completion would exceed %s: %d (limit %d).', $dimension, $used, $limit),
        );
    }

    public static function refused(string $reason, string $message): self
    {
        return new self($reason, 0, 0, $message);
    }
}
