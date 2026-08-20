<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use function Workflow\activity;
use Workflow\Workflow;

final class TestDependencyInjectionWorkflow extends Workflow
{
    public function execute(TestInjectedDependency $dependency)
    {
        $activityResult = yield activity(TestDependencyInjectionActivity::class);

        return 'workflow-' . $dependency->marker() . ':' . $activityResult;
    }
}
