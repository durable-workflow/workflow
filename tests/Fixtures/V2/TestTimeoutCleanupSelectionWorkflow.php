<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;
use function Workflow\V2\select;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

/**
 * Blocks on a selection of a signal wait and an activity past its work
 * deadline (#863). Whichever member wins is recorded as the business result;
 * the timeout cleanup branch records the interruption and runs one cleanup
 * activity.
 */
#[Type('test-timeout-cleanup-selection-workflow')]
#[Signal('approve')]
final class TestTimeoutCleanupSelectionWorkflow extends Workflow
{
    public function handle(): string
    {
        try {
            $selected = select([
                'approval' => static fn () => await('approve'),
                'work' => static fn () => activity(TestGreetingActivity::class, 'work'),
            ]);
            upsertMemo([
                'business' => [
                    'winner' => $selected->key,
                    'value' => $selected->value,
                ],
            ]);

            return 'selected';
        } catch (WorkflowTimeoutCleanupRequested $interruption) {
            upsertMemo([
                'interruption' => TimeoutCleanupInterruption::describe($interruption),
            ]);
            upsertMemo([
                'cleanup' => activity(TestGreetingActivity::class, 'cleanup'),
            ]);

            return 'cleaned-up';
        }
    }
}
