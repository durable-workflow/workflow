<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-await-workflow')]
final class TestAwaitWorkflow extends Workflow
{
    private bool $approved = false;

    private string $stage = 'booting';

    public function execute(): array
    {
        $this->stage = 'waiting-for-approval';

        await(fn (): bool => $this->approved);

        $this->stage = 'completed';

        return [
            'approved' => $this->approved,
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
