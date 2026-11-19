<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestOversizedFanOutMemberWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function timerRefusalGroups(): iterable
    {
        // The fourth of eight timers; the eighth member of a mixed group,
        // after an activity, a child, a signal wait and a condition wait.
        yield 'timers' => ['timer'];
        yield 'mixed' => ['mixed'];
    }

    #[DataProvider('timerRefusalGroups')]
    public function testPendingTimerRefusalInsideTheGroupStillRollsTheTaskBack(string $kind): void
    {
        // Timers are not admitted. The pending-timer refusal escapes the
        // task, as a sequential timer's does, and the task's transaction
        // rolls back every member written before it; the task is left failed
        // and recoverable, and the run open.
        config([
            'workflows.v2.structural_limits.pending_timer_count' => $kind === 'timer' ? 3 : 2,
        ]);

        $stub = WorkflowStub::make(TestOversizedFanOutMemberWorkflow::class, "admission-timer-count-{$kind}");
        $stub->start($kind === 'timer' ? 8 : 30, -1, 0, $kind);
        $task = $this->readyWorkflowTask($stub->runId());

        try {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            $this->fail('The pending-timer refusal did not escape the workflow task.');
        } catch (StructuralLimitExceededException $refusal) {
            $this->assertSame('pending_timer_count', $refusal->limitKind->value);
        }

        $run = WorkflowRun::query()->findOrFail($stub->runId());
        $this->assertFalse($run->status->isTerminal());
        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
        $this->assertSame(0, WorkflowFailure::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(
            [HistoryEventType::StartAccepted->value, HistoryEventType::WorkflowStarted->value],
            $this->historyTypes($run),
        );
        $this->assertSame(0, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowTimer::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(0, WorkflowRun::query()->where('id', '!=', $run->id)->count());
    }

    public function testLaterOversizedMemberTakesPrecedenceOverAnEarlierTimerRefusal(): void
    {
        // The member at index 7 would hit the pending-timer limit, which
        // admission does not check; the activity at index 27 is over the
        // payload limit, which it does. The group is refused for the payload.
        config([
            'workflows.v2.structural_limits.pending_timer_count' => 2,
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);

        $run = $this->runFirstTask('admission-precedence', 30, 27, 8192, 'mixed');

        $this->assertRefusedBeforeFirstWrite($run, 'payload_size');
    }

    public function testNestedGroupAtThePendingActivityLimitIsAdmitted(): void
    {
        config([
            'workflows.v2.structural_limits.pending_activity_count' => 6,
        ]);

        $run = $this->runFirstTask('admission-nested-at-limit', 6, -1, 0, 'nested');

        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(6, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testNestedGroupOneOverThePendingActivityLimitWritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.pending_activity_count' => 5,
        ]);

        $run = $this->runFirstTask('admission-nested-over-limit', 6, -1, 0, 'nested');

        $failure = $this->assertRefusedBeforeFirstWrite($run, 'pending_activity_count');
        $this->assertStringContainsString('5 pending activities', (string) $failure->message);
    }

    public function testNestedGroupWithAnOversizedInnerMemberWritesNoMember(): void
    {
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);

        $run = $this->runFirstTask('admission-nested-payload', 6, 4, 8192, 'nested');

        $this->assertRefusedBeforeFirstWrite($run, 'payload_size');
    }

    public function testReplayedGroupIsNotRefusedAfterTheLimitIsLoweredBelowWhatIsPending(): void
    {
        $run = $this->runFirstTask('admission-lowered-limit', 6, -1, 0, 'signalled');
        $this->assertSame(5, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());

        // Five are pending; the limit drops to two before the group replays.
        config([
            'workflows.v2.structural_limits.pending_activity_count' => 2,
        ]);
        $this->signalAndReplay($run);

        $run->refresh();
        $this->assertSame(RunStatus::Waiting, $run->status);
        $this->assertSame(0, WorkflowFailure::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(5, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
    }

    public function testPartlyRecordedGroupChecksOnlyItsUnrecordedMembers(): void
    {
        // Every member is scheduled while the payload limit is off, then the
        // last two members' records are removed, so the group is partly
        // recorded: its oversized member (index 3) is recorded, the members
        // after it are not.
        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 0,
        ]);
        $run = $this->runFirstTask('admission-partly-recorded', 7, 3, 8192, 'signalled');
        $this->assertSame(6, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());

        $removed = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('sequence', [6, 7])
            ->pluck('id')
            ->all();
        WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Activity->value)
            ->get()
            ->filter(static fn (WorkflowTask $task): bool => in_array(
                $task->payload['activity_execution_id'] ?? null,
                $removed,
                true,
            ))
            ->each(static fn (WorkflowTask $task) => $task->delete());
        ActivityExecution::query()->whereIn('id', $removed)->delete();
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->get()
            ->filter(static fn (WorkflowHistoryEvent $event): bool => in_array(
                $event->payload['sequence'] ?? null,
                [6, 7],
                true,
            ))
            ->each(static fn (WorkflowHistoryEvent $event) => $event->delete());

        config([
            'workflows.v2.structural_limits.payload_size_bytes' => 4096,
        ]);
        $this->signalAndReplay($run);

        // The recorded oversized member is not checked again; only the two
        // unrecorded, small members are admitted and scheduled.
        $run->refresh();
        $this->assertSame(
            RunStatus::Waiting,
            $run->status,
            (string) WorkflowFailure::query()->where('workflow_run_id', $run->id)->value('message'),
        );
        $this->assertSame(6, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
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

    private function readyWorkflowTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
    }

    private function signalAndReplay(WorkflowRun $run): void
    {
        WorkflowStub::load($run->workflow_instance_id)->signal('advance');

        $this->app->call([new RunWorkflowTask($this->readyWorkflowTask($run->id)->id), 'handle']);
    }

    /**
     * @return list<string>
     */
    private function historyTypes(WorkflowRun $run): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->pluck('event_type')
            ->map(
                static fn (HistoryEventType|string $type): string => $type instanceof HistoryEventType ? $type->value : $type
            )
            ->all();
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
