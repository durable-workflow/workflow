<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-immediate-failure-workflow')]
final class TestImmediateFailureWorkflow extends Workflow
{
    public function handle(): string
    {
        throw new RuntimeException('The workflow refused the order.');
    }
}
