<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TestFailedActivityRedispatchActivity;
use Tests\Fixtures\TestFailedActivityRedispatchWorkflow;
use Tests\TestCase;
use Workflow\Activity;
use Workflow\Exception;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

/**
 * Issue #1241: a failed activity's outcome is recorded by a separately queued
 * Workflow\Exception job, after the activity's unique lock is gone. A replay
 * in that gap dispatches the activity again; the copy must not run it again.
 */
final class FailedActivityRedispatchTest extends TestCase
{
    private const SHORT_MARK_SECONDS = 2;

    public function testActivityRedispatchedBeforeItsFailureIsRecordedDoesNotRunAgain(): void
    {
        $queue = 'failed-activity-redispatch-' . Str::uuid();
        $directory = sys_get_temp_dir() . '/' . $queue;
        mkdir($directory, 0700);
        $redis = Queue::connection('redis')->getConnection();
        $key = Queue::connection('redis')->getQueue($queue);
        $exceptionLock = null;

        try {
            $workflow = WorkflowStub::make(TestFailedActivityRedispatchWorkflow::class);
            $workflow->start($directory, new WorkflowOptions(connection: 'redis', queue: $queue));
            $this->work($queue);
            $this->assertSame(WorkflowWaitingStatus::class, $workflow->fresh()->status());
            $this->assertSame(1, $redis->llen($key), 'The workflow dispatches its activity.');

            // Any wake (a signal, timer or sibling result) replays the workflow.
            $workflow->resume();
            $this->assertSame(2, $redis->llen($key));

            // The activity fails its job; the Exception job is queued behind
            // the wake and the activity's unique lock is released.
            $this->work($queue);
            $this->assertSame(1, $this->executions($directory));
            $this->assertSame(1, $workflow->exceptions()->count());
            $this->assertSame(0, $workflow->logs()->count());

            // The wake replays before the failure is recorded and dispatches
            // the activity again behind the Exception job.
            $this->work($queue);
            $this->assertSame(0, $workflow->logs()->count());
            $this->assertSame(2, $redis->llen($key), 'The replay dispatches the unrecorded activity again.');

            // The Exception job is still waiting to record the failure.
            $exceptionLock = Cache::lock('laravel-workflow-exception:' . $workflow->id(), 15);
            $this->assertTrue($exceptionLock->get());
            $this->work($queue);
            $this->assertSame(1, $redis->llen($key));
            $this->assertSame(1, $redis->zcard($key . ':delayed'));

            // The redispatched copy runs while the failure is unrecorded.
            $this->work($queue);
            $this->assertSame(1, $this->executions($directory), 'The failed activity ran again.');
            $this->assertSame(1, $workflow->exceptions()->count());

            $exceptionLock->release();
            $exceptionLock = null;
            $this->drain($queue, $workflow);

            $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
            $this->assertSame('caught: activity failed', $workflow->output());
            $this->assertSame(1, $this->executions($directory));
            $this->assertSame(1, $workflow->exceptions()->count());
            $this->assertSame([Exception::class], $workflow->logs()->pluck('class')->all());
            $this->assertSame(
                [TestFailedActivityRedispatchActivity::class],
                $workflow->exceptions()
                    ->pluck('class')
                    ->all()
            );
        } finally {
            $exceptionLock?->release();
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testLostExceptionJobLeavesTheActivityRunnableAfterTheMarkExpires(): void
    {
        $queue = 'lost-exception-job-' . Str::uuid();
        $directory = sys_get_temp_dir() . '/' . $queue;
        mkdir($directory, 0700);
        $redis = Queue::connection('redis')->getConnection();
        $key = Queue::connection('redis')->getQueue($queue);
        $environment = [
            'DW_V1_ACTIVITY_FAILURE_MARK_SECONDS' => (string) self::SHORT_MARK_SECONDS,
        ];

        try {
            $workflow = WorkflowStub::make(TestFailedActivityRedispatchWorkflow::class);
            $workflow->start($directory, new WorkflowOptions(connection: 'redis', queue: $queue));
            $this->work($queue, $environment);
            $this->work($queue, $environment);
            $this->assertSame(1, $this->executions($directory));
            $this->assertSame(1, $redis->llen($key), 'The Exception job is queued.');
            $mark = Activity::failureKey($workflow->id(), 0);
            $this->assertNotNull(Cache::get($mark));

            // The Exception job is lost (a flushed queue, an undecryptable payload).
            $redis->del($key);

            // While the mark lasts, a wake's copy of the activity does not run.
            $workflow->resume();
            $this->work($queue, $environment);
            $this->assertSame(1, $redis->llen($key));
            $this->work($queue, $environment);
            $this->assertSame(1, $this->executions($directory));
            $this->assertSame(WorkflowWaitingStatus::class, $workflow->fresh()->status());

            // Once it expires, the next wake runs the activity again.
            $deadline = microtime(true) + self::SHORT_MARK_SECONDS + 5;
            while (Cache::get($mark) !== null) {
                if (microtime(true) > $deadline) {
                    $this->fail('The failure mark did not expire.');
                }
                usleep(100000);
            }
            $workflow->resume();
            $this->drain($queue, $workflow, $environment);

            $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
            $this->assertSame('caught: activity failed', $workflow->output());
            $this->assertSame(2, $this->executions($directory));
            $this->assertSame([Exception::class], $workflow->logs()->pluck('class')->all());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function work(string $queue, array $environment = []): void
    {
        $process = new Process(['php', __DIR__ . '/../../vendor/bin/testbench', 'queue:work', 'redis',
            '--queue=' . $queue, '--once', '--sleep=0'], env: [
                'WORKFLOW_WATCHDOG_ENABLED' => 'false',
                ...$environment,
            ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    /**
     * @param array<string, string> $environment
     */
    private function drain(string $queue, WorkflowStub $workflow, array $environment = []): void
    {
        $deadline = microtime(true) + 30;

        while (! $workflow->fresh()->completed() && ! $workflow->failed()) {
            if (microtime(true) > $deadline) {
                $this->fail('The workflow did not finish after its failure was recorded.');
            }

            $this->work($queue, $environment);
            usleep(100000);
        }
    }

    private function executions(string $directory): int
    {
        $path = $directory . '/executions';

        return is_file($path) ? substr_count((string) file_get_contents($path), "\n") : 0;
    }
}
