<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-application-version-exception-workflow')]
final class TestApplicationVersionExceptionWorkflow extends Workflow
{
    public function handle(): never
    {
        throw new VersionNotSupportedException('Application version check failed.');
    }
}
