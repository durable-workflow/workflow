<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Transition;

/**
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
enum SnapshotPhase: string
{
    case Before = 'before';
    case After = 'after';
    case Compensation = 'compensation';
}
