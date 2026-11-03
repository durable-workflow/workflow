<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

/**
 * PHP-only value object used to verify that V2 Avro activity arguments reject
 * unregistered objects before an activity is scheduled. V2 payloads never fall
 * back to PHP object serialization.
 */
final class TestActivityArgumentObject
{
    public function __construct(
        public readonly string $tag,
        public readonly int $count,
    ) {
    }
}
