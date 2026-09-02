<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Generator;
use function Workflow\all;
use function Workflow\child;
use Workflow\Workflow;

final class TestNestedSignalParentWorkflow extends Workflow
{
    public function execute(int $runId, int $middleCount = 10, int $leafCount = 3): Generator
    {
        $promises = [];

        for ($middleIndex = 0; $middleIndex < $middleCount; $middleIndex++) {
            $promises[] = child(TestNestedSignalMiddleWorkflow::class, $runId, $middleIndex, $leafCount);
        }

        $resolvedPerMiddle = yield all($promises);

        return [
            'run_id' => $runId,
            'middle_count' => $middleCount,
            'leaf_count' => $leafCount,
            'resolved_leaf_count' => array_sum($resolvedPerMiddle),
        ];
    }
}
