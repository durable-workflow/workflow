<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use InvalidArgumentException;

/**
 * Explicit adapter for Avro maps that PHP arrays cannot represent faithfully.
 *
 * @phpstan-type Entry array{0: string, 1: mixed}
 */
final class AvroMapValue
{
    /**
     * @param list<Entry> $pairs
     */
    private function __construct(
        public readonly array $pairs
    ) {
    }

    /**
     * @param iterable<array{0: mixed, 1: mixed}> $pairs
     */
    public static function fromPairs(iterable $pairs): self
    {
        $validated = [];
        $keys = [];
        foreach ($pairs as $pair) {
            $key = $pair[0];
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'invalid_map_key: Avro Value maps require string keys; keys are never stringified.',
                );
            }
            if (in_array($key, $keys, true)) {
                throw new InvalidArgumentException(
                    'duplicate_map_key: Avro Value maps require unique string keys.',
                );
            }

            $keys[] = $key;
            $validated[] = [$key, $pair[1]];
        }

        return new self($validated);
    }
}
