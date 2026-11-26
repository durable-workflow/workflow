<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

/**
 * Blocks on a signal wait past its work deadline (#863). The business branch
 * records the approval; the timeout cleanup branch records the interruption it
 * received and runs one cleanup activity.
 */
#[Type('test-timeout-cleanup-signal-workflow')]
#[Signal('approve')]
final class TestTimeoutCleanupSignalWorkflow extends Workflow
{
    public function handle(): string
    {
        try {
            $approval = await('approve');
            upsertMemo([
                'business' => $approval,
            ]);

            return 'approved';
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
