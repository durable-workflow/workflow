<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestAvroAdapterCodecWorkflow;
use Tests\TestCase;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

final class V2AvroAdapterCodecTest extends TestCase
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

    public function testAdaptersKeepTypedAvroFramesAcrossActivityAndChildRoundTrips(): void
    {
        $golden = $this->goldenFrames();
        $arguments = [null, true, 7, 7.0, AvroBinaryValue::fromBytes("\x00\xFF"), 'text'];
        $activityResult = AvroMapValue::fromPairs([['0', 'zero']]);
        $childResult = AvroMapValue::fromPairs([['0', 'zero'], ['1', 'one']]);

        $workflow = WorkflowStub::make(TestAvroAdapterCodecWorkflow::class, 'avro-adapter-codec');
        $workflow->start();

        $this->drainReadyTasks();

        /** @var WorkflowRun $parentRun */
        $parentRun = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->assertTrue($workflow->refresh()->completed());
        $this->assertSame('avro', $parentRun->payload_codec);
        $this->assertSame('avro', $parentRun->output_payload_codec);
        $this->assertSame($golden['map_keys_0_1'], $parentRun->output);
        $this->assertEquals($childResult, $workflow->output());

        /** @var ActivityExecution $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $parentRun->id)
            ->firstOrFail();
        $this->assertSame('avro', $execution->payload_codec);
        $this->assertSame($golden['array'], $execution->arguments);
        $this->assertSame($golden['map_key_0'], $execution->result);
        $this->assertEquals($arguments, $execution->activityArguments());
        $this->assertEquals($activityResult, $execution->activityResult());

        /** @var WorkflowHistoryEvent $activityScheduled */
        $activityScheduled = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ActivityScheduled->value)
            ->firstOrFail();
        $this->assertSame('avro', $activityScheduled->payload['activity']['payload_codec'] ?? null);
        $this->assertSame($golden['array'], $activityScheduled->payload['activity']['arguments'] ?? null);

        /** @var WorkflowHistoryEvent $activityCompleted */
        $activityCompleted = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $parentRun->id)
            ->where('event_type', HistoryEventType::ActivityCompleted->value)
            ->firstOrFail();
        $this->assertSame('avro', $activityCompleted->payload['payload_codec'] ?? null);
        $this->assertSame($golden['map_key_0'], $activityCompleted->payload['result'] ?? null);
        $this->assertSame($golden['map_key_0'], $activityCompleted->payload['activity']['result'] ?? null);

        /** @var WorkflowLink $link */
        $link = WorkflowLink::query()
            ->where('parent_workflow_run_id', $parentRun->id)
            ->where('link_type', 'child_workflow')
            ->firstOrFail();
        /** @var WorkflowRun $childRun */
        $childRun = WorkflowRun::query()->findOrFail($link->child_workflow_run_id);
        $this->assertSame('avro', $childRun->payload_codec);
        $this->assertSame($golden['array'], $childRun->arguments);
        $this->assertEquals($arguments, $childRun->workflowArguments());
        $this->assertSame('avro', $childRun->output_payload_codec);
        $this->assertSame($golden['map_keys_0_1'], $childRun->output);
        $this->assertEquals($childResult, $childRun->workflowOutput());
    }

    /**
     * @return array<string, string>
     */
    private function goldenFrames(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../resources/protocol/avro-value-v1-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return array_column($fixture['cases'], 'wire_base64', 'name');
    }

    private function drainReadyTasks(): void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            /** @var \Workflow\V2\Models\WorkflowTask|null $task */
            $task = \Workflow\V2\Models\WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('created_at')
                ->first();

            if ($task === null) {
                return;
            }

            if ($task->available_at !== null && $task->available_at->isFuture()) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new \Workflow\V2\Jobs\RunWorkflowTask($task->id),
                TaskType::Activity => new \Workflow\V2\Jobs\RunActivityTask($task->id),
                TaskType::Timer => new \Workflow\V2\Jobs\RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
