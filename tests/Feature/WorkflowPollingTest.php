<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fixtures\AssertWorkerHistoryProjectionBinding;
use Tests\Fixtures\TerminateQueueWorker;
use Tests\Fixtures\TestAwaitWorkflow;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub as V2WorkflowStub;
use Workflow\WorkflowStub;

final class WorkflowPollingTest extends TestCase
{
    public function testConcurrentWorkerBootsUseIsolatedManifestsWhenClaimingV2Tasks(): void
    {
        $repositoryRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        for ($boot = 0; $boot < 3; $boot++) {
            if ($boot > 0) {
                self::restartQueueWorkers();
            }

            $marker = sprintf('workflow:test:worker-boot:%d:%s', $boot, bin2hex(random_bytes(8)));

            for ($worker = 0; $worker < self::NUMBER_OF_WORKERS; $worker++) {
                AssertWorkerHistoryProjectionBinding::dispatch($marker)
                    ->onConnection('redis')
                    ->onQueue('default');
            }

            $deadline = hrtime(true) + 10_000_000_000;

            do {
                $successes = Cache::get($marker . ':successes', []);

                if (is_array($successes) && count($successes) === self::NUMBER_OF_WORKERS) {
                    break;
                }

                $remainingNanoseconds = $deadline - hrtime(true);
                if ($remainingNanoseconds <= 0) {
                    break;
                }

                usleep((int) min(50_000, max(1, (int) ceil($remainingNanoseconds / 1_000))));
            } while (true);

            $this->assertIsArray($successes);
            $this->assertCount(
                self::NUMBER_OF_WORKERS,
                $successes,
                sprintf(
                    'Every concurrently booted worker must resolve HistoryProjectionRole. Diagnostics: %s',
                    json_encode(self::workerDiagnostics(), JSON_UNESCAPED_SLASHES),
                ),
            );

            $servicesCaches = array_column($successes, 'services_cache');
            $packagesCaches = array_column($successes, 'packages_cache');

            $this->assertCount(self::NUMBER_OF_WORKERS, array_unique($servicesCaches));
            $this->assertCount(self::NUMBER_OF_WORKERS, array_unique($packagesCaches));

            foreach (array_merge($servicesCaches, $packagesCaches) as $cachePath) {
                $this->assertIsString($cachePath);
                $this->assertFalse(
                    str_starts_with($cachePath, $repositoryRoot),
                    "Worker manifest cache [{$cachePath}] must be outside the repository.",
                );
            }

            for ($task = 0; $task < self::NUMBER_OF_WORKERS; $task++) {
                $workflow = V2WorkflowStub::make(
                    TestScheduledWorkflow::class,
                    sprintf('isolated-worker-boot-%d-task-%d', $boot, $task),
                );
                $workflow->start(sprintf('boot-%d-task-%d', $boot, $task));
                $this->waitForWorkflow(
                    $workflow,
                    static fn (V2WorkflowStub $workflow): bool => $workflow->refresh()
                        ->completed(),
                    'a v2 workflow task to be claimed and completed',
                    15.0,
                );

                $runId = $workflow->runId();
                $this->assertNotNull($runId);
                $this->assertSame(
                    1,
                    WorkflowTask::query()
                        ->where('workflow_run_id', $runId)
                        ->where('task_type', 'workflow')
                        ->where('status', 'completed')
                        ->count(),
                );
            }
        }
    }

    public function testNonProgressingWorkflowFailsQuicklyWithDurableDiagnostics(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);
        $startedAt = hrtime(true);

        try {
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => ! $workflow->running(),
                'a terminal state',
                0.05,
            );

            $this->fail('The non-progressing workflow unexpectedly reached a terminal state.');
        } catch (AssertionFailedError $failure) {
            $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

            $this->assertLessThan(10.0, $elapsedSeconds);
            $this->assertStringContainsString(
                'waiting for workflow to reach a terminal state',
                $failure->getMessage(),
            );
            $this->assertStringContainsString(
                '"workflow":{"id":"' . $workflow->id() . '"',
                $failure->getMessage(),
            );
            $this->assertStringContainsString('"run":{"id":"' . $workflow->id() . '"', $failure->getMessage());
            $this->assertStringContainsString('"status":"created"', $failure->getMessage());
            $this->assertStringContainsString('"task":', $failure->getMessage());
            $this->assertStringContainsString('"history":', $failure->getMessage());
        }
    }

    public function testDeadQueueWorkerFailsImmediatelyWithCapturedOutput(): void
    {
        $workflow = WorkflowStub::make(TestAwaitWorkflow::class);
        $completionMarker = 'workflow:test:worker-output:' . $workflow->id();

        TerminateQueueWorker::dispatch($completionMarker)
            ->onConnection('redis')
            ->onQueue('default');

        $markerDeadline = hrtime(true) + 5_000_000_000;
        $outputCompletedWithoutParentPipeReads = false;

        do {
            if (Cache::get($completionMarker) === true) {
                $outputCompletedWithoutParentPipeReads = true;

                break;
            }

            $remainingNanoseconds = $markerDeadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                break;
            }

            usleep((int) min(50_000, max(1, (int) ceil($remainingNanoseconds / 1_000))));
        } while (true);

        $this->assertTrue(
            $outputCompletedWithoutParentPipeReads,
            'Queue worker output must not backpressure before the parent observes process state.',
        );

        try {
            $this->waitForWorkflow(
                $workflow,
                static fn (WorkflowStub $workflow): bool => ! $workflow->running(),
                'a terminal state',
                5.0,
            );

            $this->fail('The terminated queue worker was not detected.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('Queue worker ', $failure->getMessage());
            $this->assertStringContainsString(' exited while waiting', $failure->getMessage());
            $this->assertStringContainsString('"exit_code":23', $failure->getMessage());
            $this->assertStringContainsString('[earlier worker output truncated]', $failure->getMessage());
            $this->assertStringContainsString('intentional worker stdout probe', $failure->getMessage());
            $this->assertStringContainsString('intentional worker stderr probe', $failure->getMessage());
            $this->assertStringContainsString('"status":"created"', $failure->getMessage());
            $this->assertStringContainsString('"history":', $failure->getMessage());
        }
    }

    public function testNonProgressingV2WorkflowReportsRunTaskAndHistoryDiagnostics(): void
    {
        Queue::fake();

        $workflow = V2WorkflowStub::make(TestGreetingWorkflow::class, 'v2-polling-stalled');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        try {
            $this->waitForWorkflow(
                $workflow,
                static fn (V2WorkflowStub $workflow): bool => $workflow->refresh()
                    ->completed(),
                'the v2 workflow to complete',
                0.05,
            );

            $this->fail('The non-progressing v2 workflow unexpectedly completed.');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString(
                'waiting for workflow to reach the v2 workflow to complete',
                $failure->getMessage(),
            );
            $this->assertStringContainsString('"workflow":{"id":"v2-polling-stalled"', $failure->getMessage());
            $this->assertStringContainsString('"run":{"id":"' . $runId . '"', $failure->getMessage());
            $this->assertStringContainsString('"status":"pending"', $failure->getMessage());
            $this->assertStringContainsString('"task":', $failure->getMessage());
            $this->assertStringContainsString('"history":', $failure->getMessage());
            $this->assertStringContainsString('"command":', $failure->getMessage());
            $this->assertStringContainsString('"update":', $failure->getMessage());
            $this->assertStringContainsString('"workers":', $failure->getMessage());
            $this->assertStringNotContainsString('"diagnostic_error":', $failure->getMessage());
        }
    }
}
