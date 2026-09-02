<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;

final class SearchAttributeReplayIdentity
{
    /**
     * Compare a recorded search-attribute update with the command yielded after restart.
     *
     * Events recorded before attribute_types existed retain value-only replay
     * compatibility. Their missing metadata represents unknown type identity,
     * not proof that the current declaration matches a historical declaration.
     *
     * @param array<string, mixed> $recordedPayload
     */
    public static function assertCompatible(
        int $sequence,
        array $recordedPayload,
        UpsertSearchAttributesCall $current,
    ): void {
        $recordedAttributes = $recordedPayload['attributes'] ?? null;

        if (! is_array($recordedAttributes) || ! self::mapsMatch($recordedAttributes, $current->attributes)) {
            throw new HistoryEventShapeMismatchException(
                $sequence,
                'search attribute upsert with matching values',
                ['SearchAttributesUpserted'],
                'Recorded search attribute values do not match the current yielded values.',
            );
        }

        if (! array_key_exists('attribute_types', $recordedPayload)) {
            return;
        }

        $recordedTypes = $recordedPayload['attribute_types'];
        $currentTypes = SearchAttributeUpsertService::canonicalTypes($current);

        if (! is_array($recordedTypes) || ! self::mapsMatch($recordedTypes, $currentTypes)) {
            throw new HistoryEventShapeMismatchException(
                $sequence,
                'search attribute upsert with matching declared types',
                ['SearchAttributesUpserted'],
                'Recorded search attribute types do not match the current yielded types.',
            );
        }
    }

    /**
     * @param array<mixed> $recorded
     * @param array<mixed> $current
     */
    private static function mapsMatch(array $recorded, array $current): bool
    {
        ksort($recorded);
        ksort($current);

        return $recorded === $current;
    }
}
