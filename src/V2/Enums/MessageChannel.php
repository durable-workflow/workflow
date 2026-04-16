<?php

declare(strict_types=1);

namespace Workflow\V2\Enums;

enum MessageChannel: string
{
    case Signal = 'signal';
    case Update = 'update';
    case WorkflowMessage = 'workflow_message';
    case ChildSignal = 'child_signal';
    case External = 'external';
    case Query = 'query';
    case Custom = 'custom';
}
