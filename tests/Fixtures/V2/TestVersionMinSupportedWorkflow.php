<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\getVersion;
use Workflow\V2\Workflow;

#[Type('test-version-min-supported-workflow')]
final class TestVersionMinSupportedWorkflow extends Workflow
{
    public function handle(): array
    {
        $version = getVersion('step-1', 1, 2);

        return [
            'version' => $version,
        ];
    }
}
