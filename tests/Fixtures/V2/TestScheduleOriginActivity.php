<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestScheduleOriginActivity extends Activity
{
    public function handle(?array $origin): ?array
    {
        return $origin;
    }
}
