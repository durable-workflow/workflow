<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use Workflow\V2\Workflow;

final class TestCancellationContextWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            timer(3600);
        } catch (WorkflowCancellationRequestedException $cancel) {
            $context = $cancel->cancellation ?? throw new LogicException('Canonical cancellation context is missing.');
            upsertMemo([
                'cancellation' => $context->toArray(),
                'remaining' => $context->remaining(),
            ]);
            timer($context->remaining() === 27.0 ? 1 : 2);
            throw $cancel;
        }
    }
}
