<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum CommandStatus: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
