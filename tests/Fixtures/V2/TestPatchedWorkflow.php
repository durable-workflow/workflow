<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\patched;
use Workflow\V2\Workflow;

#[Type('test-patched-workflow')]
final class TestPatchedWorkflow extends Workflow
{
    public function handle(): array
    {
        $patched = patched('patch-1');

        return [
            'patched' => $patched,
            'branch' => $patched ? 'patched' : 'legacy',
        ];
    }
}
