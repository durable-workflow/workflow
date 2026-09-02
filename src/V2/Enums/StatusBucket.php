<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum StatusBucket: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
