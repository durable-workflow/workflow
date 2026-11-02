<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestContinuationProcessWorkflow extends Workflow
{
    public function handle(string $directory): array
    {
        file_put_contents($directory . '/entries', getmypid() . "\n", FILE_APPEND | LOCK_EX);
        $value = Workflow::activity(TestContinuationProcessActivity::class, $directory);
        Workflow::timer(1);

        return $value;
    }
}
