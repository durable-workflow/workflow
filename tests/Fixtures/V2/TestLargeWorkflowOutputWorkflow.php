<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-large-workflow-output-workflow')]
final class TestLargeWorkflowOutputWorkflow extends Workflow
{
    public function handle(string $payload): string
    {
        return $payload;
    }
}
