<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestContinuationGrowthWorkflow extends Workflow
{
    public static int $entries = 0;

    public static int $roundVisits = 0;

    public function handle(int $rounds): array
    {
        ++self::$entries;
        $clocks = [Workflow::now()->toJSON()];
        $value = null;
        for ($round = 1; $round <= $rounds; ++$round) {
            ++self::$roundVisits;
            $value = Workflow::activity(TestContinuationGrowthActivity::class, $round);
            $clocks[] = Workflow::now()->toJSON();
            Workflow::timer(1);
            $clocks[] = Workflow::now()->toJSON();
        }
        Workflow::timer(1);
        $clocks[] = Workflow::now()->toJSON();

        return [
            'rounds' => $rounds,
            'value' => $value,
            'clocks' => $clocks,
        ];
    }
}
