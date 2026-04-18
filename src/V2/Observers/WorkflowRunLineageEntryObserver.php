<?php

declare(strict_types=1);

namespace Workflow\V2\Observers;

use Workflow\V2\Models\WorkflowRunLineageEntry;
use Workflow\V2\Support\ChildWorkflowNamespaceProjection;

class WorkflowRunLineageEntryObserver
{
    public function __construct(
        private readonly ChildWorkflowNamespaceProjection $projection,
    ) {}

    public function created(WorkflowRunLineageEntry $entry): void
    {
        $this->projection->projectLineageEntry($entry);
    }

    public function updated(WorkflowRunLineageEntry $entry): void
    {
        $this->projection->projectLineageEntry($entry);
    }
}
