<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestRetryWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\WorkflowStub;

final class ActivityOutcomeRejectionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->freezeTime();
        config(['workflows.v2.task_dispatch_mode' => 'poll']);
    }

    public static function lateOutcomes(): array
    {
        return [
            'active completion' => [false, 'completed'],
            'closed completion' => [true, 'completed'],
            'active failure' => [false, 'failed'],
            'closed failure' => [true, 'failed'],
        ];
    }

    #[DataProvider('lateOutcomes')]
    public function testFirstLateOutcomeIsAnImmutableDiagnosticFact(bool $closed, string $outcome): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal($closed);
        $prefix = $run->historyEvents()->orderBy('sequence')->get()->map->getAttributes()->all();
        $before = $this->businessState($run);
        $this->travel(2)->seconds();
        $reply = $this->refusedOutcome($bridge, $claim['activity_attempt_id'], $outcome);

        self::assertFalse($reply['recorded']);
        self::assertSame('stale_attempt', $reply['reason']);
        self::assertNull($reply['next_task_id']);
        $notices = $this->notices($run);
        self::assertCount(1, $notices);
        $notice = $notices[0];
        self::assertSame($claim['task_id'], $notice->workflow_task_id);
        self::assertSame($claim['activity_execution_id'], $notice->payload['activity_execution_id']);
        self::assertSame($claim['activity_attempt_id'], $notice->payload['activity_attempt_id']);
        self::assertSame($claim['lease_owner'], $notice->payload['lease_owner']);
        self::assertSame(1, $notice->payload['attempt_number']);
        self::assertSame($outcome, $notice->payload['outcome']);
        self::assertSame('stale_attempt', $notice->payload['reason']);
        self::assertSame($before, $this->businessState($run));
        self::assertSame($prefix, $run->historyEvents()->orderBy('sequence')->limit(count($prefix))
            ->get()->map->getAttributes()->all());
        foreach (['result', 'failure', 'message', 'stack_trace', 'details', 'task', 'command'] as $field) {
            self::assertArrayNotHasKey($field, $notice->payload);
        }

        $retained = $notice->getAttributes();
        $this->travel(3)->seconds();
        $this->refusedOutcome($bridge, $claim['activity_attempt_id'], $outcome === 'completed' ? 'failed' : 'completed');
        self::assertCount(1, $this->notices($run));
        self::assertSame($retained, $this->notices($run)[0]->getAttributes());
        self::assertSame($before, $this->businessState($run));
    }

    public function testPrunedRunIsNotRepopulatedByARefusedOutcome(): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal();
        $run->forceFill(['details_pruned_at' => now()])->save();
        $before = $this->businessState($run);
        $history = $run->historyEvents()->count();

        self::assertFalse($bridge->complete($claim['activity_attempt_id'], 'late')['recorded']);
        self::assertCount(0, $this->notices($run));
        self::assertSame($history, $run->historyEvents()->count());
        self::assertSame($before, $this->businessState($run));
    }

    public function testAuditAppendRollsBackWithItsStorageTransaction(): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal();
        $before = $this->businessState($run);
        try {
            DB::connection($run->getConnectionName())->transaction(function () use ($bridge, $claim, $run): void {
                $bridge->complete($claim['activity_attempt_id'], 'late');
                self::assertCount(1, $this->notices($run));
                throw new RuntimeException('rollback diagnostic');
            });
            self::fail('The outer transaction must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback diagnostic', $exception->getMessage());
        }
        self::assertCount(0, $this->notices($run));
        self::assertSame($before, $this->businessState($run));
    }

    public function testRetryingACommittedBusinessOutcomeCreatesNoRejectionAudit(): void
    {
        [$bridge, $claim, $run] = $this->claimedOriginal();
        self::assertTrue($bridge->complete($claim['activity_attempt_id'], 'accepted')['recorded']);
        $history = $run->historyEvents()->count();

        self::assertFalse($bridge->complete($claim['activity_attempt_id'], 'duplicate')['recorded']);
        self::assertCount(0, $this->notices($run));
        self::assertSame($history, $run->historyEvents()->count());
    }

    private function expiredOriginal(bool $closed = false): array
    {
        [$bridge, $claim, $run] = $this->claimedOriginal();
        $this->travel(301)->seconds();
        self::assertTrue(ActivityTimeoutEnforcer::enforceLeaseExpiry($claim['task_id'])['enforced']);
        if ($closed) {
            $this->travel(10)->seconds();
            $retry = $bridge->poll(null, null, 1)[0];
            $replacement = $bridge->claimStatus($retry['task_id'], 'replacement-worker');
            self::assertTrue($replacement['claimed']);
            self::assertTrue($bridge->complete($replacement['activity_attempt_id'], 'accepted')['recorded']);
            $this->runWorkflowTask();
            self::assertTrue($run->fresh()->status->isTerminal());
        }

        return [$bridge, $claim, $run];
    }

    private function claimedOriginal(): array
    {
        $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'outcome-rejection-audit');
        $workflow->start('Ada');
        $this->runWorkflowTask();
        $bridge = $this->app->make(ActivityTaskBridge::class);
        $task = $bridge->poll(null, null, 1)[0];
        $claim = $bridge->claimStatus($task['task_id'], 'original-worker');
        self::assertTrue($claim['claimed']);

        return [$bridge, $claim, WorkflowRun::query()->findOrFail($workflow->runId())];
    }

    private function runWorkflowTask(): void
    {
        $task = WorkflowTask::query()->where('task_type', TaskType::Workflow)
            ->where('status', TaskStatus::Ready)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function refusedOutcome(ActivityTaskBridge $bridge, string $attemptId, string $outcome): array
    {
        return $outcome === 'completed'
            ? $bridge->complete($attemptId, new class {
                public function __serialize(): array
                {
                    throw new RuntimeException('Rejected output must never be serialized.');
                }
            })
            : $bridge->fail($attemptId, ['message' => 'private failure that must not enter audit history']);
    }

    private function notices(WorkflowRun $run): array
    {
        return $run->historyEvents()->where('event_type', 'ActivityOutcomeRejected')->get()->all();
    }

    private function businessState(WorkflowRun $run): array
    {
        $attributes = $run->fresh()->getAttributes();
        unset($attributes['last_history_sequence'], $attributes['updated_at']);

        return [
            $attributes,
            WorkflowTask::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()->map->getAttributes()->all(),
            ActivityExecution::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()->map->getAttributes()->all(),
            ActivityAttempt::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }
}
