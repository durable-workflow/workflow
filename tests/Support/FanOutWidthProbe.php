<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fixtures\V2\TestFanOutPayloadActivityWorkflow;
use Tests\Fixtures\V2\TestFanOutPayloadChildWorkflow;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * Runs one all() fan-out of activities or child workflows through the real
 * queue jobs, one task at a time, and measures the root run's workflow tasks
 * (#1203, #1231).
 *
 * The scheduling task records every member and publishes its work; the final
 * task replays every member's outcome and closes the run. Both run under the
 * worker's job timeout, so their cost per member must stay roughly constant.
 * Member tasks run between them and are reported separately. A probe may
 * skip their per-member summary refresh, which still rebuilds the run's
 * summary from the whole run, and refresh every summary once instead.
 */
final class FanOutWidthProbe
{
    private int $queries = 0;

    private float $sqlMilliseconds = 0.0;

    private bool $listening = false;

    public function __construct(
        private readonly Application $app,
    ) {
    }

    /**
     * @param 'activity'|'child' $kind
     * @return array{
     *     width: int,
     *     scheduling: array{seconds: float, queries: int, sql_seconds: float},
     *     members: array{seconds: float, queries: int, tasks: int},
     *     final: array{seconds: float, queries: int, sql_seconds: float},
     *     workflow_task_seconds: float,
     *     workflow_task_queries: int,
     *     output: mixed
     * }
     */
    public function run(
        string $kind,
        int $width,
        int $payloadBytes,
        string $instanceId,
        bool $refreshMemberProjections = true,
    ): array {
        $this->listen();

        $workflow = WorkflowStub::make(
            $kind === 'child' ? TestFanOutPayloadChildWorkflow::class : TestFanOutPayloadActivityWorkflow::class,
            $instanceId,
        );
        $workflow->start($width, $payloadBytes);
        $rootRunId = $workflow->runId();

        $scheduling = $this->measure(fn () => $this->runOnly($this->readyRootWorkflowTask($rootRunId)));

        $memberTasks = 0;
        $members = $this->measure(function () use ($rootRunId, $refreshMemberProjections, &$memberTasks): void {
            $role = $this->app->make(HistoryProjectionRole::class);

            if (! $refreshMemberProjections) {
                $this->app->instance(HistoryProjectionRole::class, new MemberPhaseProjectionRole());
            }

            try {
                while (($task = $this->nextReadyTask($rootRunId)) !== null) {
                    $this->runOnly($task);
                    $memberTasks++;
                }
            } finally {
                $this->app->instance(HistoryProjectionRole::class, $role);
            }

            if (! $refreshMemberProjections) {
                // Bring every summary current before the measured final task.
                WorkflowRun::query()
                    ->orderByRaw('case when id = ? then 1 else 0 end', [$rootRunId])
                    ->orderBy('id')
                    ->each(static fn (WorkflowRun $run) => $role->projectRun($run));
            }
        });

        $final = $this->measure(fn () => $this->runOnly($this->readyRootWorkflowTask($rootRunId)));

        if (WorkflowTask::query()->where('status', TaskStatus::Ready->value)->exists()) {
            throw new RuntimeException('The fan-out left ready work after its final workflow task.');
        }

        $workflow->refresh();

        if (! $workflow->completed()) {
            throw new RuntimeException(sprintf('The %d-member %s fan-out did not complete.', $width, $kind));
        }

        return [
            'width' => $width,
            'scheduling' => $scheduling,
            'members' => [
                'seconds' => $members['seconds'],
                'queries' => $members['queries'],
                'tasks' => $memberTasks,
            ],
            'final' => $final,
            'workflow_task_seconds' => $scheduling['seconds'] + $final['seconds'],
            'workflow_task_queries' => $scheduling['queries'] + $final['queries'],
            'output' => $workflow->output(),
        ];
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;
        DB::listen(function (QueryExecuted $query): void {
            $this->queries++;
            $this->sqlMilliseconds += $query->time;
        });
    }

    /**
     * @return array{seconds: float, queries: int, sql_seconds: float}
     */
    private function measure(callable $operation): array
    {
        $queries = $this->queries;
        $sqlMilliseconds = $this->sqlMilliseconds;
        $started = hrtime(true);
        $operation();

        return [
            'seconds' => round((hrtime(true) - $started) / 1_000_000_000, 3),
            'queries' => $this->queries - $queries,
            'sql_seconds' => round(($this->sqlMilliseconds - $sqlMilliseconds) / 1000, 3),
        ];
    }

    private function readyRootWorkflowTask(string $runId): WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if (! $task instanceof WorkflowTask) {
            throw new RuntimeException(sprintf('Run %s has no ready workflow task.', $runId));
        }

        return $task;
    }

    /**
     * The next ready member task: anything except the root run's workflow task.
     */
    private function nextReadyTask(string $rootRunId): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('status', TaskStatus::Ready->value)
            ->where(static function ($query) use ($rootRunId): void {
                $query->where('workflow_run_id', '!=', $rootRunId)
                    ->orWhere('task_type', '!=', TaskType::Workflow->value);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return $task;
    }

    private function runOnly(WorkflowTask $task): void
    {
        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
