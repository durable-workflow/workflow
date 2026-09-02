<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Activity;

final class TestContinueAsNewActivity extends Activity
{
    public function handle(int $count): int
    {
        return $count;
    }
}
