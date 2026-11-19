<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-fan-out-payload-leaf-workflow')]
final class TestFanOutPayloadLeafWorkflow extends Workflow
{
    public function handle(int $index, string $payload): int
    {
        return strlen($payload);
    }
}
