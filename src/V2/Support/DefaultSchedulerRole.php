<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\SchedulerRole;

final class DefaultSchedulerRole implements SchedulerRole
{
    public function tick(int $limit = 100): array
    {
        return ScheduleManager::tick($limit);
    }
}
