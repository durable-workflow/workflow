<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum TaskStatus: string
{
    case Ready = 'ready';
    case Leased = 'leased';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';
}
