<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\TestPendingContinuationWorkflow;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowContinuedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

final class PendingContinuationTimerTest extends TestCase
{
    public function testOriginalEncryptedTimerRetiresBeforePendingSuccessorRuns(): void
    {
        $queue = 'continued-pending-' . Str::uuid();
        $connection = Queue::connection('redis');
        $redis = $connection->getConnection();
        $key = $connection->getQueue($queue);
        $workflow = WorkflowStub::make(TestPendingContinuationWorkflow::class);
        $workflow->start(0, new WorkflowOptions(connection: 'redis', queue: $queue));
        $originalWorkflowPayload = $redis->lindex($key, 0);
        $this->tick($queue);
        $root = StoredWorkflow::findOrFail($workflow->id());
        $this->assertInstanceOf(WorkflowWaitingStatus::class, $root->status);
        $payloads = $redis->zrange($key . ':delayed', 0, -1);
        $this->assertCount(1, $payloads);
        $payload = $payloads[0];
        $command = json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'];
        $this->assertStringNotContainsString('O:', $command);
        $this->assertInstanceOf(\Workflow\Timer::class, unserialize(app('encrypter')->decrypt($command)));

        $workflow->finish();
        $this->tick($queue);
        $this->tick($queue);
        $this->assertInstanceOf(WorkflowContinuedStatus::class, $root->fresh()->status);
        $successor = $root->continuedWorkflows()
            ->sole();
        $this->assertInstanceOf(WorkflowPendingStatus::class, $successor->status);
        $before = $root->logs()
            ->count();

        // A workflow job already queued by an older release must retire too.
        $redis->lpush($key, $originalWorkflowPayload);
        $this->tick($queue);
        $this->assertSame(1, $redis->llen($key));
        $this->assertSame(1, $redis->zcard($key . ':delayed'));

        // Deliver the original encrypted bytes before the queued successor to
        // force the pending-run boundary. Only this fixture's queue moves.
        $redis->zrem($key . ':delayed', $payload);
        $redis->lpush($key, $payload);
        $this->tick($queue);
        $this->assertSame(1, $redis->llen($key));
        $this->assertSame(0, $redis->zcard($key . ':reserved'));
        $this->assertSame($before, $root->logs()->count());
        $this->assertInstanceOf(WorkflowPendingStatus::class, $successor->fresh()->status);

        $this->tick($queue);
        $this->assertInstanceOf(WorkflowWaitingStatus::class, $successor->fresh()->status);
        $workflow->finish();
        $this->tick($queue);
        $this->tick($queue);
        $this->assertSame('done', $workflow->output());
    }

    private function tick(string $queue): void
    {
        $process = new Process(['php', __DIR__ . '/../../vendor/bin/testbench', 'queue:work', 'redis',
            '--queue=' . $queue, '--once', '--sleep=0', '--tries=1'], env: [
                'WORKFLOW_WATCHDOG_ENABLED' => 'false',
            ]);
        $process->setTimeout(15);
        $process->mustRun();
    }
}
