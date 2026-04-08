<?php

declare(strict_types=1);

namespace Workflow;

use Attribute;
use LogicException;

#[Attribute(Attribute::TARGET_METHOD)]
final class QueryMethod
{
    public function __construct(
        public readonly ?string $name = null,
    ) {
        if ($this->name === '') {
            throw new LogicException('Query names must be non-empty strings.');
        }
    }
}
