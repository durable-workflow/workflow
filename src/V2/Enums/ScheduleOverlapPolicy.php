<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ScheduleOverlapPolicy: string
{
    case Skip = 'skip';
    case BufferOne = 'buffer_one';
    case AllowAll = 'allow_all';
    case CancelOther = 'cancel_other';
    case TerminateOther = 'terminate_other';
}
