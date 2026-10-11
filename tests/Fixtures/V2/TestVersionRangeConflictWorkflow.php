<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\getVersion;
use Workflow\V2\Workflow;

#[Type('test-version-range-conflict-workflow')]
final class TestVersionRangeConflictWorkflow extends Workflow
{
    public function handle(): int
    {
        return getVersion('range-probe', 2, 2);
    }
}
