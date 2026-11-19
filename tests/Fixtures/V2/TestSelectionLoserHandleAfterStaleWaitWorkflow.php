<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\select;
use Workflow\V2\Workflow;

/**
 * A child loses its selection, a later selection leaves its losing signal wait open, and only then does
 * the workflow await the child through its selection handle. Such an await records no history, so the
 * stale wait must not be taken as what the run waits on.
 */
#[Type('test-selection-loser-handle-after-stale-wait-workflow')]
#[Signal('first')]
#[Signal('second')]
#[Signal('third')]
final class TestSelectionLoserHandleAfterStaleWaitWorkflow extends Workflow
{
    public function handle(): array
    {
        $race = select([
            'child' => static fn () => child(TestWatchdogNoopChildWorkflow::class, 'loser'),
            'first' => static fn () => await('first'),
        ]);
        $later = select([
            'second' => static fn () => await('second'),
            'third' => static fn () => await('third'),
        ]);

        return [
            'race' => $race->key,
            'later' => $later->key,
            'child' => $race->handles['child']->await(),
        ];
    }
}
