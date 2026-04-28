<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\TaskWatchdog;

final class DefaultMatchingRole implements MatchingRole
{
    public function wake(?string $connection = null, ?string $queue = null): void
    {
        TaskWatchdog::wake($connection, $queue);
    }

    public function runPass(
        ?string $connection = null,
        ?string $queue = null,
        bool $respectThrottle = false,
        array $runIds = [],
        ?string $instanceId = null,
    ): array {
        return TaskWatchdog::runPass($connection, $queue, $respectThrottle, $runIds, $instanceId);
    }
}
