<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum CommandType: string
{
    case Start = 'start';
    case Redrive = 'redrive';
    case Signal = 'signal';
    case Update = 'update';
    case Repair = 'repair';
    case Cancel = 'cancel';
    case RequestCancellation = 'request_cancellation';
    case Terminate = 'terminate';
    case Archive = 'archive';
}
