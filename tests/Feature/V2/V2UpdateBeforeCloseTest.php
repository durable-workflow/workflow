<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestUpdateBeforeCloseWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\UpdateResult;
use Workflow\V2\WorkflowStub;

/**
 * An update accepted while the task that completes the run is running gets a
 * delivery turn before the run closes, as a signal does (#890).
 */
final class V2UpdateBeforeCloseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
    }

    protected function tearDown(): void
    {
        TestUpdateBeforeCloseWorkflow::$beforeReturn = null;

        parent::tearDown();
    }

    public function testAnUpdateAcceptedWhileTheClosingTaskRunsIsDeliveredBeforeTheRunCompletes(): void
    {
        $workflow = WorkflowStub::make(TestUpdateBeforeCloseWorkflow::class, 'update-before-close');
        $workflow->start();

        $submitted = null;
        TestUpdateBeforeCloseWorkflow::$beforeReturn = static function (string $instanceId) use (&$submitted): void {
            $submitted = WorkflowStub::load($instanceId)->submitUpdate('note', 'late');
            // The hook runs in workflow code, where now() is the workflow
            // clock. A real caller accepts the update outside it, at wall
            // time during the task's lease.
            WorkflowUpdate::query()->findOrFail($submitted->updateId())->forceFill([
                'accepted_at' => CarbonImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true))),
            ])->save();
        };

        $this->drainReadyTasks();

        $this->assertInstanceOf(UpdateResult::class, $submitted);
        $this->assertIsString($submitted->updateId());

        $update = WorkflowUpdate::query()->findOrFail($submitted->updateId());
        $this->assertSame(UpdateStatus::Completed, $update->status, (string) $update->status->value);

        $this->assertTrue($workflow->refresh()->completed(), $workflow->status());
        $this->assertSame(['late'], $workflow->output());
        $this->assertSame('late', $workflow->inspectUpdate($submitted->updateId())->result());
    }

    public function testTheClosingTaskForAnUpdateDoesNotWaitForThatUpdateAgain(): void
    {
        $workflow = WorkflowStub::make(TestUpdateBeforeCloseWorkflow::class, 'update-before-close-once');
        $workflow->start();
        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->completed(), $workflow->status());

        // A closed run fails a later update, as before.
        $result = $workflow->attemptUpdate('note', 'too-late');
        $this->assertFalse($result->completed());
    }

    private function drainReadyTasks(): void
    {
        for ($i = 0; $i < 50; ++$i) {
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

        $this->fail('Tasks did not drain.');
    }
}
