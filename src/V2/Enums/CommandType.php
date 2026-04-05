<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum CommandType: string
{
    case Start = 'start';
    case Signal = 'signal';
    case Update = 'update';
    case Repair = 'repair';
    case Cancel = 'cancel';
    case Terminate = 'terminate';
}
