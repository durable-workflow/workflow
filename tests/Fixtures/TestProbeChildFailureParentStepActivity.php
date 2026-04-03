<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestProbeChildFailureParentStepActivity extends Activity
{
    public function execute(): string
    {
        return 'prepared';
    }
}
