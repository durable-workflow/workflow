<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Contracts\Foundation\Application;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('test-constructor-injection-activity')]
final class TestConstructorInjectionActivity extends Activity
{
    public function __construct(
        private readonly Application $app,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $name): array
    {
        return [
            'application' => $this->app->make('config')
                ->get('app.name'),
            'name' => $name,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
