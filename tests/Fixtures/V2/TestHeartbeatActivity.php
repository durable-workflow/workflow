<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestHeartbeatActivity extends Activity
{
    public function execute(): array
    {
        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(2));

        try {
            $this->heartbeat();
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }

        return [
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'activity_id' => $this->activityId(),
            'attempt_id' => $this->attemptId(),
            'attempt_count' => $this->attemptCount(),
        ];
    }
}
