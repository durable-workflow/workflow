<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\deprecatePatch;
use Workflow\V2\Workflow;

#[Type('test-deprecated-patch-workflow')]
final class TestDeprecatedPatchWorkflow extends Workflow
{
    public function handle(): array
    {
        $result = deprecatePatch('patch-1');

        return [
            'marker_result' => $result,
            'branch' => 'patched',
        ];
    }
}
