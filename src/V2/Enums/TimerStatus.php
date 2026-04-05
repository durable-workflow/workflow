<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum TimerStatus: string
{
    case Pending = 'pending';
    case Cancelled = 'cancelled';
    case Fired = 'fired';
}
