<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-repeated-version')]
final class TestRepeatedVersionWorkflow extends Workflow
{
    public function handle(int $minSupported = -1): array
    {
        $first = self::getVersion('frozen', -1, 1);
        $second = self::getVersion('frozen', $minSupported, 5);

        return [$first, $second];
    }
}
