<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\Exceptions\WorkflowCancellationRequestedException;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestCancellationContextBindingWorkflow extends Workflow
{
    /**
     * @return array{bool, bool}
     */
    public function handle(): array
    {
        try {
            timer(3600);
        } catch (WorkflowCancellationRequestedException $cancel) {
            $context = $cancel->cancellation ?? throw new LogicException('Canonical cancellation context is missing.');
            $boundBudgetIsCorrect = $context->remaining() === 27.0;
            try {
                CancellationContext::fromArray($context->toArray())->remaining();
            } catch (LogicException) {
                return [$boundBudgetIsCorrect, true];
            }

            return [$boundBudgetIsCorrect, false];
        }

        return [false, false];
    }
}
