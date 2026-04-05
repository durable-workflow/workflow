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
}
