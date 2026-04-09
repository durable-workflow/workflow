<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\TaskDispatcher;

final class V2TaskDispatchTest extends TestCase
{
    public function testTaskDispatchPersistsSuccessOnlyAfterAfterCommitPublicationRuns(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000001');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        DB::transaction(function () use ($task): void {
            TaskDispatcher::dispatch($task);

            /** @var WorkflowTask $insideTransaction */
            $insideTransaction = $task->fresh();

            $this->assertNull($insideTransaction->last_dispatch_attempt_at);
            $this->assertNull($insideTransaction->last_dispatched_at);
            $this->assertNull($insideTransaction->last_dispatch_error);
        });

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNotNull($task->last_dispatched_at);
        $this->assertNull($task->last_dispatch_error);

        Queue::assertPushed(
            RunWorkflowTask::class,
            static fn (RunWorkflowTask $job): bool => $job->taskId === $task->id
        );

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('workflow_task_ready', $summary->liveness_state);
    }

    public function testTaskDispatchFailureRecordsTransportFailureWithoutPretendingPublishSucceeded(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('Queue transport unavailable.'));
        });

        $run = $this->createWaitingRun('01J00000000000000000000002');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSeconds(5),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue transport unavailable.', $exception->getMessage());
        }

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNull($task->last_dispatched_at);
        $this->assertSame('Queue transport unavailable.', $task->last_dispatch_error);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Workflow task dispatch failed', $summary->wait_reason);
        $this->assertSame(
            sprintf(
                'Workflow task %s could not be dispatched at %s. Queue transport unavailable.',
                $task->id,
                $task->last_dispatch_attempt_at?->toJSON(),
            ),
            $summary->liveness_reason,
        );

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('repair_needed', $detail['liveness_state']);
        $this->assertSame('Workflow task dispatch failed', $detail['wait_reason']);
        $this->assertTrue($detail['tasks'][0]['dispatch_failed']);
        $this->assertFalse($detail['tasks'][0]['dispatch_overdue']);
        $this->assertSame('dispatch_failed', $detail['tasks'][0]['transport_state']);
        $this->assertSame('Workflow task dispatch failed; waiting for recovery.', $detail['tasks'][0]['summary']);
        $this->assertSame(
            $task->last_dispatch_attempt_at?->toJSON(),
            $detail['tasks'][0]['last_dispatch_attempt_at']?->toJSON(),
        );
        $this->assertSame('Queue transport unavailable.', $detail['tasks'][0]['last_dispatch_error']);
    }

    public function testTaskDispatchFailureRecordsUnsupportedQueueCapabilityWithoutRunningInline(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('queue.connections.sync.driver', 'sync');
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000003');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()->subSeconds(5),
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw.');
        } catch (UnsupportedBackendCapabilitiesException $exception) {
            $this->assertSame('sync', $exception->snapshot()['queue']['connection']);
            $this->assertContains('queue_sync_unsupported', array_column($exception->snapshot()['issues'], 'code'));
        }

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_attempt_at);
        $this->assertNull($task->last_dispatched_at);
        $this->assertStringContainsString('queue_sync_unsupported', (string) $task->last_dispatch_error);

        $summary = WorkflowRunSummary::query()->findOrFail($run->id);

        $this->assertSame('workflow-task', $summary->wait_kind);
        $this->assertSame('repair_needed', $summary->liveness_state);
        $this->assertSame('Workflow task dispatch failed', $summary->wait_reason);

        $detail = RunDetailView::forRun($run->fresh(['summary']));

        $this->assertSame('dispatch_failed', $detail['tasks'][0]['transport_state']);
        $this->assertSame($task->last_dispatch_error, $detail['tasks'][0]['last_dispatch_error']);
    }

    private function createWaitingRun(string $instanceId): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serialize(['Taylor']),
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()->subMinute(),
            'last_progress_at' => now()->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
