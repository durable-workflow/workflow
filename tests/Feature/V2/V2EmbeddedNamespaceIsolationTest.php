<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\Fixtures\V2\TestTimerWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\NamespaceScope;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * Two embedded apps share one database and the same connection and queue
 * names, separated only by namespace (#889).
 */
final class V2EmbeddedNamespaceIsolationTest extends TestCase
{
    private const CONNECTION = 'redis';

    private const QUEUE = 'shared-queue';

    protected function setUp(): void
    {
        parent::setUp();

        // Each pass here plays one app; background workers would be a third.
        self::stopWorkers();
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00'));
        Queue::fake();
        config()
            ->set([
                'workflows.v2.connection' => self::CONNECTION,
                'workflows.v2.queue' => self::QUEUE,
                'workflows.v2.all_namespaces' => false,
            ]);
        $this->asApp(null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testWatchdogRepairsAndRedispatchesOnlyItsOwnNamespace(): void
    {
        $a = $this->startIn('app-a');
        $b = $this->startIn('app-b');
        $legacyB = $this->startIn('app-b');
        // Older rows can lack a task namespace; the run's namespace owns them.
        $this->workflowTask($legacyB)
            ->forceFill([
                'namespace' => null,
            ])->save();

        $taskA = $this->workflowTask($a);
        $taskB = $this->workflowTask($b);
        $taskLegacyB = $this->workflowTask($legacyB);
        foreach ([$taskA, $taskB, $taskLegacyB] as $task) {
            $this->assertSame(self::CONNECTION, $task->connection);
            $this->assertSame(self::QUEUE, $task->queue);
        }

        Carbon::setTestNow(now()->addMinute());
        $foreignBefore = [$taskB->fresh()->getAttributes(), $taskLegacyB->fresh()->getAttributes()];

        Queue::fake();
        $this->asApp('app-a');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['repaired_existing_tasks']);
        $this->assertSame([$taskA->id], $this->pushedWorkflowTaskIds());
        $this->assertSame($foreignBefore, [$taskB->fresh()->getAttributes(), $taskLegacyB->fresh()->getAttributes()]);
        $this->assertSame(1, $taskA->fresh()->repair_count);

        // The queue worker's Looping wake is the same scoped pass.
        Queue::fake();
        Cache::forget(TaskWatchdog::LOOP_THROTTLE_KEY);
        $this->asApp('app-b');
        app(MatchingRole::class)->wake(self::CONNECTION, self::QUEUE);

        $this->assertEqualsCanonicalizing([$taskB->id, $taskLegacyB->id], $this->pushedWorkflowTaskIds());
        $this->assertSame(1, $taskA->fresh()->repair_count);
    }

    public function testMissingTaskRepairStaysInsideTheNamespace(): void
    {
        $a = $this->startIn('app-a');
        $b = $this->startIn('app-b');

        foreach ([$a, $b] as $stub) {
            $this->workflowTask($stub)
                ->delete();
            $this->project($stub->runId());
            $this->assertSame('repair_needed', WorkflowRunSummary::query()->findOrFail($stub->runId())->liveness_state);
        }

        $this->asApp('app-a');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['repaired_missing_tasks'], json_encode($report));
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $a->runId())->count());
        $this->assertSame(0, WorkflowTask::query()->where('workflow_run_id', $b->runId())->count());

        $this->asApp('app-b');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['repaired_missing_tasks'], json_encode($report));
        $this->assertSame(1, WorkflowTask::query()->where('workflow_run_id', $b->runId())->count());
    }

    public function testActivityTimeoutsAreEnforcedOnlyInsideTheNamespace(): void
    {
        $a = $this->startIn('app-a');
        $b = $this->startIn('app-b');
        $this->runWorkflowTaskAs('app-a', $a);
        $this->runWorkflowTaskAs('app-b', $b);

        $executionA = $this->activityExecution($a);
        $executionB = $this->activityExecution($b);
        foreach ([$executionA, $executionB] as $execution) {
            $this->assertSame(self::QUEUE, $execution->queue);
            $execution->forceFill([
                'schedule_deadline_at' => now()
                    ->subSecond(),
            ])->save();
        }

        $this->asApp('app-a');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['activity_timeout_candidates']);
        $this->assertSame(ActivityStatus::Failed, $executionA->fresh()->status);
        $this->assertSame(ActivityStatus::Pending, $executionB->fresh()->status);

        $this->asApp('app-b');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['activity_timeout_candidates']);
        $this->assertSame(ActivityStatus::Failed, $executionB->fresh()->status);
    }

    public function testRunDeadlinesAreEnforcedOnlyInsideTheNamespace(): void
    {
        $a = $this->startIn('app-a');
        $b = $this->startIn('app-b');
        $this->runWorkflowTaskAs('app-a', $a);
        $this->runWorkflowTaskAs('app-b', $b);

        foreach ([$a, $b] as $stub) {
            WorkflowRun::query()->findOrFail($stub->runId())->forceFill([
                'run_deadline_at' => now()
                    ->subSecond(),
            ])->save();
        }

        $this->asApp('app-a');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['deadline_expired_candidates']);
        $this->assertSame(1, $this->openWorkflowTasks($a));
        $this->assertSame(0, $this->openWorkflowTasks($b));

        $this->asApp('app-b');
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(1, $report['deadline_expired_candidates']);
        $this->assertSame(1, $this->openWorkflowTasks($b));
    }

    public function testQueuedJobsForAnotherNamespaceAreRefusedBeforeAnyEffect(): void
    {
        $a = $this->startIn('app-a');
        $taskA = $this->workflowTask($a);
        $historyBefore = $this->historyCount($a);
        $before = $taskA->fresh()
            ->getAttributes();

        // A job serialized before namespace scoping existed carries only the
        // task id. It is checked against the current app scope and the task.
        $legacyJob = unserialize(serialize(new RunWorkflowTask($taskA->id)));

        $this->asApp('app-b');
        app()
            ->call([$legacyJob, 'handle']);
        app()
            ->call([new RunWorkflowTask($taskA->id), 'handle']);

        $this->assertSame($before, $taskA->fresh()->getAttributes());
        $this->assertSame($historyBefore, $this->historyCount($a));

        // Positive control: the owning app executes the same job.
        $this->runWorkflowTaskAs('app-a', $a);
        $this->assertSame(TaskStatus::Completed, $taskA->fresh()->status);

        $activityTask = WorkflowTask::query()
            ->where('workflow_run_id', $a->runId())
            ->where('task_type', TaskType::Activity->value)
            ->firstOrFail();
        $activityBefore = $activityTask->getAttributes();

        $this->asApp('app-b');
        app()
            ->call([new RunActivityTask($activityTask->id), 'handle']);

        $this->assertSame($activityBefore, $activityTask->fresh()->getAttributes());
        $this->assertSame(0, $this->activityExecution($a)->attempt_count);

        $this->asApp('app-a');
        app()
            ->call([new RunActivityTask($activityTask->id), 'handle']);

        $this->assertSame(ActivityStatus::Completed, $this->activityExecution($a)->status);
    }

    public function testQueuedTimerJobForAnotherNamespaceIsRefused(): void
    {
        $this->asApp('app-a');
        $stub = WorkflowStub::make(TestTimerWorkflow::class);
        $stub->start(5);
        $this->runWorkflowTaskAs('app-a', $stub);
        $timerTask = WorkflowTask::query()
            ->where('workflow_run_id', $stub->runId())
            ->where('task_type', TaskType::Timer->value)
            ->firstOrFail();
        Carbon::setTestNow(now()->addMinute());
        $before = $timerTask->getAttributes();
        $historyBefore = $this->historyCount($stub);

        $this->asApp('app-b');
        app()
            ->call([new RunTimerTask($timerTask->id), 'handle']);

        $this->assertSame($before, $timerTask->fresh()->getAttributes());
        $this->assertSame($historyBefore, $this->historyCount($stub));

        $this->asApp('app-a');
        app()
            ->call([new RunTimerTask($timerTask->id), 'handle']);

        $this->assertSame(TaskStatus::Completed, $timerTask->fresh()->status);
    }

    public function testScheduleTickFiresOnlyTheNamespacesSchedules(): void
    {
        $schedules = [];
        foreach (['app-a', 'app-b'] as $namespace) {
            $this->asApp($namespace);
            $schedules[$namespace] = ScheduleManager::create(
                scheduleId: 'shared-schedule',
                workflowClass: TestScheduledWorkflow::class,
                cronExpression: '0 * * * *',
                overlapPolicy: ScheduleOverlapPolicy::AllowAll,
            );
            $schedules[$namespace]->forceFill([
                'next_fire_at' => now()
                    ->subMinute(),
            ])->save();
        }
        $foreignBefore = $schedules['app-b']->fresh()
            ->getAttributes();

        $this->asApp('app-a');
        $ticked = ScheduleManager::tick();

        $this->assertSame(['triggered'], array_column($ticked, 'outcome'));
        $this->assertSame(1, (int) $schedules['app-a']->fresh()->fires_count);
        $this->assertSame($foreignBefore, $schedules['app-b']->fresh()->getAttributes());

        $this->asApp('app-b');
        $this->assertSame(['triggered'], array_column(ScheduleManager::tick(), 'outcome'));
        $this->assertSame(1, (int) $schedules['app-b']->fresh()->fires_count);
    }

    public function testAbsentAllowlistServesExactlyTheConfiguredSingleNamespace(): void
    {
        $default = $this->startIn(null);
        $named = $this->startIn('app-a');
        Carbon::setTestNow(now()->addMinute());

        Queue::fake();
        $this->asApp(null);
        $this->assertSame(['default'], NamespaceScope::configured()->namespaces());
        TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);
        $this->assertSame([$this->workflowTask($default)->id], $this->pushedWorkflowTaskIds());

        Queue::fake();
        $this->asApp('app-a');
        $this->assertSame(['app-a'], NamespaceScope::configured()->namespaces());
        TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);
        $this->assertSame([$this->workflowTask($named)->id], $this->pushedWorkflowTaskIds());
    }

    #[DataProvider('multiNamespaceLists')]
    public function testExplicitListServesEachListedNamespace(mixed $namespaces): void
    {
        $a = $this->startIn('app-a');
        $b = $this->startIn('app-b');
        $c = $this->startIn('app-c');
        Carbon::setTestNow(now()->addMinute());

        Queue::fake();
        $this->asApp('app-a', $namespaces);
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(2, $report['repaired_existing_tasks'], json_encode($report));
        $this->assertEqualsCanonicalizing(
            [$this->workflowTask($a)->id, $this->workflowTask($b)->id],
            $this->pushedWorkflowTaskIds(),
        );
        $this->assertSame(0, $this->workflowTask($c)->repair_count);
    }

    public static function multiNamespaceLists(): iterable
    {
        yield 'comma-separated env value' => [' app-a, app-b '];
        yield 'config list' => [['app-b', 'app-a']];
    }

    #[DataProvider('emptyLists')]
    public function testExplicitEmptyListServesNoNamespace(mixed $namespaces): void
    {
        $default = $this->startIn(null);
        $a = $this->startIn('app-a');
        Carbon::setTestNow(now()->addMinute());
        $taskA = $this->workflowTask($a);
        $before = $taskA->fresh()
            ->getAttributes();

        Queue::fake();
        $this->asApp('app-a', $namespaces);
        $this->assertTrue(NamespaceScope::configured()->isEmpty());
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertSame(0, $report['selected_total_candidates']);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->workflowTask($default)->repair_count);

        app()
            ->call([new RunWorkflowTask($taskA->id), 'handle']);
        $this->assertSame($before, $taskA->fresh()->getAttributes());
    }

    public static function emptyLists(): iterable
    {
        yield 'empty env value' => [''];
        yield 'empty config list' => [[]];
    }

    public function testEveryNamespaceRequiresTheSeparateAdministrativeOptIn(): void
    {
        $a = $this->startIn('app-a');
        $b = $this->startIn('app-b');
        Carbon::setTestNow(now()->addMinute());

        $this->asApp('app-a', '*');
        try {
            NamespaceScope::configured();
            $this->fail('A "*" allowlist entry must not grant every namespace.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('all_namespaces', $exception->getMessage());
        }

        Queue::fake();
        $this->asApp('app-a');
        config()
            ->set('workflows.v2.all_namespaces', true);
        $report = TaskWatchdog::runPass(self::CONNECTION, self::QUEUE);

        $this->assertTrue(NamespaceScope::configured()->isAll());
        $this->assertSame(2, $report['repaired_existing_tasks'], json_encode($report));
        $this->assertEqualsCanonicalizing(
            [$this->workflowTask($a)->id, $this->workflowTask($b)->id],
            $this->pushedWorkflowTaskIds(),
        );
    }

    private function asApp(?string $namespace, mixed $namespaces = null): void
    {
        config()->set([
            'workflows.v2.namespace' => $namespace,
            'workflows.v2.namespaces' => $namespaces,
        ]);
    }

    private function startIn(?string $namespace): WorkflowStub
    {
        $this->asApp($namespace);
        $stub = WorkflowStub::make(TestGreetingWorkflow::class);
        $stub->start('Taylor');

        return $stub;
    }

    private function runWorkflowTaskAs(string $namespace, WorkflowStub $stub): void
    {
        $this->asApp($namespace);
        app()
            ->call([new RunWorkflowTask($this->workflowTask($stub)->id), 'handle']);
    }

    private function workflowTask(WorkflowStub $stub): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $stub->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function activityExecution(WorkflowStub $stub): ActivityExecution
    {
        return ActivityExecution::query()
            ->where('workflow_run_id', $stub->runId())
            ->firstOrFail();
    }

    private function openWorkflowTasks(WorkflowStub $stub): int
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $stub->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->count();
    }

    private function historyCount(WorkflowStub $stub): int
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $stub->runId())
            ->count();
    }

    private function project(string $runId): void
    {
        app(HistoryProjectionRole::class)->projectRun(
            WorkflowRun::query()->findOrFail($runId)->fresh(
                ['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents']
            )
        );
    }

    /**
     * @return list<string>
     */
    private function pushedWorkflowTaskIds(): array
    {
        return Queue::pushed(RunWorkflowTask::class)
            ->map(static fn (RunWorkflowTask $job): string => $job->taskId)
            ->values()
            ->all();
    }
}
