<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

/**
 * One all() whose member at $oversizedIndex carries an argument of
 * $oversizedBytes (#1232). Members are activities, children, timers or a
 * mix of every member kind; a mixed group puts the oversized argument on an
 * activity or a child, and a timer group puts it on an activity. A nested
 * kind ("nested" for activities, "nested-<kind>" otherwise) puts the members
 * in inner all() groups of three. A signalled group starts with a wait for the advance signal, so
 * the signal replays the group while its activities are open.
 */
#[Type('test-oversized-fan-out-member-workflow')]
#[Signal('advance')]
final class TestOversizedFanOutMemberWorkflow extends Workflow
{
    public function handle(int $count, int $oversizedIndex, int $oversizedBytes, string $kind = 'activity'): array
    {
        $nested = str_starts_with($kind, 'nested');
        $kind = $kind === 'nested' ? 'activity' : (string) preg_replace('/^nested-/', '', $kind);
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
            $argument = $i === $oversizedIndex ? str_repeat('x', $oversizedBytes) : "item-{$i}";
            $calls[] = match ($kind) {
                'child' => static fn () => child(TestGreetingWorkflow::class, $argument),
                'timer' => $i === $oversizedIndex
                    ? static fn () => activity(TestGreetingActivity::class, $argument)
                    : static fn () => timer(60),
                'signalled' => $i === 0
                    ? static fn () => await('advance')
                    : static fn () => activity(TestGreetingActivity::class, $argument),
                'mixed' => match ($i === $oversizedIndex ? 3 : $i % 6) {
                    0 => static fn () => await('advance'),
                    1 => static fn () => timer(60),
                    2 => static fn () => await(static fn (): bool => false, timeout: 60, conditionKey: "ready.{$i}"),
                    4 => static fn () => child(TestGreetingWorkflow::class, $argument),
                    default => static fn () => activity(TestGreetingActivity::class, $argument),
                },
                default => static fn () => activity(TestGreetingActivity::class, $argument),
            };
        }

        if ($nested) {
            return all(array_map(static fn (array $inner) => static fn () => all($inner), array_chunk($calls, 3)));
        }

        return all($calls);
    }
}
