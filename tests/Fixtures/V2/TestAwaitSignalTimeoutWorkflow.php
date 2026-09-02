<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-await-signal-timeout-workflow')]
#[Signal('approved-by')]
#[Signal('empty')]
#[Signal('multi')]
final class TestAwaitSignalTimeoutWorkflow extends Workflow
{
    private string $stage = 'booting';

    public function handle(string $signalName = 'approved-by', int $timeout = 5): array
    {
        $this->stage = 'waiting';

        $payload = await($signalName, timeout: $timeout);

        $this->stage = $payload === null ? 'timed-out' : 'received';

        return [
            'payload' => $payload,
            'timed_out' => $payload === null,
            'stage' => $this->stage,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
        ];
    }
}
