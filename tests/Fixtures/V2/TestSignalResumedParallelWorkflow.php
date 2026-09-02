<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\sideEffect;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-signal-resumed-parallel-workflow')]
final class TestSignalResumedParallelWorkflow extends Workflow
{
    /**
     * @return array<string, mixed>
     */
    public function handle(bool $nested = false): array
    {
        sideEffect(static fn (): string => 'prepared');
        sideEffect(static fn (): string => 'ready');
        $approved = await(static fn (): bool => false, timeout: 60, conditionKey: 'approval.ready');

        $activity = static fn (): mixed => activity(TestGreetingActivity::class, 'Ada');
        $child = static fn (): mixed => child(TestTimerWorkflow::class, 0);
        $timer = static fn (): mixed => timer(0);
        $results = $nested
            ? all([
                $activity,
                static fn (): mixed => all([$child, $timer]),
            ])
            : all([$activity, $child, $timer]);

        return [
            'approved' => $approved,
            'nested' => $nested,
            'results' => $results,
        ];
    }
}
