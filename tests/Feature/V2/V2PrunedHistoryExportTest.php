<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestThrowAfterGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;
use Workflow\V2\WorkflowStub;

/**
 * Retention cleanup deletes a terminal run's history but keeps the run row.
 * An export taken afterwards must not look like complete history, and must
 * not share a dedupe key with the full export taken before cleanup (#856).
 */
final class V2PrunedHistoryExportTest extends TestCase
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

    /**
     * @return iterable<string, array{class-string, RunStatus}>
     */
    public static function terminalRuns(): iterable
    {
        yield 'completed run' => [TestGreetingWorkflow::class, RunStatus::Completed];
        yield 'failed run' => [TestThrowAfterGreetingWorkflow::class, RunStatus::Failed];
    }

    #[DataProvider('terminalRuns')]
    public function testAPrunedRunIsNotExportedAsCompleteHistory(string $workflowClass, RunStatus $status): void
    {
        $run = $this->terminalRun($workflowClass, $status);

        $before = HistoryExport::forRun($run->fresh());

        $this->assertTrue($before['history_complete']);
        $this->assertSame('retained', $before['workflow']['details_state']);
        $this->assertNull($before['workflow']['details_pruned_at']);
        $this->assertNotSame([], $before['history_events']);
        $this->assertSame(
            implode(':', [$run->id, $run->last_history_sequence, $run->last_progress_at->toJSON()]),
            $before['dedupe_key'],
            'a run that was never pruned keeps its existing dedupe key',
        );

        WorkflowRunRetentionCleanup::pruneRun($run->id);
        $pruned = WorkflowRun::query()->findOrFail($run->id);
        $this->assertNotNull($pruned->details_pruned_at);
        $prunedAt = $pruned->details_pruned_at->toJSON();

        $after = HistoryExport::forRun($pruned);

        $this->assertFalse($after['history_complete']);
        $this->assertSame($status->value, $after['workflow']['status'], 'the terminal status is preserved');
        $this->assertSame('pruned', $after['workflow']['details_state']);
        $this->assertSame($prunedAt, $after['workflow']['details_pruned_at']);
        $this->assertSame([], $after['history_events']);
        $this->assertSame(
            $before['workflow']['last_history_sequence'],
            $after['workflow']['last_history_sequence'],
            'pruning does not change the run sequence the old key was built from',
        );
        $this->assertNotSame($before['dedupe_key'], $after['dedupe_key']);
        $this->assertSame($before['dedupe_key'] . ':details-pruned:' . $prunedAt, $after['dedupe_key']);
        $this->assertNotSame($before['integrity']['checksum'], $after['integrity']['checksum']);

        $import = EmbeddedV2HistoryImport::import($after, [
            'dry_run' => true,
        ]);
        $this->assertSame('rejected', $import['status']);
        $this->assertContains(
            'workflow.terminal_history_incomplete',
            array_column($import['eligibility']['errors'], 'rule'),
            json_encode($import['eligibility'], JSON_UNESCAPED_SLASHES),
        );

        $path = tempnam(sys_get_temp_dir(), 'pruned-export-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($after, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        $exitCode = Artisan::call('workflow:v2:replay-verify', [
            'bundle' => $path,
        ]);
        $output = Artisan::output();

        $this->assertNotSame(0, $exitCode, $output);
        $this->assertStringContainsString('history.details_pruned', $output);
    }

    public function testRepeatedPruneKeepsThePostPruneExportStable(): void
    {
        $run = $this->terminalRun(TestGreetingWorkflow::class, RunStatus::Completed);

        WorkflowRunRetentionCleanup::pruneRun($run->id);
        $first = HistoryExport::forRun(WorkflowRun::query()->findOrFail($run->id));

        WorkflowRunRetentionCleanup::pruneRun($run->id);
        $second = HistoryExport::forRun(WorkflowRun::query()->findOrFail($run->id));

        $this->assertSame($first['dedupe_key'], $second['dedupe_key']);
        $this->assertSame($first['workflow']['details_pruned_at'], $second['workflow']['details_pruned_at']);
    }

    /**
     * @param class-string $workflowClass
     */
    private function terminalRun(string $workflowClass, RunStatus $status): WorkflowRun
    {
        $workflow = WorkflowStub::make($workflowClass, 'pruned-export-' . strtolower($status->value));
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame($status, $run->status);
        $this->assertNull($run->details_pruned_at);

        return $run;
    }

    private function runReadyTaskForRun(string $runId, TaskType $taskType): void
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', $taskType->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if ($task === null) {
            $this->fail(sprintf('Expected a ready %s task for run %s.', $taskType->value, $runId));
        }

        $job = match ($task->task_type) {
            TaskType::Workflow => new RunWorkflowTask($task->id),
            TaskType::Activity => new RunActivityTask($task->id),
            TaskType::Timer => new RunTimerTask($task->id),
        };

        $this->app->call([$job, 'handle']);
    }
}
