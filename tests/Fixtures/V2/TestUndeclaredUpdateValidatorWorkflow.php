<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use Workflow\UpdateValidator;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-undeclared-update-validator-workflow')]
final class TestUndeclaredUpdateValidatorWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'never';
    }

    #[UpdateMethod]
    public function rename(string $name): string
    {
        return $name;
    }

    #[UpdateValidator('missing')]
    public function validateMissing(): void
    {
    }
}
