<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use Workflow\V2\Support\ServiceOperationOptions;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestNexusCancellationCleanupCallerWorkflow extends Workflow
{
    public function handle(string $name): void
    {
        try {
            Workflow::serviceOperation('php-service', 'greeter', 'greet', [$name], new ServiceOperationOptions(
                targetNamespace: 'service',
                modeOverride: 'sync',
                waitFor: 'completed',
            ));
        } catch (WorkflowCancellationRequestedException $cancel) {
            upsertMemo([
                'cancellation' => $cancel->cancellation?->toArray(),
            ]);
            timer(1);
            throw $cancel;
        }
    }
}
