<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\await;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

/**
 * Catches a cancellation delivered at a signal wait and waits for the same
 * signal again (#1315). The update records the step cursor its handler runs
 * at, which replay recomputes whenever it re-applies the update.
 */
#[Signal('approve')]
final class TestCancelledSignalReawaitWorkflow extends Workflow
{
    private ?int $markedAt = null;

    public function handle(): array
    {
        try {
            await('approve');

            return [
                'interrupted' => false,
            ];
        } catch (WorkflowCancellationRequestedException) {
            $approval = await('approve');
            activity(TestGreetingActivity::class, 'cleanup');
            upsertMemo([
                'marked_at' => $this->markedAt,
            ]);

            return [
                'interrupted' => true,
                'approval' => $approval,
                'marked_at' => $this->markedAt,
            ];
        }
    }

    #[UpdateMethod]
    public function mark(): int
    {
        return $this->markedAt = $this->executionCursor();
    }
}
