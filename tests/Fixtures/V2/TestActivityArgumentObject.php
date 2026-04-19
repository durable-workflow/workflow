<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

/**
 * PHP-only value object used to verify that activity argument serialization
 * round-trips typed objects even when the run's declared codec is Avro.
 * See #429 (TD-066).
 */
final class TestActivityArgumentObject
{
    public function __construct(
        public readonly string $tag,
        public readonly int $count,
    ) {
    }
}
