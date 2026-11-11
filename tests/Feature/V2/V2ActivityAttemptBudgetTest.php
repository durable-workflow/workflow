<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestActivityAttemptBudgetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * A per-call activity attempt budget counts the first attempt, so a new
 * activity with a budget below one is refused before anything is written
 * (#860).
 */
final class V2ActivityAttemptBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function invalidBudgets(): iterable
    {
        yield 'activity zero' => [0, false];
        yield 'activity negative' => [-1, false];
        yield 'local activity zero' => [0, true];
        yield 'local activity negative' => [-1, true];
    }

    #[DataProvider('invalidBudgets')]
    public function testANewActivityWithABudgetBelowOneFailsTheRunBeforeScheduling(int $budget, bool $local): void
    {
        $workflow = WorkflowStub::make(TestActivityAttemptBudgetWorkflow::class);
        $workflow->start($budget, $local);
        $this->drainReadyTasks();

        $workflow->refresh();
        $this->assertTrue($workflow->failed(), $workflow->status());
        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->count());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function activityKinds(): iterable
    {
        yield 'activity' => [false];
        yield 'local activity' => [true];
    }

    #[DataProvider('activityKinds')]
    public function testABudgetOfOneRunsTheActivityOnce(bool $local): void
    {
        $workflow = WorkflowStub::make(TestActivityAttemptBudgetWorkflow::class);
        $workflow->start(1, $local);
        $this->drainReadyTasks();

        $this->assertTrue($workflow->refresh()->completed(), $workflow->status());
        $this->assertSame('Hello, budget!', $workflow->output());
        $execution = ActivityExecution::query()->where('workflow_run_id', $workflow->runId())->sole();
        $this->assertSame(1, $execution->retry_policy['max_attempts']);
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
