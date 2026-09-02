<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ActivityAttemptStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
