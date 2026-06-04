<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Contracts\Foundation\Application;
use Workflow\V2\Activity;

final class TestDependencyInjectionActivity extends Activity
{
    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function handle(Application $app, string $kind, string $name, array $metadata): array
    {
        return [
            'kind' => $kind,
            'name' => $name,
            'metadata' => $metadata,
            'running_in_console' => $app->runningInConsole(),
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'activity_id' => $this->activityId(),
        ];
    }
}
