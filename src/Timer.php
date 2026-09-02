<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Workflow\Models\StoredWorkflow;

final class Timer extends Signal implements ShouldBeUnique
{
    public function __construct(
        public StoredWorkflow $storedWorkflow,
        public int $index,
        $connection = null,
        $queue = null
    ) {
        parent::__construct($storedWorkflow, $connection, $queue);
    }

    public function uniqueId()
    {
        return $this->storedWorkflow->id . ':' . $this->index;
    }
}
