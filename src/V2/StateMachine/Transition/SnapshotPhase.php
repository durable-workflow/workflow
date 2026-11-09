<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

enum SnapshotPhase: string
{
    case Before = 'before';
    case After = 'after';
    case Compensation = 'compensation';
}
