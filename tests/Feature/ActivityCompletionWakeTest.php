<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TestCompletionWakeWorkflow;
use Tests\TestCase;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

final class ActivityCompletionWakeTest extends TestCase
{
    public function testLateActivityCompletionWakesParentWhilePreviousJobReturns(): void
    {
        $queue = 'activity-wake-' . Str::uuid();
        $directory = sys_get_temp_dir() . '/' . $queue;
        mkdir($directory, 0700);
        $activity = $this->worker($queue);
        $other = $this->worker($queue);
        $parent = $this->worker($queue);
        $redis = Queue::connection('redis')->getConnection();
        $key = Queue::connection('redis')->getQueue($queue);

        try {
            $workflow = WorkflowStub::make(TestCompletionWakeWorkflow::class);
            $workflow->start($directory, new WorkflowOptions(connection: 'redis', queue: $queue));
            $this->worker($queue)
                ->mustRun();
            $this->assertSame(2, $redis->llen($key));

            // The first activity removes its semaphore, then pauses before its
            // completion callback records the result. The second finishes first.
            $activity->start();
            $this->awaitFile($directory . '/activity-running');
            $other->start();
            $this->awaitFile($directory . '/activity-unlocked');
            $this->assertSame('0', file_get_contents($directory . '/activity-unlocked'));
            touch($directory . '/release-other');
            $other->wait();
            $this->assertTrue($other->isSuccessful(), $other->getErrorOutput());
            $this->assertSame(1, $workflow->logs()->count());

            // The parent observes only the second result, returns to waiting
            // and releases its overlap semaphore, but its queue handler is live.
            touch($directory . '/pause-parent');
            $parent->start();
            $this->awaitFile($directory . '/parent-returning');
            $this->assertSame(WorkflowWaitingStatus::class, $workflow->fresh()->status());
            touch($directory . '/release-activity');
            $activity->wait();
            $this->assertTrue($activity->isSuccessful(), $activity->getErrorOutput());
            $this->assertSame(2, $workflow->logs()->count());
            $this->assertSame(1, $redis->llen($key), 'A durable late result must retain its own wake.');
            $this->assertSame(WorkflowPendingStatus::class, $workflow->fresh()->status());

            touch($directory . '/release-parent');
            $parent->wait();
            $this->assertTrue($parent->isSuccessful(), $parent->getErrorOutput());
            $this->worker($queue)
                ->mustRun();
            $this->assertSame('workflow_activity_other', $workflow->output());
            $this->assertSame(2, $workflow->logs()->count());
            $this->assertSame(0, $workflow->exceptions()->count());
            $this->assertSame(0, $redis->llen($key));
            $this->assertSame(0, $redis->zcard($key . ':delayed'));
        } finally {
            touch($directory . '/release-activity');
            touch($directory . '/release-other');
            touch($directory . '/release-parent');
            foreach ([$activity, $other, $parent] as $process) {
                if ($process->isRunning()) {
                    $process->wait();
                }
            }
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function worker(string $queue): Process
    {
        $process = new Process(['php', __DIR__ . '/../../vendor/bin/testbench', 'queue:work', 'redis',
            '--queue=' . $queue, '--once', '--sleep=0', '--tries=1'], env: [
                'WORKFLOW_WATCHDOG_ENABLED' => 'false',
            ]);
        $process->setTimeout(15);

        return $process;
    }

    private function awaitFile(string $path): void
    {
        $stop = microtime(true) + 5;
        while (! is_file($path)) {
            if (microtime(true) > $stop) {
                $this->fail('Queue worker did not reach its completion boundary.');
            }
            usleep(10000);
        }
    }
}
