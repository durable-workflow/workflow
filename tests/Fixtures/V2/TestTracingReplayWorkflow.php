<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Workflow;

final class TestTracingReplayWorkflow extends Workflow
{
    public static array $contexts = [];

    public function handle(): array
    {
        self::$contexts[] = ExecutionTracing::current();
        $result = self::activity(TestTracingReplayActivity::class);
        self::$contexts[] = ExecutionTracing::current();

        return $result;
    }
}
