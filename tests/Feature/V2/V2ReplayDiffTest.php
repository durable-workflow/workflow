<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestFailingWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestHandleFailureThenThrowWorkflow;
use Tests\Fixtures\V2\TestImmediateFailureWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\ReplayDiff;
use Workflow\V2\WorkflowStub;

final class V2ReplayDiffTest extends TestCase
{
    public function testReplayDiffReportsCleanReplayForFreshBundle(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'replay-diff-clean');
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $run = WorkflowRun::query()->findOrFail($runId);
        $bundle = HistoryExport::forRun($run);

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::REPORT_SCHEMA, $report['schema']);
        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_NONE, $report['reason']);
        $this->assertSame($runId, $report['workflow']['workflow_run_id']);
        $this->assertNull($report['divergence']);
        $this->assertNull($report['error']);
        $this->assertIsInt($report['replay']['sequence']);

        $unloadedRun = $run->fresh();
        $unloadedReport = (new ReplayDiff())->diffRun($unloadedRun);
        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $unloadedReport['status']);
        $this->assertSame($runId, $unloadedReport['workflow']['workflow_run_id']);
        $this->assertNull($unloadedReport['workflow']['history_event_count']);

        $unloadedRun->load('historyEvents');
        $loadedReport = (new ReplayDiff())->diffRun($unloadedRun);
        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $loadedReport['status']);
        $this->assertSame($unloadedRun->historyEvents->count(), $loadedReport['workflow']['history_event_count']);
        $this->assertSame($unloadedRun->last_history_sequence, $loadedReport['workflow']['last_history_sequence']);

        $path = tempnam(sys_get_temp_dir(), 'replay-verify-command-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($bundle, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        $exitCode = Artisan::call('workflow:v2:replay-verify', [
            'bundle' => $path,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Replay verification: OK', $output);
        $this->assertStringContainsString('Replay: status=replayed reason=none', $output);
    }

    public function testReplayDiffSurfacesShapeMismatchAsDrift(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'replay-diff-drifted');
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        // Rewrite the activity-shaped events at workflow sequence 1 to
        // timer-shaped events so the current workflow code (which still
        // yields an activity) drifts from the recorded history.
        foreach ($bundle['history_events'] as &$event) {
            $type = $event['type'] ?? null;

            if ($type === 'ActivityScheduled' || $type === 'ActivityStarted') {
                $event['type'] = 'TimerScheduled';
                $event['payload']['timer_kind'] = 'durable_timer';
            } elseif ($type === 'ActivityCompleted' || $type === 'ActivityFailed') {
                $event['type'] = 'TimerFired';
                $event['payload']['timer_kind'] = 'durable_timer';
            }
        }
        unset($event);

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::STATUS_DRIFTED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_SHAPE_MISMATCH, $report['reason']);
        $this->assertIsArray($report['divergence']);
        $this->assertSame(1, $report['divergence']['workflow_sequence']);
        $this->assertContains('TimerFired', $report['divergence']['recorded_event_types']);
        $this->assertNull($report['error']);
    }

    public function testReplayDiffSurfacesSameShapeActivityTypeDrift(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'replay-diff-activity-type-drift');
        $workflow->start('Ada');
        $runId = $workflow->runId();
        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        foreach ($bundle['history_events'] as &$event) {
            if (! in_array($event['type'] ?? null, [
                'ActivityScheduled',
                'ActivityStarted',
                'ActivityCompleted',
                'ActivityFailed',
            ], true)) {
                continue;
            }

            $event['payload']['activity_type'] = 'Tests\\Fixtures\\V2\\ChangedGreetingActivity';
        }
        unset($event);

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::STATUS_DRIFTED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_SHAPE_MISMATCH, $report['reason']);
        $this->assertSame(1, $report['divergence']['workflow_sequence']);
        $this->assertSame(
            'activity:Tests\\Fixtures\\V2\\TestGreetingActivity',
            $report['divergence']['expected_shape'],
        );
        $this->assertContains('ActivityScheduled', $report['divergence']['recorded_event_types']);
        $this->assertStringContainsString('Recorded activity_type', $report['divergence']['message']);
        $this->assertNull($report['error']);
    }

    public function testReplayDiffReportsBundleInvalidWhenSchemaMissing(): void
    {
        $report = (new ReplayDiff())->diffExport([
            'workflow' => [
                'run_id' => 'r-1',
                'instance_id' => 'i-1',
            ],
        ]);

        $this->assertSame(ReplayDiff::STATUS_FAILED, $report['status']);
        $this->assertSame(ReplayDiff::REASON_BUNDLE_INVALID, $report['reason']);
        $this->assertNull($report['replay']);
        $this->assertIsArray($report['error']);
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function failedWorkflowProvider(): iterable
    {
        yield 'thrown by workflow code' => [TestImmediateFailureWorkflow::class, 'The workflow refused the order.'];
        yield 'activity failure propagated' => [TestFailingWorkflow::class, 'boom'];
        yield 'activity failure rethrown as a new exception' => [
            TestHandleFailureThenThrowWorkflow::class,
            'The workflow rejected the failed activity.',
        ];
    }

    /**
     * @param class-string $workflowClass
     */
    #[DataProvider('failedWorkflowProvider')]
    public function testReplayDiffReplaysTheFailureRecordedInHistory(string $workflowClass, string $message): void
    {
        $runId = $this->failedRun($workflowClass, 'replay-diff-recorded-failure');
        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $report['status'], json_encode($report));
        $this->assertSame(ReplayDiff::REASON_NONE, $report['reason']);
        $this->assertNull($report['divergence']);
        $this->assertNull($report['error']);
        $this->assertSame([
            'exception_class' => RuntimeException::class,
            'message' => $message,
        ], $report['replay']['recorded_failure'] ?? null);

        $runReport = (new ReplayDiff())->diffRun(WorkflowRun::query()->findOrFail($runId));
        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $runReport['status'], json_encode($runReport));

        $path = $this->writeBundle($bundle);
        $exitCode = Artisan::call('workflow:v2:replay-verify', [
            'bundle' => $path,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Replay verification: OK', $output);
    }

    public function testReplayDiffReportsADifferentFailureAsAReplayError(): void
    {
        $runId = $this->failedRun(TestImmediateFailureWorkflow::class, 'replay-diff-failure-mismatch');
        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        foreach ([
            'message' => ['message', 'The workflow refused an older order.'],
            'class' => ['exception_class', 'LogicException'],
        ] as $label => [$field, $recorded]) {
            $changed = $bundle;
            foreach ($changed['history_events'] as &$event) {
                if (($event['type'] ?? null) === 'WorkflowFailed') {
                    $event['payload'][$field] = $recorded;
                }
            }
            unset($event);

            $report = (new ReplayDiff())->diffExport($changed);

            // The replayed exception is not the one the run recorded, so it
            // stays an unexpected replay error and names the recorded failure.
            $this->assertSame(ReplayDiff::STATUS_FAILED, $report['status'], $label);
            $this->assertSame(ReplayDiff::REASON_REPLAY_ERROR, $report['reason'], $label);
            $this->assertSame(RuntimeException::class, $report['error']['class'], $label);
            $this->assertSame('The workflow refused the order.', $report['error']['message'], $label);
            $this->assertSame($recorded, $report['error']['recorded_failure'][$field] ?? null, $label);
            $this->assertNull($report['replay'], $label);
        }
    }

    public function testReplayDiffComparesOnlyTheClassWhenTheBundleIsRedacted(): void
    {
        $runId = $this->failedRun(TestImmediateFailureWorkflow::class, 'replay-diff-redacted-failure');
        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));
        $bundle['redaction']['applied'] = true;
        foreach ($bundle['history_events'] as &$event) {
            if (($event['type'] ?? null) === 'WorkflowFailed') {
                $event['payload']['message'] = '[redacted]';
            }
        }
        unset($event);

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::STATUS_REPLAYED, $report['status'], json_encode($report));
        $this->assertSame(RuntimeException::class, $report['replay']['recorded_failure']['exception_class'] ?? null);
    }

    public function testReplayDiffKeepsReplayErrorWhenTheHistoryDidNotFail(): void
    {
        $runId = $this->failedRun(TestImmediateFailureWorkflow::class, 'replay-diff-unrecorded-failure');
        $bundle = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));
        $bundle['workflow']['status'] = 'completed';
        foreach ($bundle['history_events'] as &$event) {
            if (($event['type'] ?? null) === 'WorkflowFailed') {
                $event['type'] = 'WorkflowCompleted';
            }
        }
        unset($event);

        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame(ReplayDiff::STATUS_FAILED, $report['status'], json_encode($report));
        $this->assertSame(ReplayDiff::REASON_REPLAY_ERROR, $report['reason']);
        $this->assertSame(RuntimeException::class, $report['error']['class']);
        $this->assertNull($report['replay']);
    }

    /**
     * @param class-string $workflowClass
     */
    private function failedRun(string $workflowClass, string $instanceId): string
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make($workflowClass, $instanceId);
        $workflow->start();
        $runId = $workflow->runId();
        $this->assertNotNull($runId);
        $this->drainReadyTasks();
        $this->assertTrue($workflow->refresh()->failed());

        return $runId;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function writeBundle(array $bundle): string
    {
        $path = tempnam(sys_get_temp_dir(), 'replay-verify-command-');
        $this->assertIsString($path);
        file_put_contents($path, json_encode($bundle, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
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
