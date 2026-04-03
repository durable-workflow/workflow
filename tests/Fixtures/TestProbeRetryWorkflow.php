<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Throwable;
use function Workflow\activity;
use function Workflow\await;
use Workflow\SignalMethod;
use Workflow\Workflow;

final class TestProbeRetryWorkflow extends Workflow
{
    #[SignalMethod]
    public function requestRetry(): void
    {
        $this->inbox->receive('retry');
    }

    public function execute()
    {
        $attempt = 0;

        while (true) {
            try {
                ++$attempt;

                return yield activity(TestProbeRetryActivity::class, $attempt);
            } catch (Throwable $throwable) {
                if ($attempt >= 3) {
                    throw $throwable;
                }

                yield await(fn (): bool => $this->inbox->hasUnread());

                $this->inbox->nextUnread();
            }
        }
    }
}
