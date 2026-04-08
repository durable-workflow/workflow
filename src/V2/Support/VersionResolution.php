<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class VersionResolution
{
    private function __construct(
        public readonly int $version,
        public readonly bool $shouldRecordMarker,
        public readonly bool $advancesSequence,
    ) {}

    public static function recorded(int $version): self
    {
        return new self($version, false, true);
    }

    public static function fresh(int $version): self
    {
        return new self($version, true, true);
    }

    public static function legacyDefault(int $version): self
    {
        return new self($version, false, false);
    }
}
