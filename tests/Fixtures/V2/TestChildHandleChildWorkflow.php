<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-child-handle-child-workflow')]
#[Signal('approved-by')]
final class TestChildHandleChildWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(): array
    {
        $this->stage = 'waiting-for-approval';

        $approvedBy = signal('approved-by');

        $this->stage = 'approved';

        return [
            'approved_by' => $approvedBy,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod('current-stage')]
    public function currentStage(): string
    {
        return $this->stage;
    }
}
