<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Workflow\V2\Activity;

final class TestRedriveRetryProbeActivity extends Activity
{
    public function backoff(): int|array
    {
        return 0;
    }

    public function handle(string $site, string $value, string $counter): string
    {
        $calls = Cache::increment($counter . ':' . $site);
        $this->heartbeat([
            'message' => $site,
        ]);
        if ($calls <= (int) config('workflows.v2.testing.redrive_retry_failures.' . $site, 0)) {
            throw new RuntimeException('Retryable fixture failure at ' . $site . '.');
        }
        return $value . ':' . $site;
    }
}
