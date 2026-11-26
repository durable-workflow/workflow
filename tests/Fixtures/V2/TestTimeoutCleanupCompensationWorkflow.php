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
 * Waits past its work deadline, then compensates with one activity and records
 * the result before cleanup returns (#863).
 */
#[Type('test-timeout-cleanup-compensation-workflow')]
final class TestTimeoutCleanupCompensationWorkflow extends Workflow
{
    public function handle(): string
    {
        try {
            timer(7200);

            return 'not-interrupted';
        } catch (WorkflowTimeoutCleanupRequested $interruption) {
            $compensation = activity(TestGreetingActivity::class, 'compensation');
            upsertMemo([
                'compensation' => $compensation,
                'cause' => $interruption->cancellation?->cause,
            ]);

            return 'cleaned-up';
        }
    }
}
