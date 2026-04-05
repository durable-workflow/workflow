<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ActivityStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';
}
