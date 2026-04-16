<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ChildCallStatus: string
{
    case Scheduled = 'scheduled';
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::Terminated, self::Abandoned => true,
            self::Scheduled, self::Started => false,
        };
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }
}
