<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parallel-child-handles-workflow')]
final class TestParallelChildHandlesWorkflow extends Workflow
{
    public function handle(): array
    {
        return all([
            fn () => child(TestChildHandleChildWorkflow::class),
            fn () => child(TestChildHandleChildWorkflow::class),
            fn () => child(TestChildHandleChildWorkflow::class),
        ]);
    }

    #[QueryMethod('child-handles')]
    public function childHandles(): array
    {
        return array_map(
            static fn ($handle): array => [
                'instance_id' => $handle->instanceId(),
                'run_id' => $handle->runId(),
                'call_id' => $handle->callId(),
            ],
            $this->children(),
        );
    }

    #[QueryMethod('latest-child-handle')]
    public function latestChildHandle(): ?array
    {
        $handle = $this->child();

        if ($handle === null) {
            return null;
        }

        return [
            'instance_id' => $handle->instanceId(),
            'run_id' => $handle->runId(),
            'call_id' => $handle->callId(),
        ];
    }
}
