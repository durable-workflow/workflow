<?php

declare(strict_types=1);

namespace Workflow\V2\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Type
{
    public function __construct(
        public readonly string $key,
    ) {
    }
}
