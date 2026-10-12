<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestNexusCancellationTargetWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        try {
            timer(3600);
            return [
                'greeting' => 'hello ' . $name,
            ];
        } catch (WorkflowCancellationRequestedException $cancel) {
            upsertMemo([
                'cleanup' => $cancel->cancellation?->toArray(),
            ]);
            timer(2);
            throw $cancel;
        }
    }
}
