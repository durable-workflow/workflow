<?php

declare(strict_types=1);

namespace Workflow\Serializers;

/**
 * JSON-safe display projection for decoded typed Avro values.
 *
 * Payload envelopes remain the lossless authority. This projection keeps
 * ordinary JSON-compatible values readable while giving bytes and PHP maps
 * that cannot be represented faithfully as native arrays explicit shapes.
 */
final class AvroValueJsonProjection
{
    public static function project(mixed $value): mixed
    {
        if ($value instanceof AvroBinaryValue) {
            return [
                '$type' => 'bytes',
                'base64' => base64_encode($value->bytes),
            ];
        }

        if ($value instanceof AvroMapValue) {
            return [
                '$type' => 'map',
                'entries' => array_map(
                    static fn (array $pair): array => [
                        'key' => $pair[0],
                        'value' => self::project($pair[1]),
                    ],
                    $value->pairs,
                ),
            ];
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::project($item);
            }
        }

        return $value;
    }
}
