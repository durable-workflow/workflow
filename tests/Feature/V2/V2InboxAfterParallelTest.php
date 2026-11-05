<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestInboxAfterParallelWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\MessageChannel;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\MessageService;
use Workflow\V2\WorkflowStub;

final class V2InboxAfterParallelTest extends TestCase
{
    #[DataProvider('executionModes')]
    public function testPendingReceiveAfterActivitiesSurvivesColdReplay(bool $parallel, int $admission): void
    {
        Queue::fake();
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        $stub = WorkflowStub::make(TestInboxAfterParallelWorkflow::class, ($parallel ? 'after-all-' : 'after-sequential-') . $admission);
        $stub->start($parallel);
        $this->drain();
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $send = static fn () => (new MessageService())->sendMessage($run, MessageChannel::WorkflowMessage, $run->workflow_instance_id, 'j1', 'chat');
        if ($admission === 0) {
            $send();
        }
        $stub->signal('go');
        if ($admission === 2) {
            for ($turn = 0; $turn < 20; $turn++) {
                if (ActivityExecution::query()->where('workflow_run_id', $run->id)->where('status', 'completed')->count() === 2) {
                    break;
                }
                self::assertTrue($this->runNextTask(), 'Two real activities must complete before admission.');
            }
        }
        if ($admission !== 0) {
            $send();
        }
        $this->drain();
        self::assertSame(2, ActivityExecution::query()->where('workflow_run_id', $run->id)->where('status', 'completed')->count());
        $first = $stub->refresh()->query('received');
        $before = $this->rows();
        $reader = new Process([PHP_BINARY, __DIR__ . '/../../Fixtures/V2/inbox_after_parallel_cold_read.php'], dirname(__DIR__, 3), [
            'INBOX_PARALLEL_RUN_ID' => $run->id,
        ]);
        $reader->setTimeout(30);
        $reader->mustRun();
        $cold = json_decode($reader->getOutput(), true, 512, JSON_THROW_ON_ERROR)['received'];
        self::assertSame($before, $this->rows(), 'A fresh-process query cannot change any durable row.');
        $stub->signal('done');
        $this->drain();
        self::assertTrue($stub->refresh()->completed());
        self::assertSame(['Hello, first!', 'Hello, second!'], $stub->output()['activities']);
        self::assertSame(['j1', 'j1', 'j1'], [$first, $cold, $stub->output()['received']]);
    }

    public static function executionModes(): iterable
    {
        foreach ([0 => 'before signal', 1 => 'before activities', 2 => 'after activities'] as $admission => $name) {
            yield 'sequential ' . $name => [false, $admission];
            yield 'parallel ' . $name => [true, $admission];
        }
    }

    private function drain(): void
    {
        for ($turn = 0; $turn < 50; $turn++) {
            if (! $this->runNextTask()) {
                return;
            }
        }
        self::fail('The bounded source fixture did not reach a wait.');
    }

    private function runNextTask(): bool
    {
        $task = WorkflowTask::query()->where('status', TaskStatus::Ready->value)->orderBy('created_at')->first();
        if ($task === null || ($task->available_at !== null && $task->available_at->isFuture())) {
            return false;
        }
        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };
        $this->app->call([$job, 'handle']);
        return true;
    }

    private function rows(): array
    {
        $rows = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $values = DB::table($table)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($values);
            $rows[$table] = $values;
        }
        ksort($rows);
        return $rows;
    }
}
