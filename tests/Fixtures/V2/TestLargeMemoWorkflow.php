<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\upsertMemo;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-large-memo-workflow')]
final class TestLargeMemoWorkflow extends Workflow
{
    public function handle(array $entries): string
    {
        upsertMemo($entries);

        return 'done';
    }
}
