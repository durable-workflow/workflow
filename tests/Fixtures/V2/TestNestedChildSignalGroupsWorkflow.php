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
 * Nests a child with signals in groups. In the `waits` shape the child's inner group still needs the
 * `inner` signal and the outer group the `outer` signal, so the child's completion resumes nothing. In
 * the `wakes` shape the child's group wins the selection, so its completion resumes the run.
 */
#[Type('test-nested-child-signal-groups-workflow')]
#[Signal('inner')]
#[Signal('outer')]
final class TestNestedChildSignalGroupsWorkflow extends Workflow
{
    public function handle(string $shape): array
    {
        if ($shape === 'waits') {
            return all([
                static fn () => all([
                    static fn () => child(TestWatchdogNoopChildWorkflow::class, 'nested'),
                    static fn () => await('inner'),
                ]),
                static fn () => await('outer'),
            ]);
        }

        $selection = select([
            'group' => static fn () => all([
                static fn () => child(TestWatchdogNoopChildWorkflow::class, 'nested'),
            ]),
            'inner' => static fn () => await('inner'),
        ]);

        return [
            'winner' => $selection->key,
            'value' => $selection->value,
        ];
    }
}
