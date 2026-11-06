<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\CooperativeWorkflowTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityRetryTaskBridge;
use Workflow\V2\Contracts\PreparedLocalActivityTaskBridge;
use Workflow\V2\Contracts\WorkflowTaskBridge;
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
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\HistoryTimeline;
use Workflow\V2\Support\LocalActivityCall;
use Workflow\V2\Support\LocalActivityExecutor;
use Workflow\V2\Support\PortableLocalActivityPreparation;
use Workflow\V2\WorkflowStub;

final class V2PortableLocalActivityRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-02T04:20:00.000000Z');
        config()
            ->set('workflows.v2.compatibility.current', 'build-a');
        config()
            ->set('workflows.v2.compatibility.supported', ['build-a']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('interruptionDelays')]
    public function testRetryContractRecoveryUsesOnePolicyDecisionWithoutAdmittingAnotherAttempt(int $delay): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall([
            'backoff_seconds' => [$delay],
        ]);
        $sample = now()
            ->toISOString();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $this->assertCount(13, $reply);
        $this->assertArrayNotHasKey('recorded', $reply);
        $this->assertSame('unknown', $reply['callback_stop_state']);
        $this->assertSame('engine_interruption', $reply['retry_disposition']['failure_source']);
        $this->assertSame($sample, $reply['retry_disposition']['failure_time']);
        $this->assertSame('policy', $reply['retry_disposition']['delay_source']);
        $this->assertSame($delay, $reply['retry_disposition']['candidate_delay_seconds']);
        $this->assertSame(now()->addSeconds($delay)->toISOString(), $reply['retry_disposition']['retry_available_at']);
        $this->assertArrayNotHasKey('timeout_kind', $reply['retry_disposition']);
        $this->assertArrayNotHasKey('requested_delay_seconds', $reply['retry_disposition']);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('cold_replay', $event->payload['retry_reason']);
        $this->assertCount(10, $event->payload['local_recovery']);
        $this->assertSame(1, $event->payload['local_recovery']['version']);
        $this->assertSame(7, $event->payload['local_recovery']['original_workflow_task_attempt']);
        $this->assertSame(8, $event->payload['local_recovery']['workflow_task_attempt']);
        $attempt = ActivityAttempt::query()->findOrFail($first['activity_attempt_id']);
        $this->assertSame(ActivityAttemptStatus::Expired, $attempt->status);
        $this->assertSame($sample, $attempt->closed_at->toISOString());
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertCount(1, $reply['created_task_ids']);
        $this->assertSame(1, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
        $this->assertSame(2, $run->tasks()->count());
        $this->assertRecoveryReply([
            ...$reply,
            'duplicate' => true,
        ], $this->recoverRetryContract($task, $descriptor));
        $this->assertSame(3, $run->historyEvents()->count());
    }

    public static function interruptionDelays(): iterable
    {
        yield 'zero remains selected' => [0];
        yield 'positive policy delay' => [2];
    }

    #[DataProvider('interruptionTerminals')]
    public function testRetryContractRecoveryTerminatesWithoutAnExtraTaskOrAttempt(array $policy, string $reason): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall($policy);
        $before = $task->getAttributes();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $this->assertSame('ActivityFailed', $reply['event_type']);
        $this->assertSame('engine_interruption', $reply['retry_disposition']['failure_source']);
        $this->assertSame($reason, $reply['retry_disposition']['terminal_classification']);
        $this->assertArrayNotHasKey('retry_available_at', $reply['retry_disposition']);
        $this->assertSame([], $reply['created_task_ids']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertSame(1, ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count);
        $this->assertRecoveryReply([
            ...$reply,
            'duplicate' => true,
        ], $this->recoverRetryContract($task, $descriptor));
    }

    public static function interruptionTerminals(): iterable
    {
        yield 'policy nonretryable precedes budget' => [[
            'max_attempts' => 1,
            'non_retryable_error_types' => [\RuntimeException::class],
        ], 'non_retryable'];
        yield 'attempt budget' => [[
            'max_attempts' => 1,
        ], 'attempts_exhausted'];
        yield 'original total deadline' => [[
            'backoff_seconds' => [30],
        ], 'retry_delay_exceeds_schedule_to_close'];
    }

    #[DataProvider('recoveryTimeouts')]
    public function testRetryContractRecoveryRetainsActualTimeoutAuthority(
        int $total,
        int $start,
        array $timeouts,
        string $kind,
        bool $scheduled
    ): void {
        [$run, $task, , $descriptor] = $this->reclaimedRetryCall(
            totalTimeout: $total,
            startTimeout: $start,
            timeouts: $timeouts
        );
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $decision = $reply['retry_disposition'];
        $this->assertSame('engine_timeout', $decision['failure_source']);
        $this->assertSame($kind, $decision['timeout_kind']);
        $this->assertSame($scheduled ? 'ActivityRetryScheduled' : 'ActivityTimedOut', $reply['event_type']);
        $this->assertSame($scheduled, $reply['claim_released']);
        $this->assertCount($scheduled ? 1 : 0, $reply['created_task_ids']);
        $this->assertSame($scheduled ? 2 : 1, $run->tasks()->count());
        $this->assertRecoveryReply([
            ...$reply,
            'duplicate' => true,
        ], $this->recoverRetryContract($task, $descriptor));
    }

    public static function recoveryTimeouts(): iterable
    {
        yield 'total deadline precedes interruption' => [4, 4, [], 'schedule_to_close', false];
        yield 'start deadline still retries policy' => [30, 4, [], 'start_to_close', true];
        yield 'heartbeat precedes start timeout' => [30, 4, [
            'heartbeat_timeout' => 3,
        ], 'heartbeat', true];
    }

    public function testRetryRecoveryDuplicateAfterDeadlineKeepsItsOriginalAttemptAndDecision(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        $retry->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'next-worker',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        Carbon::setTestNow(now()->addSeconds(2));
        $next = PortableLocalActivityPreparation::prepareWithRetryPolicy(
            $retry->id,
            'next-worker',
            1,
            1,
            'next-local-attempt',
            $descriptor,
            '1.20'
        );
        $this->assertTrue($next['prepared'], $next['reason'] ?? '');
        $this->assertNotSame($first['activity_attempt_id'], $next['activity_attempt_id']);
        Carbon::setTestNow(now()->addMinutes(2));
        config()
            ->set('workflows.v2.default_retry_policy', [
                'max_attempts' => 1,
            ]);
        $before = $run->historyEvents()
            ->count();
        $this->assertRecoveryReply([
            ...$reply,
            'duplicate' => true,
        ], $this->recoverRetryContract($task, $descriptor));
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame('unknown', $reply['callback_stop_state']);
        $this->assertSame(2, ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count);
    }

    #[DataProvider('recoverySnapshotMutations')]
    public function testCorruptRecoveryAuthorityCannotPublishOrReturnAReceipt(bool $committed, bool $invalidCap): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall([
            'backoff_seconds' => [5],
            'maximum_interval_seconds' => 20,
        ]);
        $reply = $committed ? $this->recoverRetryContract($task, $descriptor) : null;
        $execution = ActivityExecution::query()->findOrFail($first['activity_execution_id']);
        $execution->forceFill([
            'retry_policy' => [
                ...$execution->retry_policy,
                'backoff_seconds' => [$invalidCap ? 21 : 3],
            ],
        ])->save();
        $before = [$run->historyEvents()->count(), $run->tasks()->count(), $execution->refresh()->getAttributes(),
            ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->getAttributes()];
        $rejected = $this->recoverRetryContract($task, $descriptor);
        $this->assertFalse($rejected['recovered']);
        $this->assertSame('local_activity_preparation_mismatch', $rejected['reason']);
        $this->assertArrayNotHasKey('retry_disposition', $rejected);
        $this->assertArrayNotHasKey('event_id', $rejected);
        $this->assertSame(
            $before,
            [$run->historyEvents()->count(), $run->tasks()->count(), $execution->refresh()->getAttributes(),
                ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->getAttributes()]
        );
        if ($reply !== null) {
            $this->assertSame(
                $reply['retry_disposition'],
                WorkflowHistoryEvent::query()->findOrFail($reply['event_id'])->payload['retry_disposition']
            );
        }
    }

    public static function recoverySnapshotMutations(): iterable
    {
        yield 'invalid retained cap before selection' => [false, true];
        yield 'valid but replaced original snapshot before selection' => [false, false];
        yield 'invalid retained cap after committed receipt' => [true, true];
        yield 'valid but replaced original snapshot after committed receipt' => [true, false];
    }

    #[DataProvider('recoveryReceiptMutations')]
    public function testRecoveryReceiptCannotChangeItsClosedIdentityOrDecision(string $location, mixed $value): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedRetryCall();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $payload = $event->payload;
        data_set($payload, $location, $value);
        $event->forceFill([
            'payload' => $payload,
        ])->save();
        $before = $run->historyEvents()
            ->count();
        $refused = $this->recoverRetryContract($task, $descriptor);
        $this->assertSame('local_activity_recovery_mismatch', $refused['reason']);
        $this->assertArrayNotHasKey('event_id', $refused);
        $this->assertSame($before, $run->historyEvents()->count());
        $expectedRecovery = $payload['local_recovery'];
        $actualRecovery = $event->refresh()
->payload['local_recovery'];
        ksort($expectedRecovery);
        ksort($actualRecovery);
        $this->assertSame($expectedRecovery, $actualRecovery);
    }

    public static function recoveryReceiptMutations(): iterable
    {
        yield 'worker source substitution' => ['retry_disposition.failure_source', 'application'];
        yield 'worker override injection' => ['retry_disposition.requested_delay_seconds', 1];
        yield 'timeout injection' => ['retry_disposition.timeout_kind', 'heartbeat'];
        yield 'unknown metadata member' => ['local_recovery.extra', true];
        yield 'stop state cannot be rewritten' => ['local_recovery.callback_stop_state', 'stopped'];
        yield 'original owner substituted' => ['local_recovery.original_lease_owner', 'foreign'];
        yield 'retry delay substituted' => ['retry_backoff_seconds', 0];
        yield 'outcome and recovery authority mixed' => ['local_outcome.version', 2];
    }

    public function testRetryPreparationRefusesMixedOutcomeAndRecoveryAuthority(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $event->forceFill([
            'payload' => [
                ...$event->payload,
                'local_outcome' => [
                    'version' => 2,
                ],
            ],
        ])->save();
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        $retry->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'next-worker',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        Carbon::setTestNow(now()->addSeconds(2));
        $refused = PortableLocalActivityPreparation::prepareWithRetryPolicy(
            $retry->id,
            'next-worker',
            1,
            1,
            'next-local-attempt',
            $descriptor,
            '1.20'
        );
        $this->assertSame('local_activity_retry_preparation_mismatch', $refused['reason']);
        $this->assertSame(3, $run->historyEvents()->count());
        $this->assertSame(1, ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count);
    }

    public function testRetryRecoveryRollbackPublishesNoFailureSampleReceiptOrTask(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall();
        $connection = ConfiguredV2Models::connection();
        $before = $task->getAttributes();
        $connection->beginTransaction();
        try {
            $reply = $this->recoverRetryContract($task, $descriptor);
            $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
            $this->assertSame('engine_interruption', $reply['retry_disposition']['failure_source']);
            $this->assertSame(3, $run->historyEvents()->count());
        } finally {
            $connection->rollBack();
        }
        $this->assertSame(2, $run->historyEvents()->count());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(
            ActivityAttemptStatus::Running,
            ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->status
        );
    }

    public function testRetryRecoveryCannotUseAStillLiveIndependentOriginalClaim(): void
    {
        [$run, $original, , $descriptor] = $this->reclaimedRetryCall(reclaim: false);
        $task = $original->replicate();
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $original->forceFill([
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertSame('local_activity_original_claim_live', $reply['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    public function testRetryRecoveryKeepsIndependentRecoveringAndOriginalTaskIdentities(): void
    {
        [$run, $original, $first, $descriptor] = $this->reclaimedRetryCall(reclaim: false);
        $task = $original->replicate();
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $reply = $this->recoverRetryContract($task, $descriptor);
        $this->assertTrue($reply['recovered'], $reply['reason'] ?? '');
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame($task->id, $reply['workflow_task_id']);
        $this->assertSame($task->id, $event->payload['local_recovery']['workflow_task_id']);
        $this->assertSame($original->id, $event->payload['local_recovery']['original_workflow_task_id']);
        $this->assertSame($original->id, $event->payload['activity_attempt']['task_id']);
        $this->assertSame(TaskStatus::Completed, $original->refresh()->status);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertSame(1, ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count);
        $this->assertSame(3, $run->tasks()->count());
    }

    public function testRetryRecoveryCannotUpgradeAnOriginalVersionOnePreparation(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $versioned = [
            ...$descriptor,
            'retry_policy' => [
                'contract_version' => 2,
                'mode' => 'explicit',
                'max_attempts' => 3,
                'backoff_seconds' => [2],
            ],
        ];
        $refused = $this->recoverRetryContract($task, $versioned);
        $this->assertSame('local_activity_preparation_mismatch', $refused['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
        $legacy = $this->recover($task, $descriptor);
        $this->assertTrue($legacy['recovered']);
        $this->assertArrayNotHasKey('retry_disposition', $legacy);
    }

    public function testConcurrentRetryRecoveriesSerializeOneDecisionAndReturnTheOriginalAfterDeadline(): void
    {
        if (ConfiguredV2Models::connection()->getDriverName() !== 'mysql'
            || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The two-process lock control requires MySQL and pcntl.');
        }
        self::stopWorkers();
        [$run, $task, $first, $descriptor] = $this->reclaimedRetryCall();
        $connectionName = ConfiguredV2Models::connection()->getName();
        $table = (new ActivityAttempt())->getTable();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($sockets);
        stream_set_timeout($sockets[0], 10);
        stream_set_timeout($sockets[1], 10);
        DB::purge($connectionName);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            try {
                $connection = DB::reconnect($connectionName);
                $announced = false;
                $connection->beforeExecuting(static function (string $query) use ($sockets, $table, &$announced): void {
                    if (! $announced && str_contains($query, $table) && str_contains(
                        strtolower($query),
                        'for update'
                    )) {
                        $announced = true;
                        fwrite($sockets[1], "locking\n");
                    }
                });
                fwrite($sockets[1], "ready\n");
                if (fgets($sockets[1]) !== "recover\n") {
                    throw new \LogicException('Recovery race was not released.');
                }
                Carbon::setTestNow(now()->addMinutes(2));
                $reply = PortableLocalActivityPreparation::recoverWithRetryPolicy(
                    $task->id,
                    'replacement-worker',
                    8,
                    1,
                    $descriptor,
                    '1.20'
                );
                fwrite($sockets[1], json_encode($reply, JSON_THROW_ON_ERROR) . "\n");
                fclose($sockets[1]);
                exit(0);
            } catch (Throwable $error) {
                fwrite($sockets[1], json_encode([
                    'error' => substr($error->getMessage(), 0, 800),
                ]) . "\n");
                fclose($sockets[1]);
                exit(1);
            }
        }
        fclose($sockets[1]);
        $connection = DB::reconnect($connectionName);
        try {
            $this->assertSame("ready\n", fgets($sockets[0]));
            $connection->beginTransaction();
            ActivityAttempt::query()->lockForUpdate()->findOrFail($first['activity_attempt_id']);
            fwrite($sockets[0], "recover\n");
            $this->assertSame("locking\n", fgets($sockets[0]));
            $winner = $this->recoverRetryContract($task, $descriptor);
            $this->assertTrue($winner['recovered'], $winner['reason'] ?? '');
            $connection->commit();
            $line = fgets($sockets[0]);
            $this->assertIsString($line);
            $loser = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $this->assertRecoveryReply([
                ...$winner,
                'duplicate' => true,
            ], $loser);
            $until = microtime(true) + 5;
            do {
                $exited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($exited === 0) {
                    usleep(10000);
                }
            } while ($exited === 0 && microtime(true) < $until);
            $this->assertSame($pid, $exited);
            $pid = 0;
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame(3, $run->historyEvents()->count());
            $this->assertSame(2, $run->tasks()->count());
            $this->assertSame(
                1,
                ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count
            );
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            fclose($sockets[0]);
            if ($pid > 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
            DB::purge($connectionName);
            DB::reconnect($connectionName);
        }
    }

    public function testColdRecoveryFencesTheOldAttemptAndPreparesARealRetryUnderTheOriginalBudget(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall();
        $reply = $this->recover($task, $descriptor);
        $this->assertTrue($reply['recovered']);
        $this->assertFalse($reply['duplicate']);
        $this->assertTrue($reply['claim_released']);
        $this->assertSame('unknown', $reply['callback_stop_state']);
        $this->assertSame('ActivityRetryScheduled', $reply['event_type']);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $this->assertNull($task->lease_expires_at);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('cold_replay', $event->payload['retry_reason']);
        $this->assertSame(7, $event->payload['local_recovery']['original_workflow_task_attempt']);
        $this->assertSame(8, $event->payload['local_recovery']['workflow_task_attempt']);
        $this->assertSame('original-worker', $event->payload['local_recovery']['original_lease_owner']);
        $this->assertSame('replacement-worker', $event->payload['local_recovery']['lease_owner']);
        $this->assertSame('expired', $event->payload['activity_attempt']['status']);
        $this->assertSame(8, $event->payload['task']['attempt_count']);
        $this->assertSame(1, $event->payload['activity_attempt']['attempt_number']);
        $timeline = collect(HistoryTimeline::forRun($run->fresh()))->firstWhere('id', $event->id);
        $this->assertSame('unknown', $timeline['local_recovery']['callback_stop_state']);
        $this->assertSame(7, $timeline['local_recovery']['original_workflow_task_attempt']);
        $this->assertSame(8, $timeline['local_recovery']['workflow_task_attempt']);
        $this->assertStringContainsString('Callback stop is unknown', $timeline['summary']);
        $execution = ActivityExecution::query()->findOrFail($first['activity_execution_id']);
        $this->assertSame(ActivityStatus::Pending, $execution->status);
        $this->assertSame(ActivityAttemptStatus::Expired, $execution->attempts()->sole()->status);
        $this->assertSame(1, $execution->attempt_count);
        $this->assertSame(
            $first['schedule_to_close_deadline_at'],
            $execution->schedule_to_close_deadline_at->toISOString()
        );
        $this->assertSame('stale_activity_attempt', $this->lateResult($first)['reason']);
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        $this->assertSame(0, $run->tasks()->where('task_type', TaskType::Activity)->count());
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        $retry->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'next-worker',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $this->assertSame('local_activity_retry_not_due', $this->prepareRetry($retry, $descriptor)['reason']);
        Carbon::setTestNow(now()->addSeconds(2));
        $second = $this->prepareRetry($retry, $descriptor);
        $this->assertTrue($second['prepared']);
        $this->assertSame(2, $second['attempt_number']);
        $this->assertSame(1, $second['workflow_task_attempt']);
        $this->assertNotSame($first['activity_attempt_id'], $second['activity_attempt_id']);
        $this->assertSame($first['schedule_to_close_deadline_at'], $second['schedule_to_close_deadline_at']);
        $result = $this->bridge()
            ->recordLocalActivityOutcome($second['activity_attempt_id'], 'next-worker', 1, $this->success(), '1.20');
        $this->assertTrue($result['recorded']);
        $replayed = app(LocalActivityExecutor::class)->execute(
            $run->fresh(),
            $retry->fresh(),
            1,
            new LocalActivityCall('python-local-opaque', ['Taylor'])
        );
        $this->assertSame($result['event_id'], $replayed['event']->id);
        $before = $task->refresh()
            ->getAttributes();
        $historyCount = $run->historyEvents()
            ->count();
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($historyCount, $run->historyEvents()->count());
    }

    public function testAnIndependentRecoveryClaimRetiresTheExpiredOriginalTask(): void
    {
        [$run, $original, $first, $descriptor] = $this->reclaimedCall(reclaim: false);
        $task = $original->replicate();
        $task->save();
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $reply = $this->recover($task, $descriptor);
        $this->assertTrue($reply['recovered']);
        $this->assertSame(TaskStatus::Completed, $original->refresh()->status);
        $this->assertNull($original->lease_expires_at);
        $this->assertSame(TaskStatus::Completed, $task->refresh()->status);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame($task->id, $event->workflow_task_id);
        $this->assertSame($original->id, $event->payload['local_recovery']['original_workflow_task_id']);
        $this->assertSame($original->id, $event->payload['activity_attempt']['task_id']);
        $this->assertSame(3, $run->tasks()->count());
        $this->assertSame('stale_activity_attempt', $this->lateResult($first)['reason']);
    }

    public function testResponseLossReturnsItsReceiptAfterTakeoverAndLaterCancellationWithoutRenewal(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $first = $this->recover($task, $descriptor);
        $task->forceFill([
            'lease_owner' => 'another-worker',
            'attempt_count' => 9,
            'lease_expires_at' => now()
                ->subSecond(),
        ])->save();
        $this->assertTrue(WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30)->accepted());
        $deadline = $run->refresh()
            ->cancellation_deadline_at->toISOString();
        $before = $task->refresh()
            ->getAttributes();
        $historyCount = $run->historyEvents()
            ->count();
        $this->assertSame([
            ...$first,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame($historyCount, $run->historyEvents()->count());
    }

    public function testChangedRecoveryContentsCannotRelabelAReceipt(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $this->assertTrue($this->recover($task, $descriptor)['recovered']);
        $descriptor['schedule_to_close_timeout'] = 60;
        $count = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_recovery_mismatch', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($count, $run->historyEvents()->count());
    }

    public function testRetryExhaustionRecordsOneReplayableFailureAndRetainsTheReplacementClaim(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(maxAttempts: 1);
        $before = $task->getAttributes();
        $reply = $this->recover($task, $descriptor);
        $this->assertTrue($reply['recovered']);
        $this->assertSame('ActivityFailed', $reply['event_type']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame([], $reply['created_task_ids']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(1, $run->failures()->count());
        $this->assertSame(
            ActivityAttemptStatus::Expired,
            ActivityAttempt::query()->findOrFail($first['activity_attempt_id'])->status
        );
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
        $this->assertSame(1, $run->failures()->count());
        $this->assertSame(1, $run->tasks()->count());
        $replayed = app(LocalActivityExecutor::class)->execute(
            $run->fresh(),
            $task->fresh(),
            1,
            new LocalActivityCall('python-local-opaque', ['Taylor'])
        );
        $this->assertSame($reply['event_id'], $replayed['event']->id);
        $this->assertSame('failed', $replayed['status']);
    }

    public function testTheOriginalTotalDeadlineOverridesColdReplayWithoutAnotherAttempt(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(totalTimeout: 4);
        $before = $task->getAttributes();
        $reply = $this->recover($task, $descriptor);
        $this->assertSame('ActivityTimedOut', $reply['event_type']);
        $this->assertFalse($reply['claim_released']);
        $this->assertSame([], $reply['created_task_ids']);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('schedule_to_close', $event->payload['timeout_kind']);
        $this->assertSame('unknown', $event->payload['local_recovery']['callback_stop_state']);
        $this->assertSame('expired', $event->payload['activity_attempt']['status']);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(1, $run->tasks()->count());
        $this->assertSame(1, ActivityExecution::query()->findOrFail($first['activity_execution_id'])->attempt_count);
        $this->assertSame([
            ...$reply,
            'duplicate' => true,
        ], $this->recover($task, $descriptor));
    }

    public function testAnExpiredPerAttemptDeadlineUsesTheExistingTimeoutRetryPolicy(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(startTimeout: 4);
        $reply = $this->recover($task, $descriptor);
        $this->assertSame('ActivityRetryScheduled', $reply['event_type']);
        $event = WorkflowHistoryEvent::query()->findOrFail($reply['event_id']);
        $this->assertSame('timeout', $event->payload['retry_reason']);
        $this->assertSame('start_to_close', $event->payload['timeout_kind']);
        $retry = WorkflowTask::query()->findOrFail($reply['created_task_ids'][0]);
        Carbon::setTestNow(now()->addSeconds(2));
        $retry->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'next-worker',
            'attempt_count' => 1,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $prepared = $this->prepareRetry($retry, $descriptor);
        $this->assertTrue($prepared['prepared']);
        $this->assertSame($first['schedule_to_close_deadline_at'], $prepared['schedule_to_close_deadline_at']);
    }

    public function testALivePreviousAttemptCannotBeRecoveredEvenAfterTaskOwnershipChanges(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall(elapsed: 3);
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_previous_attempt_live', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
        $this->assertSame(TaskStatus::Leased, $task->refresh()->status);
    }

    public function testALiveOriginalHostingClaimCannotBeRecoveredThroughAnotherTask(): void
    {
        [$run, $original, , $descriptor] = $this->reclaimedCall(reclaim: false);
        $task = $original->replicate();
        $task->save();
        $task->forceFill([
            'lease_owner' => 'replacement-worker',
            'attempt_count' => 8,
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $original->forceFill([
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_original_claim_live', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testTheSameClaimEpochCannotTurnAMissedLocalLeaseIntoColdRecovery(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $task->forceFill([
            'attempt_count' => 7,
        ])->save();
        $reply = PortableLocalActivityPreparation::recover($task->id, 'replacement-worker', 7, 1, $descriptor, '1.20');
        $this->assertSame('local_activity_original_claim_not_reclaimed', $reply['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    public function testCancellationFencesBeforeLeaseExpiryAndStillRequiresTheOriginalSupervisorsStopReceipt(): void
    {
        [$run, $task, $first, $descriptor] = $this->reclaimedCall(elapsed: 3);
        $request = WorkflowStub::loadRun($run->id)->requestCancellation('stop', 30);
        $this->assertTrue($request->accepted());
        $deadline = $run->refresh()
            ->cancellation_deadline_at->toISOString();
        $before = $task->getAttributes();
        $reply = $this->recover($task, $descriptor);
        $this->assertFalse($reply['recovered']);
        $this->assertSame('cancellation_requested', $reply['reason']);
        $this->assertTrue($reply['fenced']);
        $this->assertSame($reply, $this->recover($task, $descriptor));
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityRetryScheduled)->count()
        );
        $this->assertSame(
            0,
            $run->historyEvents()
                ->where('event_type', HistoryEventType::ActivityCancellationAcknowledged)->count()
        );
        $acknowledgement = $this->bridge()
            ->acknowledgeLocalActivityCancellation(
                $first['activity_attempt_id'],
                'original-worker',
                $request->commandId(),
                7,
                '1.20'
            );
        $this->assertTrue($acknowledgement['acknowledged']);
        $this->assertSame($deadline, $run->refresh()->cancellation_deadline_at->toISOString());
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public function testMissingOriginalStartCannotBeReplacedWithMutableRunningRows(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $run->historyEvents()
            ->where('event_type', HistoryEventType::ActivityStarted)->delete();
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_preparation_mismatch', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testChangedDescriptorCannotRecoverAnotherOperation(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $descriptor['arguments'] = Serializer::serializeWithCodec('avro', ['different']);
        $before = $run->historyEvents()
            ->count();
        $this->assertSame('local_activity_preparation_mismatch', $this->recover($task, $descriptor)['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    public function testProtocol119CannotEnterThePreparedRecoveryPath(): void
    {
        [$run, $task, , $descriptor] = $this->reclaimedCall();
        $reply = PortableLocalActivityPreparation::recover($task->id, 'replacement-worker', 8, 1, $descriptor, '1.19');
        $this->assertSame('local_activity_recovery_requires_protocol_1_20', $reply['reason']);
        $this->assertSame(2, $run->historyEvents()->count());
    }

    public function testPreparedLocalRoleUsesTheExistingWorkflowBindingWithoutExpandingCustomBridges(): void
    {
        $this->assertSame(app(WorkflowTaskBridge::class), $this->bridge());
        $this->assertFalse($this->app->bound(PreparedLocalActivityRetryTaskBridge::class));
        $this->assertNotInstanceOf(PreparedLocalActivityRetryTaskBridge::class, $this->bridge());
        foreach ([WorkflowTaskBridge::class, CooperativeWorkflowTaskBridge::class] as $contract) {
            $custom = \Mockery::mock($contract);
            $this->app->instance(WorkflowTaskBridge::class, $custom);
            $resolved = $this->app->make(PreparedLocalActivityTaskBridge::class);
            $this->assertSame($custom, $resolved);
            $this->assertNotInstanceOf(PreparedLocalActivityTaskBridge::class, $resolved);
            $this->assertNotInstanceOf(PreparedLocalActivityRetryTaskBridge::class, $resolved);
        }
    }

    public function testLocalStopAdmissionRejectsProtocol119ThroughTheOptionalRole(): void
    {
        [$run, , $first] = $this->reclaimedCall();
        $before = $run->historyEvents()
            ->count();
        $reply = $this->bridge()
            ->acknowledgeLocalActivityCancellation(
                $first['activity_attempt_id'],
                'original-worker',
                'not-a-recorded-request',
                7,
                '1.19'
            );
        $this->assertFalse($reply['acknowledged']);
        $this->assertSame('local_activity_stop_receipt_requires_protocol_1_20', $reply['reason']);
        $this->assertSame($before, $run->historyEvents()->count());
    }

    private function assertRecoveryReply(array $expected, array $actual): void
    {
        // Native JSON storage may reorder object keys. Preserve exact scalar
        // types and every field while comparing the closed disposition.
        if (isset($expected['retry_disposition'], $actual['retry_disposition'])) {
            ksort($expected['retry_disposition']);
            ksort($actual['retry_disposition']);
        }
        $this->assertSame($expected, $actual);
    }

    private function recoverRetryContract(WorkflowTask $task, array $descriptor): array
    {
        return PortableLocalActivityPreparation::recoverWithRetryPolicy(
            $task->id,
            'replacement-worker',
            8,
            1,
            $descriptor,
            '1.20'
        );
    }

    private function reclaimedRetryCall(
        array $policy = [],
        int $totalTimeout = 30,
        int $startTimeout = 20,
        bool $reclaim = true,
        array $timeouts = [],
    ): array {
        return $this->reclaimedCall(
            maxAttempts: $policy['max_attempts'] ?? 3,
            totalTimeout: $totalTimeout,
            startTimeout: $startTimeout,
            reclaim: $reclaim,
            versionedPolicy: [
                'contract_version' => 2,
                'mode' => 'explicit',
                'max_attempts' => 3,
                'backoff_seconds' => [2],
                ...$policy,
            ],
            timeouts: $timeouts
        );
    }

    private function bridge(): PreparedLocalActivityTaskBridge
    {
        return $this->app->make(PreparedLocalActivityTaskBridge::class);
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function recover(WorkflowTask $task, array $descriptor): array
    {
        return $this->bridge()
            ->recoverLocalActivity($task->id, 'replacement-worker', 8, 1, $descriptor, '1.20');
    }

    /** @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    private function prepareRetry(WorkflowTask $task, array $descriptor): array
    {
        return $this->bridge()
            ->prepareLocalActivity($task->id, 'next-worker', 1, 1, 'new-local-attempt', $descriptor, '1.20');
    }

    /** @param array<string, mixed> $first
     * @return array<string, mixed>
     */
    private function lateResult(array $first): array
    {
        return $this->bridge()
            ->recordLocalActivityOutcome($first['activity_attempt_id'], 'original-worker', 7, $this->success(), '1.20');
    }

    /**
     * @return array<string, mixed>
     */
    private function success(): array
    {
        return [
            'outcome' => 'completed',
            'payload_codec' => 'avro',
            'result' => Serializer::serializeWithCodec('avro', 'completed'),
        ];
    }

    /**
     * @return array{WorkflowRun, WorkflowTask, array<string, mixed>, array<string, mixed>}
     */
    private function reclaimedCall(
        int $elapsed = 6,
        int $maxAttempts = 3,
        int $totalTimeout = 30,
        int $startTimeout = 20,
        bool $reclaim = true,
        ?array $versionedPolicy = null,
        array $timeouts = [],
    ): array {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'run_count' => 1,
            'started_at' => now()
                ->subMinute(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => 'portable-parent',
            'status' => RunStatus::Waiting,
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'started_at' => now()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        $task = WorkflowTask::query()->create([
            'workflow_run_id' => $run->id,
            'task_type' => TaskType::Workflow,
            'status' => TaskStatus::Leased,
            'attempt_count' => 7,
            'payload' => [],
            'connection' => 'database',
            'queue' => 'default',
            'compatibility' => 'build-a',
            'lease_owner' => 'original-worker',
            'lease_expires_at' => now()
                ->addSeconds(5),
        ])->refresh();
        $descriptor = [
            'type' => 'record_local_activity',
            'activity_type' => 'python-local-opaque',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'payload_codec' => 'avro',
            'start_to_close_timeout' => min($startTimeout, $totalTimeout),
            'schedule_to_close_timeout' => $totalTimeout,
            'retry_policy' => [
                'max_attempts' => $maxAttempts,
                'backoff_seconds' => [2],
            ],
            ...$timeouts,
        ];
        if ($versionedPolicy !== null) {
            $descriptor['retry_policy'] = $versionedPolicy;
        }
        $first = $versionedPolicy === null ? $this->bridge()
            ->prepareLocalActivity($task->id, 'original-worker', 7, 1, 'original-local-attempt', $descriptor, '1.20')
            : PortableLocalActivityPreparation::prepareWithRetryPolicy(
                $task->id,
                'original-worker',
                7,
                1,
                'original-local-attempt',
                $descriptor,
                '1.20'
            );
        $this->assertTrue($first['prepared']);
        Carbon::setTestNow(now()->addSeconds($elapsed));
        if ($reclaim) {
            $task->forceFill([
                'lease_owner' => 'replacement-worker',
                'attempt_count' => 8,
                'lease_expires_at' => now()
                    ->addMinute(),
            ])->save();
        }
        return [$run->refresh(), $task->refresh(), $first, $descriptor];
    }
}
