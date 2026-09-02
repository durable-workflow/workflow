<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Log;
use Workflow\Activity;

class TestStressLogActivity extends Activity
{
    public function execute(int $runId, int $childIndex, int $activityIndex): bool
    {
        Log::info(__METHOD__ . ':' . __LINE__, [
            'run_id' => $runId,
            'child_index' => $childIndex,
            'activity_index' => $activityIndex,
            'workflow_id' => $this->workflowId(),
            'index' => $this->index,
            'worker_pid' => getmypid(),
        ]);

        return true;
    }
}
