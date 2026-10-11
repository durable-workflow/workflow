<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Workflow;

#[Type('test-version-pending-range-repair-workflow')]
final class TestVersionPendingRangeRepairWorkflow extends Workflow
{
    public static int $minSupported = 1;

    public static int $maxSupported = 1;

    public function handle(array $value): array
    {
        self::getVersion('repair-range', self::$minSupported, self::$maxSupported);
        $selected = self::select([
            'activity' => static fn () => self::activity(
                TestVersionRangeRepairActivity::class,
                new ActivityOptions(queue: 'server-parity-range-activity'),
                $value
            ),
            'timer' => static fn () => self::timer(3),
        ]);

        return $selected->handles['activity']->await();
    }
}
