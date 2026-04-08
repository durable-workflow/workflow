<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitWithTimeout;
use Workflow\V2\Workflow;

#[Type('test-await-timeout-workflow')]
final class TestAwaitWithTimeoutWorkflow extends Workflow
{
    private bool $approved = false;

    private bool $timedOut = false;

    private string $stage = 'booting';

    public function execute(): Generator
    {
        $this->stage = 'waiting-for-approval';

        $approved = yield awaitWithTimeout(5, fn (): bool => $this->approved);

        if (! $approved) {
            $this->timedOut = true;
            $this->stage = 'timed-out';

            return [
                'approved' => false,
                'timed_out' => true,
                'stage' => $this->stage,
                'workflow_id' => $this->workflowId(),
                'run_id' => $this->runId(),
            ];
        }

        $this->stage = 'approved';

        return [
            'approved' => true,
            'timed_out' => false,
            'stage' => $this->stage,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'approved' => $this->approved,
            'timed_out' => $this->timedOut,
            'stage' => $this->stage,
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;

        return $this->currentState();
    }
}
