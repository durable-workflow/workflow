<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

enum Verdict: string
{
    case Satisfied = 'satisfied';
    case Unsatisfied = 'unsatisfied';
    case Unknown = 'unknown';
}
