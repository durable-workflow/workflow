<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Contracts\Foundation\Application;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-docs-dependency-injection-workflow')]
final class TestDocsDependencyInjectionWorkflow extends Workflow
{
    public function handle(Application $app): string
    {
        if ($app->runningInConsole()) {
            return 'console';
        }

        return 'http';
    }
}
