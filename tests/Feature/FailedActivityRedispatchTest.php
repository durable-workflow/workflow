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

    private function work(string $queue): void
    {
        $process = new Process(['php', __DIR__ . '/../../vendor/bin/testbench', 'queue:work', 'redis',
            '--queue=' . $queue, '--once', '--sleep=0'], env: [
                'WORKFLOW_WATCHDOG_ENABLED' => 'false',
            ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function drain(string $queue, WorkflowStub $workflow): void
    {
        $deadline = microtime(true) + 30;

        while (! $workflow->fresh()->completed() && ! $workflow->failed()) {
            if (microtime(true) > $deadline) {
                $this->fail('The workflow did not finish after its failure was recorded.');
            }

            $this->work($queue);
            usleep(100000);
        }
    }

    private function executions(string $directory): int
    {
        $path = $directory . '/executions';

        return is_file($path) ? substr_count((string) file_get_contents($path), "\n") : 0;
    }
}
