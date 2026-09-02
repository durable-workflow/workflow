<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-mixed-entry-activity-workflow')]
final class TestMixedEntryActivityWorkflow extends Workflow
{
    public function handle(string $name): array
    {
        return [
            'greeting' => activity(TestMixedEntryActivity::class, $name),
        ];
    }
}
