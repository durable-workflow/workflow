<?php

declare(strict_types=1);

namespace Workflow;

use Attribute;
use LogicException;

#[Attribute(Attribute::TARGET_METHOD)]
final class UpdateMethod
{
    public function __construct(
        public readonly ?string $name = null,
    ) {
        if ($this->name === '') {
            throw new LogicException('Update names must be non-empty strings.');
        }
    }
}
