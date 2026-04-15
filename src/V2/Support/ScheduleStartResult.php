<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ScheduleStartResult
{
    public function __construct(
        public readonly string $instanceId,
        public readonly ?string $runId,
    ) {
    }
}
