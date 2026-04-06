<?php

declare(strict_types=1);

namespace Workflow\V2\Attributes;

use Attribute;
use LogicException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Signal
{
    public readonly string $name;

    public function __construct(string $name)
    {
        $name = trim($name);

        if ($name === '') {
            throw new LogicException('V2 signal names must be non-empty strings.');
        }

        $this->name = $name;
    }
}
