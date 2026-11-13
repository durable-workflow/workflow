<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\select;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

/**
 * Closes its run while a select() loser is still open (#1117).
 */
#[Type('test-selection-loser-at-close-workflow')]
final class TestSelectionLoserAtCloseWorkflow extends Workflow
{
    public ?string $caught = null;

    public function handle(string $mode, int $round = 0): mixed
    {
        if ($round > 0) {
            return 'continued';
        }

        if ($mode === 'all-failure') {
            // The second member fails first; the first is still open when the
            // workflow handles the failure and closes.
            try {
                all([
                    static fn () => activity(TestGreetingActivity::class, 'still-running'),
                    static fn () => activity(TestFailingActivity::class),
                ]);
            } catch (\Throwable $throwable) {
                $this->caught = $throwable->getMessage();
            }

            return 'caught: ' . $this->caught;
        }

        $selected = match ($mode) {
            // The activity wins and the hour-long timer stays pending.
            'timer-loser' => select([
                'work' => static fn () => activity(TestGreetingActivity::class, 'timer-loser'),
                'deadline' => static fn () => timer(3600),
            ]),
            // Whichever activity closes first wins; the other may already run.
            'activity-loser' => select([
                'first' => static fn () => activity(TestGreetingActivity::class, 'first'),
                'second' => static fn () => activity(TestGreetingActivity::class, 'second'),
            ]),
            // The zero timer wins and the activity is never awaited.
            default => select([
                'work' => static fn () => activity(TestGreetingActivity::class, $mode),
                'deadline' => static fn () => timer(0),
            ]),
        };

        return match ($mode) {
            'fail' => throw new \RuntimeException('Closing with an open select() loser.'),
            'continue' => continueAsNew($mode, 1),
            default => 'closed-with-' . $selected->key,
        };
    }
}
