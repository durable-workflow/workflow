<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use Workflow\V2\Contracts\YieldedCommand;

final class AwaitWithTimeoutCall implements YieldedCommand
{
    public function __construct(
        public readonly int $seconds,
        public readonly Closure $condition,
        public readonly ?string $conditionKey = null,
        public readonly ?string $conditionDefinitionFingerprint = null,
    ) {
    }
}
