<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestVersionRangeRepairActivity extends Activity
{
    public static int $executions = 0;

    public function handle(array $value): array
    {
        ++self::$executions;

        return $value;
    }
}
