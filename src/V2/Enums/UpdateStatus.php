<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum UpdateStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Failed = 'failed';
}
