<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivitySnapshot;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\WorkflowReplayer;
use Workflow\V2\WorkflowStub;

final class V2WorkflowReplayerTest extends TestCase
{
    public function testPublicReplayerCanReplayLiveRun(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'public-replayer-live-run');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $state = (new WorkflowReplayer())->replay(WorkflowRun::query()->findOrFail($runId));

        $this->assertSame(1, $state->sequence);
        $this->assertInstanceOf(ActivityCall::class, $state->current);
        $this->assertSame('public-replayer-live-run', $state->workflow->workflowId());
    }

    public function testPublicReplayerCanReplayHistoryExportBundle(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'public-replayer-history-export');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $run = WorkflowRun::query()->findOrFail($runId);
        $export = HistoryExport::forRun($run);
        $state = (new WorkflowReplayer())->replayExport($export);

        $this->assertSame(2, $state->sequence);
        $this->assertNull($state->current);
        $this->assertSame('public-replayer-history-export', $state->workflow->workflowId());
    }

    public function testPublicReplayerPreservesExternalActivityPayloadEnvelopesFromHistoryExport(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'public-replayer-external-activity-payloads');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $this->runReadyTaskForRun($runId, TaskType::Workflow);
        $this->runReadyTaskForRun($runId, TaskType::Activity);
        $this->runReadyTaskForRun($runId, TaskType::Workflow);

        $codec = CodecRegistry::defaultCodec();
        $arguments = $this->externalStorageEnvelope($codec, 'replay-activity-arguments');
        $result = $this->externalStorageEnvelope($codec, 'replay-activity-result');
        $export = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));
        $export['activities'][0]['payload_codec'] = $codec;
        $export['activities'][0]['arguments'] = $arguments;
        $export['activities'][0]['result'] = $result;

        $run = (new WorkflowReplayer())->runFromHistoryExport($export);
        $activity = $run->activityExecutions->first();

        $this->assertInstanceOf(ActivityExecution::class, $activity);
        $this->assertIsString($activity->arguments);
        $this->assertIsString($activity->result);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $activity->arguments);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $activity->result);

        $snapshot = ActivitySnapshot::fromExecution($activity);

        $this->assertEquals($arguments, $snapshot['arguments']);
        $this->assertEquals($result, $snapshot['result']);
    }

    public function testPublicReplayerPreservesExternalRunAndCommandPayloadEnvelopesFromHistoryExport(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        Queue::fake();

        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'public-replayer-external-run-payloads');
        $workflow->start('Taylor');
        $runId = $workflow->runId();

        $this->assertNotNull($runId);

        $codec = CodecRegistry::defaultCodec();
        $arguments = $this->externalStorageEnvelope($codec, 'replay-run-arguments');
        $commandPayload = $this->externalStorageEnvelope($codec, 'replay-command-payload');
        $export = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));
        $export['payloads']['arguments']['available'] = true;
        $export['payloads']['arguments']['data'] = $arguments;
        $export['commands'] = [
            [
                'id' => 'external-command',
                'sequence' => 1,
                'type' => 'start',
                'target_scope' => 'instance',
                'payload_codec' => $codec,
                'payload' => $commandPayload,
                'source' => 'api',
                'status' => 'accepted',
                'outcome' => 'started_new',
            ],
        ];

        $run = (new WorkflowReplayer())->runFromHistoryExport($export);
        $command = $run->commands->first();

        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $run->arguments);
        $this->assertEquals($arguments, ExternalPayloads::storedEnvelope($run->arguments));
        $this->assertInstanceOf(WorkflowCommand::class, $command);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $command->payload);
        $this->assertEquals($commandPayload, ExternalPayloads::storedEnvelope($command->payload));
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

    /**
     * @return array{codec: string, external_storage: array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function externalStorageEnvelope(string $codec, string $label): array
    {
        return [
            'codec' => $codec,
            'external_storage' => [
                'schema' => ExternalPayloadReference::SCHEMA,
                'uri' => 'local://workflow-replayer-test/' . $label,
                'sha256' => hash('sha256', $label),
                'size_bytes' => strlen($label),
                'codec' => $codec,
            ],
        ];
    }
}
