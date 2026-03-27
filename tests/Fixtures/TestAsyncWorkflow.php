<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Workflow\Workflow;
use function Workflow\{activity, async};

final class TestAsyncWorkflow extends Workflow
{
    public function execute()
    {
        $results = yield async(static function (Application $app) {
            if (! $app->runningInConsole()) {
                throw new RuntimeException('Test workflows must run in console.');
            }

            $otherResult = yield activity(TestOtherActivity::class, 'other');

            $result = yield activity(TestActivity::class);

            return [$otherResult, $result];
        });

        $otherResult = $results[0];
        $result = $results[1];

        return 'workflow_' . $result . '_' . $otherResult;
    }
}
