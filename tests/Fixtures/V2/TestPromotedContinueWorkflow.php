<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use function Workflow\V2\continueAsNewWithTarget;
use Workflow\V2\Exceptions\ContinueAsNewTargetException;
use Workflow\V2\Workflow;

#[Type('test-promoted-continue')]
final class TestPromotedContinueWorkflow extends Workflow
{
    public function handle(string $stage): mixed
    {
        if ($stage === 'check-service-gate') {
            try {
                return continueAsNewWithTarget('promoted', 'done');
            } catch (ContinueAsNewTargetException $exception) {
                return $exception->reason;
            }
        }

        return match ($stage) {
            'promoted' => continueAsNewWithTarget('promoted', 'done'),
            'inherited' => continueAsNew('done'),
            default => $stage,
        };
    }
}
