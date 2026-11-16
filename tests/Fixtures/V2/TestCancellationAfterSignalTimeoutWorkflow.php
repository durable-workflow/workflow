<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Workflow;

#[Signal('earlier')]
#[Signal('later')]
final class TestCancellationAfterSignalTimeoutWorkflow extends Workflow
{
    public function handle(int $timeout): string
    {
        try {
            self::await('earlier', $timeout);
            self::activity(TestGreetingActivity::class, 'forward');
            self::await('later');
        } catch (WorkflowCancellationRequestedException) {
            return self::cancellationShield(
                static fn (): string => self::activity(TestGreetingActivity::class, 'cleanup')
            );
        }
        return 'not cancelled';
    }
}
