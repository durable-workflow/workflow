<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Generator;
use function Workflow\awaitWithTimeout;
use Workflow\SignalMethod;
use Workflow\Workflow;

final class TestNestedSignalLeafWorkflow extends Workflow
{
    private bool $responded = false;

    #[SignalMethod]
    public function respond(): void
    {
        $this->responded = true;
    }

    public function execute(int $runId, int $middleIndex, int $leafIndex): Generator
    {
        $resolved = yield awaitWithTimeout(30, fn (): bool => $this->responded);

        return [
            'run_id' => $runId,
            'middle_index' => $middleIndex,
            'leaf_index' => $leafIndex,
            'resolved' => $resolved,
        ];
    }
}
