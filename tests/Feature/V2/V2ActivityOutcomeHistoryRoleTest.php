<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\DefaultHistoryProjectionRole;
use Workflow\V2\Support\ParallelChildGroup;

/**
 * Pins every ActivityOutcomeRecorder::record exit that refreshes operator
 * projections to the HistoryProjectionRole binding, so a future change cannot
 * silently bypass the role for any of these paths (cancelled/terminated run,
 * already-terminal run, retry resume, the parallel-sibling-still-pending early
 * return, and the standard success/final-failure resume). The attempt-keyed
 * recordForAttempt wrapper — the production entry point reached through
 * DefaultActivityTaskBridge::complete and ::fail — is pinned in all three
 * production directions (success, retry, and final-failure), plus the
 * parallel-sibling-pending sub-direction reached when a parallel-group
 * activity succeeds while a sibling is still running, both arms of the
 * already-terminated early return (cancelled-run reached when an outcome
 * arrives for an activity whose parent run has already been cancelled, and
 * terminated-run reached when the parent run has been forcibly terminated),
 * and both arms of the already-terminal block reached when a worker
 * finishes after a parallel sibling has already settled the parent run
 * (Completed-run late-success and Failed-run late-failure), so a
 * wrapper-side refactor cannot bypass the role binding by routing
 * around record() on any direction. The raw record() entry point
 * additionally pins the off-diagonal Completed-run late-failure cell of
 * the already-terminal block — where the late-arriving worker outcome
 * disagrees with the settled run status — so a future change cannot
 * bypass projectRun() by adding a guard that requires the run status
 * and the outcome to agree.
 *
 * V2ActivityExceptionCodecTest::testRetryPathUsesHistoryProjectionRoleBinding
 * carries the same retry-path role pin alongside its codec regression and is
 * expected to remain green; the copy here is the canonical pin so the
 * coverage cannot drift if that codec test is later refactored away.
 */
final class V2ActivityOutcomeHistoryRoleTest extends TestCase
{
    public function testCancelledRunPathUsesHistoryProjectionRoleBinding(): void
    {
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-cancelled',
        );

        $run->forceFill(['status' => RunStatus::Cancelled->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: 'ignored',
            throwable: null,
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_cancelled', $outcome['reason']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testTerminatedRunPathUsesHistoryProjectionRoleBinding(): void
    {
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-terminated',
        );

        $run->forceFill(['status' => RunStatus::Terminated->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: 'ignored',
            throwable: null,
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_terminated', $outcome['reason']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testAlreadyTerminalRunPathUsesHistoryProjectionRoleBinding(): void
    {
        // A late-arriving outcome whose parent run already moved to a terminal
        // status (e.g. failed by a sibling activity) still has to refresh the
        // operator projection through the role binding so the late close-out
        // appears in the summary view.
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-already-completed',
        );

        $run->forceFill(['status' => RunStatus::Completed->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: 'late-success',
            throwable: null,
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testAlreadyTerminalFailedRunFailurePathUsesHistoryProjectionRoleBinding(): void
    {
        // Companion to the already-terminal-completed/late-success pin above:
        // here the parent run is already Failed (e.g. by a sibling activity)
        // and the late-arriving outcome is itself a worker failure. This pins
        // both halves of the early-return that the existing test does not
        // cover — the RunStatus::Failed side of the compound status check and
        // the throwable-true side of the in-block ternaries — so a future
        // change cannot bypass projectRun() by adding a Failed-run-only or
        // failure-outcome-only branch in the already-terminal block.
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-already-failed',
        );

        $run->forceFill(['status' => RunStatus::Failed->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('late failure boom', 23),
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testAlreadyTerminalCompletedRunFailurePathUsesHistoryProjectionRoleBinding(): void
    {
        // Off-diagonal companion to the matched-diagonal already-terminal pins
        // above: testAlreadyTerminalRunPathUsesHistoryProjectionRoleBinding
        // pins Completed-run + late-success and
        // testAlreadyTerminalFailedRunFailurePathUsesHistoryProjectionRoleBinding
        // pins Failed-run + late-failure — together they exercise both halves
        // of the [Completed, Failed] status check and both halves of the
        // in-block ternaries, but only on the matched diagonal (run status
        // agreeing with the worker outcome). This pin drives the off-diagonal
        // cell where the parent run is already Completed (e.g. a parallel
        // sibling drove the run to Completed before this worker landed) but
        // the late-arriving worker outcome is itself a failure, so a future
        // change cannot bypass projectRun() by adding a guard that requires
        // the run status and the outcome to agree (e.g. a fast path keyed on
        // a Completed-run-and-success or Failed-run-and-failure pairing) in
        // the already-terminal block.
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-already-completed-failure',
        );

        $run->forceFill(['status' => RunStatus::Completed->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('late completed-run failure boom', 31),
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testSuccessResumePathUsesHistoryProjectionRoleBinding(): void
    {
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-success',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: 'hello',
            throwable: null,
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testFinalFailureResumePathUsesHistoryProjectionRoleBinding(): void
    {
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-final-failure',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('final boom', 11),
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRetryResumePathUsesHistoryProjectionRoleBinding(): void
    {
        // The retry path schedules a follow-up activity task instead of waking
        // the parent run, but it still has to refresh the operator projection
        // through the role binding so the retry-scheduled event shows up in
        // the summary view. V2ActivityExceptionCodecTest pins the same path
        // alongside its codec regression; this is the canonical, self-contained
        // pin in the dedicated history-role file so the coverage survives any
        // future refactor of that codec test.
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-retry',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: null,
            throwable: new RuntimeException('retry boom', 7),
            maxAttempts: 2,
            backoffSeconds: 1,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task']);
        $this->assertSame(TaskType::Activity, $outcome['next_task']->task_type);
        $this->assertNotSame($task->id, $outcome['next_task']->id);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testParallelSiblingPendingPathUsesHistoryProjectionRoleBinding(): void
    {
        // When an activity in a parallel group succeeds but a sibling is still
        // running, the recorder takes its own early-return branch that refreshes
        // the operator projection without enqueuing a workflow resume task. Pin
        // that exit to the role binding too.
        [$run, , $task, $attempt] = $this->scaffoldParallelLeasedAttempt(
            instanceId: 'issue-678-history-role-parallel-sibling',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::record(
            taskId: $task->id,
            attemptId: $attempt->id,
            attemptCount: 1,
            result: 'hello',
            throwable: null,
            maxAttempts: 1,
            backoffSeconds: 0,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperUsesHistoryProjectionRoleBinding(): void
    {
        // The attempt-keyed wrapper is the entry point production workers reach
        // through DefaultActivityTaskBridge — pin it to the role binding too so
        // a future change cannot bypass projectRun() by routing the wrapper
        // around record() or by introducing its own projection step.
        [$run, , , $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: 'hello',
            throwable: null,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperFailurePathUsesHistoryProjectionRoleBinding(): void
    {
        // Symmetric to the success-path wrapper pin: DefaultActivityTaskBridge::fail
        // is the production failure entry point and forwards a worker throwable
        // through recordForAttempt. Pin the failure direction of the wrapper to
        // the role binding too, so a future change cannot bypass projectRun() by
        // adding a failure-only branch in the wrapper that routes around record()
        // or introduces its own projection step. The scaffolded execution pins
        // max_attempts=1, so the throwable lands on the final-failure-resume
        // exit inside record().
        [$run, , , $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-failure',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: null,
            throwable: new RuntimeException('wrapper failure boom', 13),
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task']);
        $this->assertSame(TaskType::Workflow, $outcome['next_task']->task_type);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperRetryPathUsesHistoryProjectionRoleBinding(): void
    {
        // Third wrapper direction: DefaultActivityTaskBridge::fail forwards a
        // worker throwable for an attempt whose retry-policy snapshot still has
        // budget, so the wrapper drives the retry-resume exit inside record().
        // The success and final-failure wrapper pins above cover the two
        // terminal directions; this pins the in-flight retry direction so a
        // wrapper-side refactor cannot bypass projectRun() by adding a
        // retry-only branch (e.g. a fast path that schedules the retry task
        // without routing through record()) or by introducing its own
        // projection step on the retry direction. Scaffold the attempt with
        // max_attempts=2 so the wrapper-derived retry budget allows a retry on
        // attempt 1.
        [$run, , $task, $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-retry',
            maxAttempts: 2,
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: null,
            throwable: new RuntimeException('wrapper retry boom', 17),
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNotNull($outcome['next_task']);
        $this->assertSame(TaskType::Activity, $outcome['next_task']->task_type);
        $this->assertNotSame($task->id, $outcome['next_task']->id);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperParallelSiblingPendingPathUsesHistoryProjectionRoleBinding(): void
    {
        // Fourth wrapper sub-direction: the success/retry/final-failure
        // wrapper pins above cover the three terminal directions
        // DefaultActivityTaskBridge routes through, but the
        // parallel-sibling-pending early return inside record() — taken when
        // a parallel-group activity succeeds while a sibling is still running
        // — is currently only pinned via the raw record() entry point. Drive
        // the same exit through recordForAttempt so a wrapper-side refactor
        // cannot bypass projectRun() by adding a parallel-group fast path
        // that routes around record() or by introducing its own projection
        // step on this sub-direction.
        [$run, , , $attempt] = $this->scaffoldParallelLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-parallel-sibling',
        );

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: 'hello',
            throwable: null,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperCancelledRunPathUsesHistoryProjectionRoleBinding(): void
    {
        // Fifth wrapper sub-direction: the four "live" wrapper pins above
        // cover the directions where the parent run is still being progressed
        // (success, retry, final-failure, and the parallel-sibling-pending
        // early return), but the run-cancelled early return inside record() —
        // taken when an outcome arrives for an activity whose parent run has
        // already been Cancelled (e.g. by an external operator while the
        // worker was in flight) — is currently only pinned via the raw
        // record() entry point. DefaultActivityTaskBridge::complete and ::fail
        // both reach this exit through recordForAttempt in production, so a
        // wrapper-side refactor that adds a parent-status fast path (e.g. one
        // that short-circuits cancelled runs without entering record()'s
        // transaction) could silently bypass projectRun() on this direction.
        // Drive the cancelled-run early return through recordForAttempt to
        // pin the wrapper to the role binding here too.
        [$run, , , $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-cancelled',
        );

        $run->forceFill(['status' => RunStatus::Cancelled->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: 'ignored',
            throwable: null,
        );

        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_cancelled', $outcome['reason']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperTerminatedRunPathUsesHistoryProjectionRoleBinding(): void
    {
        // Sixth wrapper sub-direction: the cancelled-run wrapper pin above
        // covers the Cancelled arm of the [Cancelled, Terminated] early
        // return inside record(), but the Terminated arm — taken when an
        // outcome arrives for an activity whose parent run has been forcibly
        // Terminated (e.g. by an operator killing a stuck workflow while the
        // worker was in flight) — is currently only pinned via the raw
        // record() entry point. DefaultActivityTaskBridge::complete and ::fail
        // both reach this exit through recordForAttempt in production, so a
        // wrapper-side refactor that adds a Terminated-only fast path (e.g.
        // one that short-circuits terminated runs without entering record()'s
        // transaction, distinct from the cancelled fast path) could silently
        // bypass projectRun() on this direction. Drive the terminated-run
        // early return through recordForAttempt to pin the wrapper to the
        // role binding on the Terminated arm too.
        [$run, , , $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-terminated',
        );

        $run->forceFill(['status' => RunStatus::Terminated->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: 'ignored',
            throwable: null,
        );

        $this->assertFalse($outcome['recorded']);
        $this->assertSame('run_terminated', $outcome['reason']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperAlreadyTerminalCompletedRunPathUsesHistoryProjectionRoleBinding(): void
    {
        // Seventh wrapper sub-direction: the success/retry/final-failure
        // wrapper pins above cover the live record() exits, and the
        // cancelled/terminated wrapper pins cover the operator-driven early
        // return on a still-active run, but the already-terminal block
        // inside record() — taken when a late-arriving outcome lands on a
        // run whose status has already settled to Completed (e.g. driven
        // there by a sibling activity's resume task in a parallel group)
        // — is currently only pinned via the raw record() entry point.
        // DefaultActivityTaskBridge::complete reaches this exit through
        // recordForAttempt in production whenever a worker finishes after
        // a parallel sibling has already closed out the parent run, so a
        // wrapper-side refactor that adds an already-terminal fast path
        // (e.g. one that short-circuits late outcomes on a settled run
        // without entering record()'s transaction) could silently bypass
        // projectRun() on this direction. Drive the already-terminal
        // Completed-run late-success arm through recordForAttempt to pin
        // the wrapper to the role binding on this sub-direction too.
        [$run, , , $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-already-completed',
        );

        $run->forceFill(['status' => RunStatus::Completed->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: 'late-success',
            throwable: null,
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    public function testRecordForAttemptWrapperAlreadyTerminalFailedRunFailurePathUsesHistoryProjectionRoleBinding(): void
    {
        // Eighth wrapper sub-direction, symmetric to the wrapper Completed-run
        // late-success pin above: the raw record() pin
        // testAlreadyTerminalFailedRunFailurePath covers both halves of the
        // already-terminal block that the Completed/late-success pin does not
        // — the RunStatus::Failed side of the [Completed, Failed] status check
        // and the throwable-true side of the in-block ternaries — but only
        // through the raw record() entry point. DefaultActivityTaskBridge::fail
        // reaches this same exit through recordForAttempt in production
        // whenever a worker fails after a parallel sibling has already settled
        // the parent run to Failed, so a wrapper-side refactor that adds a
        // Failed-run-only or failure-outcome-only fast path (e.g. one that
        // short-circuits late failures on a settled-failed run without
        // entering record()'s transaction, distinct from the late-success
        // fast path) could silently bypass projectRun() on this direction.
        // Drive the already-terminal Failed-run late-failure arm through
        // recordForAttempt to pin the wrapper to the role binding here too.
        [$run, , , $attempt] = $this->scaffoldLeasedAttempt(
            instanceId: 'issue-678-history-role-record-for-attempt-already-failed',
        );

        $run->forceFill(['status' => RunStatus::Failed->value])->save();

        $customRole = $this->bindRecordingRole();

        $outcome = ActivityOutcomeRecorder::recordForAttempt(
            attemptId: $attempt->id,
            result: null,
            throwable: new RuntimeException('wrapper late failure boom', 29),
        );

        $this->assertTrue($outcome['recorded']);
        $this->assertNull($outcome['next_task']);
        $this->assertSame([['projectRun', $run->id]], $customRole->calls);
    }

    /**
     * @return object{calls: array<int, array{0: string, 1: string}>}
     */
    private function bindRecordingRole(): object
    {
        $customRole = new class(new DefaultHistoryProjectionRole()) implements HistoryProjectionRole {
            /** @var array<int, array{0: string, 1: string}> */
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

    /**
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask, 3: ActivityAttempt}
     */
    private function scaffoldLeasedAttempt(string $instanceId, int $maxAttempts = 1): array
    {
        $now = now();
        $pinnedCodec = 'workflow-serializer-y';

        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'run_count' => 1,
            'reserved_at' => $now,
            'started_at' => $now,
        ]);

        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'test-greeting-workflow',
            'status' => RunStatus::Waiting->value,
            'arguments' => Serializer::serializeWithCodec($pinnedCodec, ['Taylor']),
            'payload_codec' => $pinnedCodec,
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'last_progress_at' => $now,
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        $attemptId = (string) Str::ulid();

        $execution = ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'status' => ActivityStatus::Running->value,
            'attempt_count' => 1,
            'current_attempt_id' => $attemptId,
            'arguments' => Serializer::serializeWithCodec($pinnedCodec, ['Taylor']),
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => $maxAttempts,
                'backoff_seconds' => [1],
                'start_to_close_timeout' => 60,
                'schedule_to_start_timeout' => null,
            ],
        ]);

        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Leased->value,
            'available_at' => $now,
            'payload' => [
                'activity_execution_id' => $execution->id,
            ],
            'connection' => null,
            'queue' => null,
            'leased_at' => $now,
            'lease_expires_at' => $now->copy()
                ->addMinutes(5),
            'attempt_count' => 1,
        ]);

        $attempt = ActivityAttempt::query()->create([
            'id' => $attemptId,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'workflow_task_id' => $task->id,
            'attempt_number' => 1,
            'status' => ActivityAttemptStatus::Running->value,
            'lease_owner' => $task->id,
            'started_at' => $now,
            'lease_expires_at' => $now->copy()
                ->addMinutes(5),
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
        ]);

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, [
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attemptId,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
            'attempt_number' => 1,
        ], $task);

        return [$run, $execution, $task, $attempt];
    }

    /**
     * Scaffold the leased attempt at sequence 1 with parallel-group metadata,
     * plus a sibling activity execution at sequence 2 left in Running status so
     * `ParallelChildGroup::shouldWakeParentOnActivityClosure` returns false and
     * the recorder takes its parallel-sibling-pending early return.
     *
     * @return array{0: WorkflowRun, 1: ActivityExecution, 2: WorkflowTask, 3: ActivityAttempt}
     */
    private function scaffoldParallelLeasedAttempt(string $instanceId): array
    {
        [$run, $execution, $task, $attempt] = $this->scaffoldLeasedAttempt($instanceId);

        $now = now();
        $pinnedCodec = $run->payload_codec;

        // Sibling at sequence 2, never started, so the parallel group cannot
        // be considered completed yet by groupCompletedSuccessfully().
        ActivityExecution::query()->create([
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'activity_class' => TestGreetingActivity::class,
            'activity_type' => 'test-greeting-activity',
            'status' => ActivityStatus::Running->value,
            'attempt_count' => 1,
            'arguments' => Serializer::serializeWithCodec($pinnedCodec, ['Sibling']),
            'connection' => null,
            'queue' => null,
            'started_at' => $now,
            'retry_policy' => [
                'snapshot_version' => 1,
                'max_attempts' => 1,
                'backoff_seconds' => [1],
                'start_to_close_timeout' => 60,
                'schedule_to_start_timeout' => null,
            ],
        ]);

        // Replace the sequence 1 ActivityScheduled/ActivityStarted history
        // events with versions carrying parallel-group metadata so that
        // ParallelChildGroup::metadataPathForSequence resolves a non-empty path.
        WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('event_type', [
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityStarted->value,
            ])
            ->delete();

        $parallelMetadata = ParallelChildGroup::itemMetadata(
            baseSequence: 1,
            size: 2,
            index: 0,
            kind: 'activity',
        );

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
        ], $parallelMetadata));

        WorkflowHistoryEvent::record($run, HistoryEventType::ActivityStarted, array_merge([
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'activity_class' => $execution->activity_class,
            'activity_type' => $execution->activity_type,
            'sequence' => 1,
            'attempt_number' => 1,
        ], $parallelMetadata), $task);

        return [$run, $execution, $task, $attempt];
    }
}
