<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

/**
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
enum Verdict: string
{
    case Satisfied = 'satisfied';
    case Unsatisfied = 'unsatisfied';
    case Unknown = 'unknown';
}
