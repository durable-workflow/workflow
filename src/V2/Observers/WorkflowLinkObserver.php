<?php

declare(strict_types=1);

namespace Workflow\V2\Observers;

use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Support\ChildWorkflowNamespaceProjection;

class WorkflowLinkObserver
{
    public function __construct(
        private readonly ChildWorkflowNamespaceProjection $projection,
    ) {
    }

    public function created(WorkflowLink $link): void
    {
        $this->projection->projectLink($link);
    }
}
