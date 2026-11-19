<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

enum Status: string
{
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Unknown = 'unknown';
    case NotExecuted = 'not-executed';
    case Refused = 'refused';
}
