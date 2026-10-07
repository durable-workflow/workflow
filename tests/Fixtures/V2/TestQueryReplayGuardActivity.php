<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use Workflow\V2\Activity;

final class TestQueryReplayGuardActivity extends Activity
{
    public static int $executions = 0;

    public function handle(): string
    {
        ++self::$executions;

        throw new LogicException('Query replay must not execute an activity.');
    }
}
