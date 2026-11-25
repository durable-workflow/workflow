<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * Code between a select() and awaiting its losing handle records no step.
 */
#[Type('test-logged-selection-workflow')]
final class TestLoggedSelectionWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $this->logger()
            ->info('selection started');
        $selected = Workflow::select([
            'work' => static fn () => Workflow::activity(TestGreetingActivity::class, $name),
            'deadline' => static fn () => Workflow::timer(0),
        ]);
        $this->logger()
            ->info('selection awaiting work', [
                'winner' => $selected->key,
            ]);
        $work = $selected->handles['work']->await();
        $this->logger()
            ->info('selection finished', [
                'work' => $work,
            ]);

        return [$selected->key, $work];
    }
}
