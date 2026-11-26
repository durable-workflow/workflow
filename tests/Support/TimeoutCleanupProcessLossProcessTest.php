<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Orchestra\Testbench\TestCase;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;

/**
 * A worker process for V2TimeoutCleanupTest's worker-loss proof (#863). The
 * crash phase runs the run's cleanup tasks and dies by SIGKILL inside the
 * workflow task that records the compensation result, before it commits. The
 * resume phase is a fresh process with no in-memory state: like a replacement
 * queue worker, it lets the production watchdog repair the abandoned lease and
 * runs the run from persisted history until it closes.
 */
final class TimeoutCleanupProcessLossProcessTest extends TestCase
{
    public function testRunTheTimeoutCleanupWorker(): void
    {
        $input = $this->input();
        Carbon::setTestNow($input['clock']);
        Queue::fake();
        $runId = $input['run_id'];

        if ($input['phase'] === 'crash') {
            WorkflowHistoryEvent::created(static function (WorkflowHistoryEvent $event) use ($input, $runId): void {
                if ($event->workflow_run_id !== $runId || $event->event_type !== HistoryEventType::MemoUpserted) {
                    return;
                }
                $task = WorkflowTask::query()->findOrFail($event->workflow_task_id);
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'task_id' => $task->id,
                    'lease_owner' => $task->lease_owner,
                    'lease_expires_at' => $task->lease_expires_at?->toISOString(),
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));
                posix_kill(getmypid(), SIGKILL);
                throw new LogicException('The worker-loss signal did not terminate this process.');
            });
        }

        for ($step = 0; $step < 20; ++$step) {
            if ($input['phase'] === 'resume' && WorkflowRun::query()->findOrFail($runId)->status->isTerminal()) {
                file_put_contents($input['report'], json_encode([
                    'pid' => getmypid(),
                    'clock' => now()
                        ->toISOString(),
                ], JSON_THROW_ON_ERROR));

                return;
            }

            $task = $this->readyTask($runId);
            if ($task === null) {
                TaskWatchdog::runPass(runIds: [$runId]);
                $task = $this->readyTask($runId);
            }
            if ($task === null) {
                continue;
            }
            $job = $task->task_type === TaskType::Activity
                ? new RunActivityTask($task->id)
                : new RunWorkflowTask($task->id);
            $this->app->call([$job, 'handle']);
        }

        $this->fail('The timeout cleanup worker did not reach its cut or the run\'s close.');
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $input = $this->input();
        $app['config']->set([
            'workflows.v2.task_dispatch_mode' => 'queue',
            'database.default' => 'worker_loss',
            'database.connections.worker_loss' => $input['database'],
            'database.redis' => $input['redis'],
            'workflows.storage.connection' => 'worker_loss',
            'queue.default' => 'redis',
            'cache' => $input['cache'],
            'logging.default' => 'null',
        ]);
    }

    private function readyTask(string $runId): ?WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready->value)
            ->where('available_at', '<=', Carbon::now()->format('Y-m-d H:i:s.u'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private function input(): array
    {
        $path = getenv('DWF_TIMEOUT_CLEANUP_PROCESS_INPUT');
        if (! is_string($path) || $path === '') {
            $this->markTestSkipped('Invoked only by V2TimeoutCleanupTest\'s worker-loss proof.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
