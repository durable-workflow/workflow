<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use Workflow\V2\Workflow;

#[Type('test-fiber-signal-workflow')]
#[Signal('approved-by')]
final class TestFiberSignalWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?string $approvedBy = null;

    public function handle(string $name): array
    {
        $this->stage = 'loading-greeting';
        $greeting = activity(TestGreetingActivity::class, $name);

        $this->stage = 'waiting-for-approval';
        $approvedBy = awaitSignal('approved-by');

        $this->approvedBy = is_string($approvedBy) ? $approvedBy : null;
        $this->stage = 'completed';

        return [
            'greeting' => $greeting,
            'approved_by' => $approvedBy,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    /**
     * @return array{stage: string, approved_by: ?string}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'approved_by' => $this->approvedBy,
        ];
    }
}
