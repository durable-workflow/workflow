<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Support\V2\NullLongPollWakeStore;
use Tests\Support\V2\ThrowingLongPollWakeStore;
use Tests\TestCase;
use Workflow\V2\Contracts\LongPollWakeStore;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\CacheLongPollWakeStore;
use Workflow\V2\WorkflowStub;

/**
 * Covers the degraded-mode scheduler-correctness scenarios pinned in
 * docs/architecture/scheduler-correctness.md. Each scenario verifies
 * that the acceleration layer's failure does not become a correctness
 * failure: tasks still move through their durable lifecycle, history
 * events still persist, and workflows still reach a terminal state
 * using only durable dispatch rows.
 */
final class V2SchedulerDegradedModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.current', 'build-degraded');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-degraded']);
    }

    /**
     * Contract: "Wake backend lost some signals — a subset of
     * published signals never reaches subscribers. Pollers that
     * missed the signal re-poll on their configured interval. No
     * work is lost."
     *
     * A deployment whose wake layer silently drops every signal
     * remains correct; the workflow still runs to completion using
     * durable dispatch state alone.
     */
    public function testScenarioWakeBackendLostSignals(): void
    {
        $this->swapWakeStore(NullLongPollWakeStore::class);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'degraded-dropped');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'degraded-dropped')
            ->orderBy('run_number')
            ->firstOrFail();

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertSame([], WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('status', TaskStatus::Ready->value)
            ->pluck('id')
            ->all());
        $this->assertGreaterThan(
            0,
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->count(),
            'Durable history must accrue even when no wake signal was delivered.',
        );
    }

    /**
     * Contract: "Wake backend unreachable — signal() calls surface
     * as exceptions or log lines at the publisher; pollers continue
     * to discover work on the next configured poll."
     *
     * The publisher-side failure is logged via report() but MUST
     * NOT cause the durable task write, history write, or workflow
     * progression to fail.
     */
    public function testScenarioWakeBackendUnreachable(): void
    {
        $store = $this->swapWakeStore(ThrowingLongPollWakeStore::class);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'degraded-unreachable');
        $workflow->start('Taylor');

        $this->drainReadyTasks();

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'degraded-unreachable')
            ->orderBy('run_number')
            ->firstOrFail();

        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertGreaterThan(
            0,
            $store->signalAttempts,
            'Throwing wake store should have been asked to signal at least once.',
        );
    }

    /**
     * Contract: "A node MUST NOT refuse to make progress because
     * the acceleration layer is unavailable."
     *
     * Pins the publisher-resilience invariant directly: a raising
     * wake backend does not break workflow start, and the durable
     * workflow task is committed in the normal path.
     */
    public function testScenarioCacheBackendPermanentlyUnavailableDoesNotBlockTaskCreation(): void
    {
        $store = $this->swapWakeStore(ThrowingLongPollWakeStore::class);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'degraded-blocked');

        try {
            $workflow->start('Taylor');
        } catch (RuntimeException $throwable) {
            $this->fail('Task creation must not fail when the wake backend raises: ' . $throwable->getMessage());
        }

        $this->assertSame(
            1,
            WorkflowTask::query()
                ->where('task_type', TaskType::Workflow->value)
                ->count(),
            'Exactly one ready workflow task should have been durably created despite the wake backend raising.',
        );
        $this->assertGreaterThan(0, $store->signalAttempts);
    }

    /**
     * Contract: "Wake backend partitioned — different nodes see
     * different version snapshots. A node that missed a signal
     * re-polls on its configured interval and still finds every
     * eligible task."
     *
     * A node that sees zero advancing signals (effectively
     * partitioned away from the publisher) still discovers ready
     * work through durable dispatch state — workflow_tasks rows.
     */
    public function testScenarioWakeBackendPartitionedFallsBackToDurableDispatchPoll(): void
    {
        $this->swapWakeStore(NullLongPollWakeStore::class);

        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'degraded-partitioned');
        $workflow->start('Taylor');

        $readyCountBeforeDrain = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->count();

        $this->assertGreaterThan(
            0,
            $readyCountBeforeDrain,
            'Durable dispatch state must expose a ready task to direct polling even when no wake signal was delivered.',
        );

        $this->drainReadyTasks();

        $this->assertSame(0, WorkflowTask::query() ->where('status', TaskStatus::Ready->value) ->count());
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

    /**
     * @template TStore of CacheLongPollWakeStore
     *
     * @param  class-string<TStore>  $storeClass
     * @return TStore
     */
    private function swapWakeStore(string $storeClass): CacheLongPollWakeStore
    {
        $store = $this->app->make($storeClass);
        $this->app->instance(LongPollWakeStore::class, $store);
        $this->app->instance(CacheLongPollWakeStore::class, $store);

        return $store;
    }
}
