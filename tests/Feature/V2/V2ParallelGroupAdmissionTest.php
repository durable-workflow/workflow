<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestOversizedFanOutMemberWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\WorkflowStub;

/**
 * An all() that a structural limit refuses is refused before any member is
 * written (#1232): the task records only the typed failure, and no member is
 * scheduled and then cancelled.
 */
final class V2ParallelGroupAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
        ]);
        Queue::fake();
    }

    public function testOversizedActivityArgumentAtPosition151Of300WritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);

        $run = $this->runFirstTask('admission-activity-payload', 300, 150, 8192, 'activity');

        $this->assertRefusedBeforeFirstWrite($run, 'payload_size');
    }

    public function testOversizedChildArgumentWritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);

        $run = $this->runFirstTask('admission-child-payload', 20, 10, 8192, 'child');

        $this->assertRefusedBeforeFirstWrite($run, 'payload_size');
        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame(0, WorkflowLink::query()->count());
    }

    public function testOversizedMemberAfterSignalTimerAndConditionMembersWritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);

        $run = $this->runFirstTask('admission-mixed-payload', 30, 27, 8192, 'mixed');

        $this->assertRefusedBeforeFirstWrite($run, 'payload_size');
        $this->assertSame(1, WorkflowInstance::query()->count());
    }

    public function testPendingTimerLimitInsideTheGroupWritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.pending_timer_count' => 5,
        ]);

        $run = $this->runFirstTask('admission-timer-count', 8, -1, 0, 'timer');

        $this->assertRefusedBeforeFirstWrite($run, 'pending_timer_count');
    }

    public function testPendingActivityLimitInsideTheGroupWritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.pending_activity_count' => 100,
        ]);

        $run = $this->runFirstTask('admission-activity-count', 150, -1, 0, 'activity');

        $failure = $this->assertRefusedBeforeFirstWrite($run, 'pending_activity_count');
        // The refusal names the member the per-member guard would have
        // refused: the 101st, with 100 pending.
        $this->assertStringContainsString('100', (string) $failure->message);
    }

    public function testAdmittedGroupStillSchedulesEveryMember(): void
    {
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
            'workflows.v2.structural_limits.pending_timer_count' => 10,
        ]);

        $run = $this->runFirstTask('admission-admitted', 30, 27, 2500, 'mixed');

        $this->assertSame(
            RunStatus::Waiting,
            $run->status,
            (string) WorkflowFailure::query()->where('workflow_run_id', $run->id)->value('message'),
        );
        $this->assertSame(10, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(5, WorkflowLink::query()->where('parent_workflow_run_id', $run->id)->count());
        // Five timers and five condition timeouts.
        $this->assertSame(10, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testExternallyStoredOversizedArgumentIsAdmitted(): void
    {
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);
        $driver = new class() implements ExternalPayloadStorageDriver {
            /**
             * @var array<string, string>
             */
            public array $payloads = [];

            public function put(string $data, string $sha256, string $codec): string
            {
                $uri = 'memory://admission/' . $sha256;
                $this->payloads[$uri] = $data;

                return $uri;
            }

            public function get(string $uri): string
            {
                return $this->payloads[$uri];
            }

            public function delete(string $uri): void
            {
                unset($this->payloads[$uri]);
            }
        };
        $this->app->instance(
            ExternalPayloadStoragePolicy::class,
            new class($driver) implements ExternalPayloadStoragePolicy {
                public function __construct(
                    private readonly ExternalPayloadStorageDriver $driver,
                ) {
                }

                public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
                {
                    return $this->driver;
                }

                public function thresholdBytesFor(?string $namespace): ?int
                {
                    return 1024;
                }
            },
        );

        // The oversized argument is stored as a small reference, so the
        // stored size the per-member guard checks is under the limit.
        $run = $this->runFirstTask('admission-external-payload', 6, 3, 8192, 'activity');

        $this->assertSame(
            RunStatus::Waiting,
            $run->status,
            (string) WorkflowFailure::query()->where('workflow_run_id', $run->id)->value('message'),
        );
        $this->assertSame(6, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        $this->assertCount(1, $driver->payloads);
    }

    private function runFirstTask(
        string $instanceId,
        int $count,
        int $oversizedIndex,
        int $oversizedBytes,
        string $kind,
    ): WorkflowRun {
        $stub = WorkflowStub::make(TestOversizedFanOutMemberWorkflow::class, $instanceId);
        $stub->start($count, $oversizedIndex, $oversizedBytes, $kind);

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $stub->runId())
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);

        return WorkflowRun::query()->findOrFail($stub->runId());
    }

    private function assertRefusedBeforeFirstWrite(WorkflowRun $run, string $limitKind): WorkflowFailure
    {
        $this->assertSame(RunStatus::Failed, $run->status);

        $this->assertSame(
            [
                HistoryEventType::StartAccepted->value,
                HistoryEventType::WorkflowStarted->value,
                HistoryEventType::WorkflowFailed->value,
            ],
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->pluck('event_type')
                ->map(
                    static fn (HistoryEventType|string $type): string => $type instanceof HistoryEventType ? $type->value : $type
                )
                ->all(),
        );
        $failed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->sole();
        $this->assertSame($limitKind, $failed->payload['structural_limit_kind'] ?? null);

        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowRun::query()->where('id', '!=', $run->id)->count());
        $this->assertSame(
            0,
            WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', '!=', TaskType::Workflow->value)
                ->count(),
        );

        /** @var WorkflowFailure $failure */
        $failure = WorkflowFailure::query()->where('workflow_run_id', $run->id)->sole();
        $this->assertSame(
            FailureCategory::StructuralLimit->value,
            $failure->failure_category?->value ?? $failure->failure_category,
        );

        return $failure;
    }
}
