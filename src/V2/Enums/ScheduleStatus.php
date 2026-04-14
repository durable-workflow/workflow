<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Deleted = 'deleted';

    public function isTerminal(): bool
    {
        return $this === self::Deleted;
    }

    public function allowsTrigger(): bool
    {
        return $this === self::Active;
    }
}
