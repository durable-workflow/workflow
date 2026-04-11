<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use function Workflow\V2\activity;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\getVersion;

#[Type('test-version-workflow')]
#[Signal('finish')]
final class TestVersionWorkflow extends Workflow
{
    private int $version = WorkflowStub::DEFAULT_VERSION;

    private string $result = 'booting';

    private string $stage = 'booting';

    public function handle(): array
    {
        $this->version = getVersion('step-1', WorkflowStub::DEFAULT_VERSION, 2);
        $this->result = match ($this->version) {
            WorkflowStub::DEFAULT_VERSION => activity(TestVersionedActivityV1::class),
            1 => activity(TestVersionedActivityV2::class),
            2 => activity(TestVersionedActivityV3::class),
        };

        $this->stage = 'waiting-for-finish';

        $finish = awaitSignal('finish');

        $this->stage = 'completed';

        return [
            'version' => $this->version,
            'result' => $this->result,
            'finish' => $finish,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentVersion(): int
    {
        return $this->version;
    }

    #[QueryMethod]
    public function currentResult(): string
    {
        return $this->result;
    }

    #[QueryMethod]
    public function currentStage(): string
    {
        return $this->stage;
    }
}
