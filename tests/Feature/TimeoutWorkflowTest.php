<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Tests\Fixtures\TestTimeoutWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowFailedStatus;
use Workflow\WorkflowStub;

final class TimeoutWorkflowTest extends TestCase
{
    public function testTimeout(): void
    {
        $workflow = WorkflowStub::make(TestTimeoutWorkflow::class);

        $workflow->start();

        $deadline = now()
            ->addSeconds(20);

        while ($workflow->running() && now()->lt($deadline)) {
            usleep(50000);
            $workflow->fresh();
        }

        if ($workflow->running()) {
            throw new RuntimeException(sprintf(
                'Timed out waiting for activity timeout workflow %s to fail. Current status: %s; exceptions: %d; logs: %d',
                (string) $workflow->id(),
                (string) $workflow->status(),
                $workflow->exceptions()
                    ->count(),
                $workflow->logs()
                    ->count(),
            ));
        }

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertSame(1, $workflow->exceptions()->count());
    }
}
