<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;

final class AwaitCall
{
    public function __construct(
        public readonly Closure $condition,
        public readonly ?string $conditionKey = null,
    ) {
    }
}
