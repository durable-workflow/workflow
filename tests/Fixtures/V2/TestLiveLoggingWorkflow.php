<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;

/**
 * A live continuation resumes after the activity and falls back to a cold
 * replay of the same task at the side effect it doesn't handle.
 */
#[Type('test-live-logging-workflow')]
final class TestLiveLoggingWorkflow extends Workflow
{
    public function handle(): array
    {
        $this->logger()
            ->info('live started');
        $greeting = activity(TestGreetingActivity::class, 'live');
        $this->logger()
            ->info('live after activity', [
                'greeting' => $greeting,
            ]);
        $stamp = sideEffect(static fn (): string => 'stamped');
        $this->logger()
            ->info('live after side effect', [
                'stamp' => $stamp,
            ]);

        return [$greeting, $stamp];
    }
}
