<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkflowContinuationCache;

/** A real child PHPUnit application owns the daemon and its process-local cache. */
final class ContinuationProcessWorkerTest extends TestCase
{
    public function testDaemon(): void
    {
        if (! is_string(getenv('DWF_PROCESS_DATABASE')) || getenv('DWF_PROCESS_DATABASE') === '') {
            $this->markTestSkipped('Invoked by EmbeddedContinuationProcessTest with its existing database.');
        }
        $checkpoint = getenv('DWF_PROCESS_CHECKPOINT');
        if (is_string($checkpoint) && $checkpoint !== '') {
            WorkflowTask::updated(static function (WorkflowTask $task) use ($checkpoint): void {
                if ($task->task_type !== TaskType::Workflow || $task->status !== TaskStatus::Leased
                    || ! WorkflowHistoryEvent::query()->where('workflow_run_id', $task->workflow_run_id)
                        ->where('event_type', HistoryEventType::TimerFired->value)->exists()) {
                    return;
                }
                $database = $task->getConnection();
                $database->afterCommit(static function () use ($task, $database, $checkpoint): void {
                    $snapshot = [
                        'pid' => getmypid(),
                        'task_id' => $task->id,
                        'run_id' => $task->workflow_run_id,
                        'lease' => $task->getRawOriginal('lease_expires_at'),
                        'transaction_level' => $database->transactionLevel(),
                        'pdo_transaction' => $database->getPdo()
                            ->inTransaction(),
                        'cache' => app(WorkflowContinuationCache::class)->snapshot(),
                    ];
                    file_put_contents($checkpoint . '.pending', json_encode($snapshot, JSON_THROW_ON_ERROR));
                    rename($checkpoint . '.pending', $checkpoint);
                    // Pause after a committed claim, before that task can consume the cached frame.
                    while (true) {
                        usleep(100_000);
                    }
                });
            });
        }
        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--sleep' => 1,
            '--max-jobs' => (int) getenv('DWF_PROCESS_MAX_JOBS'),
            '--timeout' => 15,
            '--memory' => 256,
        ]), Artisan::output());
    }

    protected function getPackageProviders($app): array
    {
        return ContinuationProcessEnvironment::providers();
    }

    protected function defineEnvironment($app): void
    {
        $database = getenv('DWF_PROCESS_DATABASE');
        if (is_string($database) && $database !== '') {
            ContinuationProcessEnvironment::configure($app, $database, getenv('DWF_PROCESS_ENABLED') === '1');
        }
    }
}
