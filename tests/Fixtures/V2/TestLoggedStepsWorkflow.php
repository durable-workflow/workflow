<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * Replay corpus workflow: the replay-aware logger observes code but never
 * changes the commands a replay produces.
 */
#[Type('test-logged-steps-workflow')]
final class TestLoggedStepsWorkflow extends Workflow
{
    public function handle(): array
    {
        $this->logger()
            ->info('logged steps started');
        $first = activity(TestGreetingActivity::class, 'first');
        $this->logger()
            ->info('logged steps first done', [
                'result' => $first,
            ]);
        $second = activity(TestGreetingActivity::class, 'second');
        $this->logger()
            ->info('logged steps second done', [
                'result' => $second,
            ]);

        return [$first, $second];
    }
}
