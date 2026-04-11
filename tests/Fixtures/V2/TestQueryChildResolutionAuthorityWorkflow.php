<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-query-child-resolution-authority-workflow')]
final class TestQueryChildResolutionAuthorityWorkflow extends Workflow
{
    private string $stage = 'not-started';

    public function handle(int $seconds): array
    {
        $this->stage = 'waiting-for-child';

        $child = child(TestTimerWorkflow::class, $seconds);

        $this->stage = 'child-resolved';

        return [
            'stage' => $this->stage,
            'child' => $child,
        ];
    }

    #[QueryMethod('current-state')]
    public function currentState(): array
    {
        $handle = $this->child();

        return [
            'stage' => $this->stage,
            'child' => $handle === null ? null : [
                'instance_id' => $handle->instanceId(),
                'run_id' => $handle->runId(),
                'call_id' => $handle->callId(),
            ],
        ];
    }
}
