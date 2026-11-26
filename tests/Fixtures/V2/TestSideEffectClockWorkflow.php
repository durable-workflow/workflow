<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\now;
use function Workflow\V2\sideEffect;

use Workflow\V2\Workflow;

/**
 * Reads Workflow::now() right after a side effect, for first-execution versus
 * replay clock comparisons.
 */
final class TestSideEffectClockWorkflow extends Workflow
{
    public ?string $afterSideEffect = null;

    /**
     * @return array{after_activity: string, after_side_effect: string}
     */
    public function handle(string $name): array
    {
        activity(TestGreetingActivity::class, $name);
        $afterActivity = now()
            ->format('Y-m-d\TH:i:s.uP');
        sideEffect(static fn (): string => 'effect');
        $this->afterSideEffect = now()
            ->format('Y-m-d\TH:i:s.uP');

        return [
            'after_activity' => $afterActivity,
            'after_side_effect' => $this->afterSideEffect,
        ];
    }
}
