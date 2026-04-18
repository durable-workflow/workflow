<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use function Workflow\V2\upsertSearchAttributes;
use Workflow\V2\Workflow;

/**
 * Accepts pre-built search attributes and upserts them.
 * Used by structural limit tests to exercise the size guard.
 *
 * @param array<string, scalar|null> $attributes
 */
#[Type('test-large-search-attr-workflow')]
final class TestLargeSearchAttributeWorkflow extends Workflow
{
    public function handle(array $attributes): string
    {
        upsertSearchAttributes($attributes);

        return 'done';
    }
}
