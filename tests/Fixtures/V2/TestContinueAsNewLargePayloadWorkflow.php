<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Workflow;

#[Type('test-continue-as-new-large-payload-workflow')]
final class TestContinueAsNewLargePayloadWorkflow extends Workflow
{
    public function handle(string $payload, int $count = 0): mixed
    {
        if ($count > 0) {
            return 'continued';
        }

        return continueAsNew($payload, $count + 1);
    }
}
