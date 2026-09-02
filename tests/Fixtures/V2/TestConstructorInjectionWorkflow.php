<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Contracts\Foundation\Application;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-constructor-injection-workflow')]
final class TestConstructorInjectionWorkflow extends Workflow
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
            'workflow_application' => $this->app->make('config')
                ->get('app.name'),
            'activity' => activity(TestConstructorInjectionActivity::class, $name),
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }
}
