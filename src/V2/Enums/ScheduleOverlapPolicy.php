<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum ScheduleOverlapPolicy: string
{
    case Skip = 'skip';
    case BufferOne = 'buffer_one';
    case BufferAll = 'buffer_all';
    case AllowAll = 'allow_all';
    case CancelOther = 'cancel_other';
    case TerminateOther = 'terminate_other';

    public function isBuffer(): bool
    {
        return $this === self::BufferOne || $this === self::BufferAll;
    }
}
