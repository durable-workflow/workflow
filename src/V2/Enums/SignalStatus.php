<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum SignalStatus: string
{
    case Received = 'received';
    case Applied = 'applied';
    case Rejected = 'rejected';
}
