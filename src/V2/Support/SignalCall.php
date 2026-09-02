<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;

final class SignalCall implements YieldedCommand
{
    public function __construct(
        public readonly string $name,
        public readonly ?int $timeoutSeconds = null,
    ) {
    }
}
