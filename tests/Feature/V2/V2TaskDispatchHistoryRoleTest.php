<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\UnsupportedBackendCapabilitiesException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\WorkflowStub;

/**
 * Pins every TaskDispatcher::publish exit that refreshes operator projections
 * to the HistoryProjectionRole binding, so a future change cannot silently
 * bypass the role for any of these paths (faked dispatch, poll mode, fleet
 * block, unsupported backend, successful queue publication, or transport
 * failure).
 */
final class V2TaskDispatchHistoryRoleTest extends TestCase
{
    public function testFakedDispatchPathUsesHistoryProjectionRoleBinding(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        WorkflowStub::fake();

        $run = $this->createWaitingRun('01J00000000000000000000HR1');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->addMinute(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        TaskDispatcher::dispatch($task);

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testPollModeDispatchPathUsesHistoryProjectionRoleBinding(): void
    {
        config()->set('workflows.v2.task_dispatch_mode', 'poll');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000HR2');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        TaskDispatcher::dispatch($task);

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testFleetBlockedDispatchPathUsesHistoryProjectionRoleBinding(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
        config()
            ->set('workflows.v2.fleet.validation_mode', 'fail');

        WorkerCompatibilityFleet::clear();
        WorkerCompatibilityFleet::record(['build-b'], 'redis', 'default', 'worker-b');

        $this->beforeApplicationDestroyed(static function (): void {
            WorkerCompatibilityFleet::clear();
        });

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000HR3');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        TaskDispatcher::dispatch($task);

        $task->refresh();

        $this->assertNotNull($task->last_dispatch_error);
        $this->assertStringContainsString(
            'Dispatch blocked under fail validation mode',
            (string) $task->last_dispatch_error,
        );
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testUnsupportedBackendDispatchPathUsesHistoryProjectionRoleBinding(): void
    {
        config()->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.driver', 'redis');
        config()
            ->set('queue.connections.sync.driver', 'sync');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldNotReceive('dispatch');
        });

        $run = $this->createWaitingRun('01J00000000000000000000HR4');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(5),
            'payload' => [],
            'connection' => 'sync',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw an unsupported backend capability exception.');
        } catch (UnsupportedBackendCapabilitiesException $exception) {
            $this->assertSame('sync', $exception->snapshot()['queue']['connection']);
        }

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testQueueDispatchSuccessPathUsesHistoryProjectionRoleBinding(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        Queue::fake();

        $run = $this->createWaitingRun('01J00000000000000000000HR5');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSecond(),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        TaskDispatcher::dispatch($task);

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testQueueDispatchFailurePathUsesHistoryProjectionRoleBinding(): void
    {
        config()->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);

        $this->mock(BusDispatcher::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')
                ->once()
                ->andThrow(new \RuntimeException('Queue transport unavailable.'));
        });

        $run = $this->createWaitingRun('01J00000000000000000000HR6');

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now()
                ->subSeconds(5),
            'payload' => [],
            'connection' => 'redis',
            'queue' => 'default',
            'compatibility' => 'build-a',
        ]);

        $customRole = $this->bindRecordingRole();

        try {
            TaskDispatcher::dispatch($task);
            $this->fail('Expected dispatch to throw.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Queue transport unavailable.', $exception->getMessage());
        }

        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    private function bindRecordingRole(): HistoryProjectionRole
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = [];

            public function __construct(
                private readonly DefaultHistoryProjectionRole $delegate,
            ) {
            }

            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                $this->calls[] = ['projectRun', $run->id];

                return $this->delegate->projectRun($run);
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->delegate->recordActivityStarted($run, $execution, $attempt, $task);
            }
        };

        $this->app->instance(HistoryProjectionRole::class, $customRole);

        return $customRole;
    }

    private function createWaitingRun(string $instanceId): WorkflowRun
    {
        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => now()
                ->subMinute(),
            'started_at' => now()
                ->subMinute(),
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
            'started_at' => now()
                ->subMinute(),
            'last_progress_at' => now()
                ->subSeconds(30),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        return $run;
    }
}
