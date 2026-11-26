<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

/**
 * Returns its business result as soon as one timer or activity resolves
 * (#863). When that result arrives at or after the work deadline, the
 * workflow task that runs it is the one in which the engine notices the
 * deadline.
 */
#[Type('test-timeout-cleanup-same-turn-workflow')]
final class TestTimeoutCleanupSameTurnWorkflow extends Workflow
{
    public function handle(string $wait, int $seconds): string
    {
        try {
            $value = $wait === 'timer'
                ? timer($seconds)
                : activity(TestGreetingActivity::class, 'work');

            return 'business-done:' . json_encode($value);
        } catch (WorkflowTimeoutCleanupRequested $interruption) {
            upsertMemo([
                'interruption' => TimeoutCleanupInterruption::describe($interruption),
            ]);

            return 'cleaned-up';
        }
    }
}
