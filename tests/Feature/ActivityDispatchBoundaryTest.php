<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TestDispatchBoundaryWorkflow;
use Tests\TestCase;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

final class ActivityDispatchBoundaryTest extends TestCase
{
    public function testOneAttemptActivityDoesNotSpendItsAttemptOnTheWorkflowLock(): void
    {
        $queue = 'dispatch-boundary-' . Str::uuid();
        $directory = sys_get_temp_dir() . '/' . $queue;
        mkdir($directory, 0700);
        $parent = $this->worker($queue);

        try {
            $workflow = WorkflowStub::make(TestDispatchBoundaryWorkflow::class);
            $workflow->start($directory, new WorkflowOptions(connection: 'redis', queue: $queue));
            $parent->start();
            $deadline = microtime(true) + 5;
            while (! is_file($directory . '/produced')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Workflow did not reach its activity dispatch boundary.');
                }
                usleep(10000);
            }

            // Poll while the parent still owns its workflow overlap semaphore.
            // Queue contention must not consume the activity's sole attempt.
            $connection = Queue::connection('redis');
            $this->assertSame(
                0,
                $connection->getConnection()
                    ->llen($connection->getQueue($queue)),
                'Activities must enter the queue after their workflow releases its overlap semaphore.'
            );
            $this->worker($queue)
                ->mustRun();
            $this->assertFileDoesNotExist($directory . '/executed');
            touch($directory . '/release');
            $parent->wait();
            $this->assertTrue($parent->isSuccessful(), $parent->getErrorOutput());

            for ($i = 0; $i < 4; $i++) {
                $this->worker($queue)
                    ->mustRun();
            }

            $this->assertTrue($workflow->completed());
            $this->assertTrue($workflow->output());
            $this->assertSame('1', file_get_contents($directory . '/executed'));
            $this->assertSame(1, $workflow->logs()->count());
            $this->assertSame(0, $workflow->exceptions()->count());
            $this->assertSame(0, Queue::connection('redis')->size($queue));
        } finally {
            touch($directory . '/release');
            if ($parent->isRunning()) {
                $parent->wait();
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
}
