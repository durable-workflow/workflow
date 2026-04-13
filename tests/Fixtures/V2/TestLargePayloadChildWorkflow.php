<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\child;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-large-payload-child-workflow')]
final class TestLargePayloadChildWorkflow extends Workflow
{
    public function handle(string $payload): string
    {
        return child(TestLargePayloadWorkflow::class, $payload);
    }
}
