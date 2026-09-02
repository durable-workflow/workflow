<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Generator;
use Illuminate\Support\Facades\Log;
use function Workflow\all;
use function Workflow\child;
use Workflow\Workflow;

class TestStressParentWorkflow extends Workflow
{
    public function execute(int $runId, int $children = 100, int $activitiesPerChild = 10): Generator
    {
        $promises = [];

        for ($childIndex = 0; $childIndex < $children; $childIndex++) {
            $promises[] = child(TestStressChildWorkflow::class, $runId, $childIndex, $activitiesPerChild);
        }

        Log::info(__METHOD__ . ':' . __LINE__, [
            'run_id' => $runId,
            'workflow_id' => $this->storedWorkflow->id,
            'worker_pid' => getmypid(),
        ]);

        yield all($promises);

        Log::info(__METHOD__ . ':' . __LINE__, [
            'run_id' => $runId,
            'workflow_id' => $this->storedWorkflow->id,
            'worker_pid' => getmypid(),
        ]);

        return [
            'run_id' => $runId,
            'children' => $children,
            'activities_per_child' => $activitiesPerChild,
        ];
    }
}
