<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestExecuteCompatibilityWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Webhooks;
use Workflow\V2\WorkflowStub;

final class V2EntryMethodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('workflows.webhook_auth.method', 'none');
        Queue::fake();
    }

    public function testHandleIsTheCanonicalV2WorkflowAndActivityEntryMethod(): void
    {
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'handle-entry-method');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Taylor!', $workflow->output()['greeting']);
    }

    public function testExecuteBasedV2WorkflowsRemainLoadableForCompatibility(): void
    {
        $workflow = WorkflowStub::make(TestExecuteCompatibilityWorkflow::class, 'execute-entry-method');
        $workflow->start('Jordan');

        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('Hello, Jordan!', $workflow->output()['greeting']);
        $this->assertSame('Hello, Jordan!', $workflow->query('greeting'));
    }

    public function testWebhookStartStillSupportsExecuteBasedCompatibilityWorkflows(): void
    {
        Webhooks::routes([TestExecuteCompatibilityWorkflow::class]);

        $response = $this->postJson('/webhooks/start/test-execute-compatibility-workflow', [
            'workflow_id' => 'execute-compatibility-webhook',
            'name' => 'Casey',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('workflow_type', 'test-execute-compatibility-workflow');

        $workflow = WorkflowStub::load('execute-compatibility-webhook');

        $this->drainReadyTasks();

        $workflow->refresh();

        $this->assertSame('Hello, Casey!', $workflow->output()['greeting']);
        $this->assertSame('Hello, Casey!', $workflow->query('greeting'));
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
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
}
