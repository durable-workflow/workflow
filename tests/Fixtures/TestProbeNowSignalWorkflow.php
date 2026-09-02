<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\Carbon;
use Workflow\SignalMethod;
use Workflow\Workflow;
use Workflow\WorkflowStub;

final class TestProbeNowSignalWorkflow extends Workflow
{
    public static bool $signalSawCarbonNow = false;

    #[SignalMethod]
    public function recordNowType(): void
    {
        self::$signalSawCarbonNow = WorkflowStub::now() instanceof Carbon;
    }

    public function execute()
    {
        if (false) {
            yield;
        }

        return 'ok';
    }
}
