<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestHeartbeatCheckpointActivity extends Activity
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $name): array
    {
        $checkpoint = $this->lastHeartbeatCheckpoint();

        return [
            'execution_id' => $this->activityId(),
            'attempt_id' => $this->attemptId(),
            'version' => $this->heartbeatResumeVersion(),
            'details' => $checkpoint?->details(),
            'acknowledged_at' => $checkpoint?->acknowledgedAt,
            'name' => $name,
        ];
    }
}
