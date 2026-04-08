<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\getVersion;

#[Type('test-version-min-supported-workflow')]
final class TestVersionMinSupportedWorkflow extends Workflow
{
    public function execute(): Generator
    {
        $version = yield getVersion('step-1', 1, 2);

        return [
            'version' => $version,
        ];
    }
}
