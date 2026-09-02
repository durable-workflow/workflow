<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\YieldedCommand;

final readonly class CancelDurableOperationCall implements YieldedCommand
{
    public function __construct(
        public DurableOperationHandle $handle
    ) {
    }
}
