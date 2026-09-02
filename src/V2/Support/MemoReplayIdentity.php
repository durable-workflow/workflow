<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\Serializers\CodecDecodeException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;

final class MemoReplayIdentity
{
    /**
     * @param array<string, mixed> $currentEntries
     */
    public static function assertCompatible(int $sequence, mixed $recordedEntries, array $currentEntries): void
    {
        if (is_array($recordedEntries) && self::matches($recordedEntries, $currentEntries)) {
            return;
        }

        throw new HistoryEventShapeMismatchException(
            $sequence,
            'memo upsert with matching entries',
            ['MemoUpserted'],
            'Recorded memo entries do not match the current yielded entries.',
        );
    }

    /**
     * Compare persisted portable Avro envelopes independently of source-language map order.
     *
     * @param array<mixed> $recordedEntries
     * @param array<string, mixed> $currentEntries
     */
    private static function matches(array $recordedEntries, array $currentEntries): bool
    {
        try {
            $recorded = MemoPayload::canonicalMapEnvelope($recordedEntries);
            $current = MemoPayload::mapEnvelope($currentEntries);

            return hash_equals($recorded['blob'], $current['blob']);
        } catch (CodecDecodeException|InvalidArgumentException) {
            return false;
        }
    }
}
