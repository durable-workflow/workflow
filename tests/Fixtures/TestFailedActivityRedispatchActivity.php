<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use RuntimeException;
use Workflow\Activity;

final class TestFailedActivityRedispatchActivity extends Activity
{
    // The first exception fails the job, so its outcome is recorded by the
    // separately queued Workflow\Exception job.
    public $maxExceptions = 1;

    public function execute(string $directory): string
    {
        file_put_contents($directory . '/executions', "1\n", FILE_APPEND);

        throw new RuntimeException('activity failed');
    }
}
