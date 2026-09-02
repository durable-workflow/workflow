<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestActivityArgumentObjectActivity;
use Tests\Fixtures\V2\TestActivityArgumentObjectWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecDecodeException;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

/** Avro-only activity arguments reject PHP-only object values. */
final class V2ActivityArgumentCodecTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()
            ->set('workflows.serializer', 'avro');
        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();
    }

    public function testPhpOnlyActivityArgumentsFailBeforeScheduling(): void
    {
        $workflow = WorkflowStub::make(TestActivityArgumentObjectWorkflow::class, 'activity-arg-codec-avro');
        $workflow->start();

        $this->drainReadyTasks();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertSame('avro', $run->payload_codec);

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertDatabaseMissing('activity_executions', [
            'workflow_run_id' => $run->id,
        ]);
    }

    public function testActivityArgumentsUseStoredCodecInsteadOfSniffFallback(): void
    {
        $workflow = WorkflowStub::make(TestActivityArgumentObjectWorkflow::class, 'activity-arg-stored-codec');
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 99,
            'activity_class' => TestActivityArgumentObjectActivity::class,
            'activity_type' => TestActivityArgumentObjectActivity::class,
            'status' => ActivityStatus::Pending->value,
            'attempt_count' => 0,
            'payload_codec' => 'avro',
            'arguments' => json_encode(['sniffable-json'], JSON_THROW_ON_ERROR),
        ]);

        $this->expectException(CodecDecodeException::class);

        $execution->activityArguments();
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var \Workflow\V2\Models\WorkflowTask|null $task */
            $task = \Workflow\V2\Models\WorkflowTask::query()
                ->where('status', \Workflow\V2\Enums\TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return;
            }

            $job = match ($task->task_type) {
                \Workflow\V2\Enums\TaskType::Workflow => new \Workflow\V2\Jobs\RunWorkflowTask($task->id),
                \Workflow\V2\Enums\TaskType::Activity => new \Workflow\V2\Jobs\RunActivityTask($task->id),
                \Workflow\V2\Enums\TaskType::Timer => new \Workflow\V2\Jobs\RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
