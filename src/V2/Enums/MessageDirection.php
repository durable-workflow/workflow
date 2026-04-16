<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
}
