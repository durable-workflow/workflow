<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Contracts\Foundation\Application;
use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\localActivity;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-dependency-injection-workflow')]
#[Signal('approved-by', [
    [
        'name' => 'actor',
        'type' => 'string',
        'allows_null' => false,
    ],
    [
        'name' => 'context',
        'type' => 'array',
        'allows_null' => false,
    ],
])]
final class TestDependencyInjectionWorkflow extends Workflow
{
    /**
     * @var array<string, mixed>
     */
    private array $state = [
        'stage' => 'initialized',
    ];

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function handle(Application $app, string $name, array $metadata = []): array
    {
        $this->state = [
            'stage' => 'started',
            'workflow_running_in_console' => $app->runningInConsole(),
            'name' => $name,
            'metadata' => $metadata,
        ];

        $this->state['queued'] = activity(TestDependencyInjectionActivity::class, 'queued', $name, [
            'source' => 'ordinary-activity',
            'metadata' => $metadata,
        ]);

        $this->state['local'] = localActivity(TestDependencyInjectionActivity::class, 'local', $name, [
            'source' => 'local-activity',
            'metadata' => $metadata,
        ]);

        $approval = signal('approved-by');

        $this->state['approval'] = is_array($approval) ? array_values($approval) : [$approval];
        $this->state['stage'] = 'completed';

        return [
            ...$this->state,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    #[QueryMethod('di-state')]
    public function dependencyState(Application $app, string $prefix, array $context = []): array
    {
        return [
            'query_running_in_console' => $app->runningInConsole(),
            'prefix' => $prefix,
            'context' => $context,
            'state' => $this->state,
        ];
    }
}
