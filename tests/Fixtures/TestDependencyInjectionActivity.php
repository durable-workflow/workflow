<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestDependencyInjectionActivity extends Activity
{
    public function execute(TestInjectedDependency $dependency): string
    {
        return 'activity-' . $dependency->marker();
    }
}
