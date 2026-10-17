<?php

declare(strict_types=1);

namespace TracingBenchmark;

use Workflow\V2\Workflow;

final class BenchmarkWorkflow extends Workflow
{
    public function handle(int $members): array
    {
        if ($members === 1) {
            return [self::activity(BenchmarkActivity::class, 0)];
        }

        $calls = [];
        foreach (range(0, $members - 1) as $member) {
            $calls[] = static fn (): mixed => self::activity(BenchmarkActivity::class, $member);
        }

        return self::all($calls);
    }
}
