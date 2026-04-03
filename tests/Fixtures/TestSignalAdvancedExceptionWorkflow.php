<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\activity;
use function Workflow\await;
use Workflow\SignalMethod;
use Workflow\Workflow;

final class TestSignalAdvancedExceptionWorkflow extends Workflow
{
    private bool $shouldContinue = false;

    #[SignalMethod]
    public function continueAfterFailure(): void
    {
        $this->shouldContinue = true;
    }

    public function execute()
    {
        try {
            yield activity(TestSingleTryExceptionActivity::class, true);
        } catch (Throwable) {
            yield await(fn (): bool => $this->shouldContinue);
            yield activity(TestSingleTryExceptionActivity::class, true);
        }

        return 'handled';
    }
}
