<?php

declare(strict_types=1);

namespace Workflow\Serializers;

/** Explicit binary adapter for PHP strings intended for Avro `bytes`. */
final class AvroBinaryValue
{
    private function __construct(
        public readonly string $bytes
    ) {
    }

    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }
}
