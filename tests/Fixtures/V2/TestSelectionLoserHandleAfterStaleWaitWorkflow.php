<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\select;
use Workflow\V2\Workflow;

/**
 * A child loses its selection. A later selection leaves its losing signal wait
 * open before the workflow awaits the child through its original handle.
 */
#[Type('test-selection-loser-handle-after-stale-wait-workflow')]
#[Signal('first')]
#[Signal('second')]
#[Signal('third')]
#[Signal('after')]
final class TestSelectionLoserHandleAfterStaleWaitWorkflow extends Workflow
{
    public function handle(bool $waitAfterChild = false, bool $nested = false): array
    {
        $race = select([
            'child' => static fn () => $nested ? all([
                static fn () => child(TestWatchdogNoopChildWorkflow::class, 'loser'),
                static fn () => child(TestWatchdogNoopChildWorkflow::class, 'second-child'),
            ]) : child(TestWatchdogNoopChildWorkflow::class, 'loser'),
            'first' => static fn () => await('first'),
        ]);
        $later = select([
            'second' => static fn () => await('second'),
            'third' => static fn () => await('third'),
        ]);

        $result = [
            'race' => $race->key,
            'later' => $later->key,
            'child' => $race->handles['child']->await(),
        ];

        if ($waitAfterChild) {
            $result['after'] = await('after');
        }

        return $result;
    }
}
