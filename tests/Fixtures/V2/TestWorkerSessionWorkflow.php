<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\WorkerSessionOptions;
use Workflow\V2\Workflow;

#[Type('test-worker-session-workflow')]
final class TestWorkerSessionWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        $session = Workflow::workerSession(
            'gpu-render',
            new WorkerSessionOptions(
                queue: 'gpu-activities',
                requirements: ['gpu:nvidia-l4'],
                leaseSeconds: 120,
                ttlSeconds: 600,
                maxConcurrentActivities: 1,
            ),
        );

        return [
            'greeting' => $session->activity(TestGreetingActivity::class, $name),
        ];
    }
}
