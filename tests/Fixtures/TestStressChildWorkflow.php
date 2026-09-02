<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Generator;
use Illuminate\Support\Facades\Log;
use function Workflow\activity;
use Workflow\Workflow;

class TestStressChildWorkflow extends Workflow
{
    public function execute(int $runId, int $childIndex, int $activitiesPerChild = 10): Generator
    {
        for ($activityIndex = 0; $activityIndex < $activitiesPerChild; $activityIndex++) {
            Log::info(__METHOD__ . ':' . __LINE__, [
                'run_id' => $runId,
                'workflow_id' => $this->storedWorkflow->id,
                'child_index' => $childIndex,
                'activity_index' => $activityIndex,
                'worker_pid' => getmypid(),
            ]);

            yield activity(TestStressLogActivity::class, $runId, $childIndex, $activityIndex);
        }

        return true;
    }
}
