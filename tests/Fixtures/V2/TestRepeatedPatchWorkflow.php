<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-repeated-patch')]
final class TestRepeatedPatchWorkflow extends Workflow
{
    public function handle(): array
    {
        $first = self::patched('same');
        $second = self::patched('same');
        self::deprecatePatch('same');

        return [$first, $second, self::activity(TestVersionedActivityV1::class)];
    }
}
