<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum TaskType: string
{
    case Workflow = 'workflow';
    case Activity = 'activity';
    case Timer = 'timer';
}
