<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/** @internal A handler advanced the authoring frame and dispatch should continue. */
enum WorkflowTaskStep
{
    case ContinueExecution;
}
