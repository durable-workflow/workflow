<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Workflow;

final class TestParallelActivityBatchWorkflow extends Workflow
{
    public function handle(int $count): array
    {
        return all(array_map(
            static fn (int $index) => static fn () => activity(TestGreetingActivity::class, (string) $index),
            range(0, $count - 1),
        ));
    }
}
