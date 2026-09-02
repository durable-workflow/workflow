<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Terminated, self::Completed, self::Failed => true,
            default => false,
        };
    }

    public function statusBucket(): StatusBucket
    {
        return match ($this) {
            self::Completed => StatusBucket::Completed,
            self::Cancelled, self::Terminated, self::Failed => StatusBucket::Failed,
            default => StatusBucket::Running,
        };
    }
}
