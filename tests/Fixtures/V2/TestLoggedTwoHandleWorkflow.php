<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * Two losing handles: the first can resolve while the second still parks.
 */
#[Type('test-logged-two-handle-workflow')]
final class TestLoggedTwoHandleWorkflow extends Workflow
{
    public function handle(): array
    {
        $this->logger()
            ->info('two handles started');
        $selected = Workflow::select([
            'first' => static fn () => Workflow::activity(TestGreetingActivity::class, 'first'),
            'second' => static fn () => Workflow::activity(TestGreetingActivity::class, 'second'),
            'deadline' => static fn () => Workflow::timer(0),
        ]);
        $this->logger()
            ->info('two handles awaiting');
        $first = $selected->handles['first']->await();
        $this->logger()
            ->info('two handles first done');
        $second = $selected->handles['second']->await();
        $this->logger()
            ->info('two handles second done');

        return [$first, $second];
    }
}
