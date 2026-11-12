<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Enums\ParentClosePolicy;
use function Workflow\V2\select;
use function Workflow\V2\signal;
use Workflow\V2\Support\ChildWorkflowOptions;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

/**
 * The first run starts a long-running child it never awaits (the timer wins
 * the selection), then continues as new while that child is still open. Later
 * runs carry no handle on the child. The final run optionally waits for a
 * "close" signal so tests can interleave child completion with the open chain.
 */
#[Type('test-parent-continues-with-open-child')]
#[Signal('close')]
final class TestParentContinuesWithOpenChildWorkflow extends Workflow
{
    public function handle(
        string $policy = 'abandon',
        int $generation = 0,
        int $hops = 1,
        bool $waitBeforeClose = false,
    ): array {
        if ($generation === 0) {
            select([
                'child' => static fn () => child(
                    TestLongRunningChildWorkflow::class,
                    new ChildWorkflowOptions(parentClosePolicy: ParentClosePolicy::from($policy)),
                ),
                'ready' => static fn () => timer(0),
            ]);
        }

        if ($generation < $hops) {
            return continueAsNew($policy, $generation + 1, $hops, $waitBeforeClose);
        }

        if ($waitBeforeClose) {
            signal('close');
        }

        return [
            'generation' => $generation,
            'workflow_id' => $this->workflowId(),
        ];
    }
}
