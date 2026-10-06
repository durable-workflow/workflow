<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use function Workflow\continueAsNew;
use Workflow\SignalMethod;
use Workflow\Workflow;
use Workflow\WorkflowStub;

final class TestContinuedAwaitTimerWorkflow extends Workflow
{
    private bool $finished = false;

    #[SignalMethod]
    public function finish(): void
    {
        $this->finished = true;
    }

    public function execute(int $step = 0, int $lastStep = 2)
    {
        yield WorkflowStub::awaitWithTimeout('2 minutes', fn (): bool => $this->finished);

        if ($step < $lastStep) {
            return yield continueAsNew($step + 1, $lastStep);
        }

        return 'done';
    }
}
