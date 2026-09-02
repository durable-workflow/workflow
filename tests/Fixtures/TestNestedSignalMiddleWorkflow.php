<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Generator;
use function Workflow\all;
use function Workflow\child;
use Workflow\Workflow;

final class TestNestedSignalMiddleWorkflow extends Workflow
{
    public function execute(int $runId, int $middleIndex, int $leafCount = 3): Generator
    {
        $promises = [];

        for ($leafIndex = 0; $leafIndex < $leafCount; $leafIndex++) {
            $promises[] = child(TestNestedSignalLeafWorkflow::class, $runId, $middleIndex, $leafIndex);
        }

        $results = yield all($promises);

        return count(array_filter(
            $results,
            static fn (array $result): bool => (bool) ($result['resolved'] ?? false)
        ));
    }
}
