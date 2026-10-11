<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\getVersion;
use Workflow\V2\Workflow;

#[Type('test-version-range-repair-workflow')]
final class TestVersionRangeRepairWorkflow extends Workflow
{
    public static int $minSupported = 1;

    public static int $maxSupported = 1;

    public function handle(array $value): array
    {
        getVersion('repair-range', self::$minSupported, self::$maxSupported);

        return activity(TestVersionRangeRepairActivity::class, $value);
    }
}
