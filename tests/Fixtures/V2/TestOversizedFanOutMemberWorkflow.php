<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

/**
 * One all() whose member at $oversizedIndex carries an argument of
 * $oversizedBytes (#1232). Members are activities, children, timers or a
 * mix of every member kind; a mixed group puts the oversized argument on an
 * activity or a child.
 */
#[Type('test-oversized-fan-out-member-workflow')]
final class TestOversizedFanOutMemberWorkflow extends Workflow
{
    public function handle(int $count, int $oversizedIndex, int $oversizedBytes, string $kind = 'activity'): array
    {
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
            $argument = $i === $oversizedIndex ? str_repeat('x', $oversizedBytes) : "item-{$i}";
            $calls[] = match ($kind) {
                'child' => static fn () => child(TestGreetingWorkflow::class, $argument),
                'timer' => static fn () => timer(60),
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

        return all($calls);
    }
}
