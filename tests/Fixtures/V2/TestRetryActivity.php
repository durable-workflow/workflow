<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Workflow\V2\Activity;

final class TestRetryActivity extends Activity
{
    public int $tries = 2;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $name): array
    {
        $count = Cache::increment('workflow:v2:test-retry-activity:' . $this->activityId());

        if ($count === 1) {
            throw new RuntimeException('retry me');
        }

        return [
            'message' => "Hello, {$name}!",
            'activity_id' => $this->activityId(),
            'attempt_id' => $this->attemptId(),
            'attempt_count' => $this->attemptCount(),
        ];
    }
}
