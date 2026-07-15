<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestDependencyInjectionActivity;
use Tests\Fixtures\V2\TestDependencyInjectionWorkflow;
use Tests\Fixtures\V2\TestDocsDependencyInjectionWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\LocalActivityRuntime;
use Workflow\V2\WorkflowStub;

final class V2DependencyInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();
    }

    public function testDocumentedWorkflowHandleDependencyInjectionCompletes(): void
    {
        $workflow = WorkflowStub::make(TestDocsDependencyInjectionWorkflow::class, 'docs-dependency-injection');
        $workflow->start();

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('console', $workflow->output());
        $this->assertNoWorkflowFailures($workflow->runId());
    }

    public function testHandleDependencyInjectionSupportsStoredArgumentsActivitiesQueriesAndSignals(): void
    {
        $metadata = [
            'tags' => ['alpha', 'beta'],
            'attributes' => [
                'tier' => 'gold',
            ],
        ];

        $workflow = WorkflowStub::make(TestDependencyInjectionWorkflow::class, 'dependency-injection-mixed');
        $workflow->start('Taylor', $metadata);

        $this->drainReadyTasks();

        $this->assertSame('waiting', $workflow->refresh()->status());
        $this->assertNoWorkflowFailures($workflow->runId());

        $query = $workflow->query('di-state', 'before-signal', [
            'source' => 'query',
        ]);

        $this->assertTrue($query['query_running_in_console'] ?? false);
        $this->assertSame('before-signal', $query['prefix'] ?? null);
        $this->assertSame([
            'source' => 'query',
        ], $query['context'] ?? null);
        $this->assertSame('Taylor', $query['state']['name'] ?? null);
        $this->assertSame($metadata, $query['state']['metadata'] ?? null);
        $this->assertTrue($query['state']['workflow_running_in_console'] ?? false);
        $this->assertSame('queued', $query['state']['queued']['kind'] ?? null);
        $this->assertSame('local', $query['state']['local']['kind'] ?? null);
        $this->assertSame($metadata, $query['state']['queued']['metadata']['metadata'] ?? null);
        $this->assertSame($metadata, $query['state']['local']['metadata']['metadata'] ?? null);

        $signal = $workflow->signal('approved-by', 'Jordan', [
            'source' => 'signal',
            'approved' => true,
        ]);

        $this->assertTrue($signal->accepted());

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());

        $output = $workflow->output();

        $this->assertSame('completed', $output['stage'] ?? null);
        $this->assertSame('Taylor', $output['name'] ?? null);
        $this->assertSame($metadata, $output['metadata'] ?? null);
        $this->assertTrue($output['workflow_running_in_console'] ?? false);
        $this->assertSame('queued', $output['queued']['kind'] ?? null);
        $this->assertSame('local', $output['local']['kind'] ?? null);
        $this->assertSame('Taylor', $output['queued']['name'] ?? null);
        $this->assertSame('Taylor', $output['local']['name'] ?? null);
        $this->assertSame($metadata, $output['queued']['metadata']['metadata'] ?? null);
        $this->assertSame($metadata, $output['local']['metadata']['metadata'] ?? null);
        $this->assertSame([
            'Jordan',
            [
                'source' => 'signal',
                'approved' => true,
            ],
        ], $output['approval'] ?? null);

        $executions = ActivityExecution::query()
            ->where('workflow_run_id', $workflow->runId())
            ->orderBy('sequence')
            ->get();

        $this->assertCount(2, $executions);
        $this->assertSame(TestDependencyInjectionActivity::class, $executions[0]->activity_class);
        $this->assertSame(TestDependencyInjectionActivity::class, $executions[1]->activity_class);
        $this->assertSame(ActivityStatus::Completed, $executions[0]->status);
        $this->assertSame(ActivityStatus::Completed, $executions[1]->status);
        $this->assertNull($executions[0]->activity_options['execution_mode'] ?? null);
        $this->assertSame(
            LocalActivityRuntime::EXECUTION_MODE,
            $executions[1]->activity_options['execution_mode'] ?? null
        );
        $this->assertNoWorkflowFailures($workflow->runId());
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $cutoff = now()
                ->format('Y-m-d H:i:s.u');

            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->where(static function ($query) use ($cutoff): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', $cutoff);
                })
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }

    private function assertNoWorkflowFailures(string $runId): void
    {
        $this->assertSame(0, WorkflowFailure::query() ->where('workflow_run_id', $runId) ->count());
    }
}
