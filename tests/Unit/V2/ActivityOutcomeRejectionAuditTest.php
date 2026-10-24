<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestRetryWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\ActivityOutcomeRejection;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\Support\RunTimelineProjector;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;
use Workflow\V2\WorkflowStub;

final class ActivityOutcomeRejectionAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->freezeTime();
        config([
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
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
        $prefix = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $before = $this->businessState($run);
        $this->travel(2)
            ->seconds();
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
            ->get()
            ->map->getAttributes()
            ->all());
        foreach (['result', 'failure', 'message', 'stack_trace', 'details', 'task', 'command'] as $field) {
            self::assertArrayNotHasKey($field, $notice->payload);
        }

        $retained = $notice->getAttributes();
        $this->travel(3)
            ->seconds();
        $this->refusedOutcome(
            $bridge,
            $claim['activity_attempt_id'],
            $outcome === 'completed' ? 'failed' : 'completed'
        );
        self::assertCount(1, $this->notices($run));
        self::assertSame($retained, $this->notices($run)[0]->getAttributes());
        self::assertSame($before, $this->businessState($run));
    }

    public function testPrunedRunIsNotRepopulatedByARefusedOutcome(): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal();
        $run->forceFill([
            'details_pruned_at' => now(),
        ])->save();
        $before = $this->businessState($run);
        $history = $run->historyEvents()
            ->count();

        self::assertFalse($bridge->complete($claim['activity_attempt_id'], 'late')['recorded']);
        self::assertCount(0, $this->notices($run));
        self::assertSame($history, $run->historyEvents()->count());
        self::assertSame($before, $this->businessState($run));
    }

    public function testDiagnosticRequiresExactAttemptIdDespiteCaseInsensitiveStorage(): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal();
        $alias = strtolower($claim['activity_attempt_id']);
        if ($alias === $claim['activity_attempt_id']) {
            $alias = strtoupper($claim['activity_attempt_id']);
        }
        self::assertNotSame($claim['activity_attempt_id'], $alias);
        $lookup = ActivityAttempt::query()->find($alias);
        if (DB::connection()->getDriverName() === 'mysql') {
            self::assertInstanceOf(ActivityAttempt::class, $lookup);
            self::assertSame($claim['activity_attempt_id'], $lookup->id);
        }
        $before = $this->businessState($run);

        $reply = $bridge->complete($alias, 'unbound case alias');
        self::assertFalse($reply['recorded']);
        self::assertSame('attempt_not_found', $reply['reason']);
        self::assertCount(0, $this->notices($run));
        self::assertNull($this->recordDiagnostic($claim, $run, $alias));
        self::assertCount(0, $this->notices($run));
        self::assertSame($before, $this->businessState($run));

        $canonical = $this->recordDiagnostic($claim, $run, $claim['activity_attempt_id']);
        self::assertInstanceOf(WorkflowHistoryEvent::class, $canonical);
        self::assertSame($claim['activity_attempt_id'], $canonical->payload['activity_attempt_id']);
        self::assertSame($claim['lease_owner'], $canonical->payload['lease_owner']);
        self::assertCount(1, $this->notices($run));
        self::assertSame($before, $this->businessState($run));
    }

    public static function missingOriginalOwners(): array
    {
        return [
            'unknown original owner' => [null],
            'empty original owner' => [''],
        ];
    }

    #[DataProvider('missingOriginalOwners')]
    public function testDiagnosticDoesNotInventMissingOriginalOwnership(?string $owner): void
    {
        [, $claim, $run] = $this->expiredOriginal();
        ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->forceFill([
            'lease_owner' => $owner,
        ])->save();
        $before = $this->businessState($run);

        self::assertNull($this->recordDiagnostic($claim, $run, $claim['activity_attempt_id']));
        self::assertCount(0, $this->notices($run));
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
        $history = $run->historyEvents()
            ->count();

        self::assertFalse($bridge->complete($claim['activity_attempt_id'], 'duplicate')['recorded']);
        self::assertCount(0, $this->notices($run));
        self::assertSame($history, $run->historyEvents()->count());
    }

    public function testRetryingACommittedFailureCreatesNoRejectionAudit(): void
    {
        [$bridge, $claim, $run] = $this->claimedOriginal(TestGreetingWorkflow::class);
        self::assertSame(1, $claim['retry_policy']['max_attempts']);
        self::assertTrue($bridge->fail($claim['activity_attempt_id'], [
            'message' => 'accepted failure',
        ])['recorded']);
        self::assertSame(
            ActivityAttemptStatus::Failed,
            ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->status,
        );
        $before = $this->businessState($run);
        $history = $run->historyEvents()
            ->get()
            ->map->getAttributes()
            ->all();

        self::assertFalse($bridge->fail($claim['activity_attempt_id'], [
            'message' => 'duplicate failure',
        ])['recorded']);
        self::assertCount(0, $this->notices($run));
        self::assertSame($history, $run->historyEvents()->get()->map->getAttributes()->all());
        self::assertSame($before, $this->businessState($run));
    }

    public static function settledAttempts(): array
    {
        return [
            'completed attempt' => [ActivityAttemptStatus::Completed],
            'failed attempt' => [ActivityAttemptStatus::Failed],
            'cancelled attempt' => [ActivityAttemptStatus::Cancelled],
        ];
    }

    public static function cancelledRunStates(): array
    {
        return [
            'cancelled retained run' => [RunStatus::Cancelled, 'run_cancelled'],
            'terminated retained run' => [RunStatus::Terminated, 'run_terminated'],
        ];
    }

    #[DataProvider('cancelledRunStates')]
    public function testExpiredOutcomeOnClosedRunKeepsBusinessState(RunStatus $status, string $reason): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal();
        $run->forceFill([
            'status' => $status,
            'closed_at' => now(),
        ])->save();
        $before = $this->businessState($run);

        $reply = $this->refusedOutcome($bridge, $claim['activity_attempt_id'], 'completed');

        self::assertFalse($reply['recorded']);
        self::assertSame($reason, $reply['reason']);
        self::assertSame($before, $this->businessState($run));
        self::assertCount(1, $this->notices($run));
        self::assertSame($reason, $this->notices($run)[0]->payload['reason']);
    }

    #[DataProvider('settledAttempts')]
    public function testSettledAttemptsCannotBeReclassifiedToCreateADiagnostic(ActivityAttemptStatus $status): void
    {
        [, $claim, $run] = $this->expiredOriginal();
        ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->forceFill([
            'status' => $status,
        ])->save();
        $before = $this->businessState($run);
        $history = $run->historyEvents()
            ->get()
            ->map->getAttributes()
            ->all();

        self::assertNull($this->recordDiagnostic($claim, $run, $claim['activity_attempt_id']));
        self::assertCount(0, $this->notices($run));
        self::assertSame($history, $run->historyEvents()->get()->map->getAttributes()->all());
        self::assertSame($before, $this->businessState($run));
    }

    public function testActualPruningLeavesNoAttemptForALateOutcomeAudit(): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal(closed: true);
        $report = WorkflowRunRetentionCleanup::pruneRun($run);
        self::assertGreaterThan(0, $report['activity_attempts_deleted']);
        self::assertGreaterThan(0, $report['history_events_deleted']);
        $retained = $run->fresh()
            ->getAttributes();
        self::assertNotNull($retained['details_pruned_at']);

        $reply = $bridge->complete($claim['activity_attempt_id'], 'discarded after pruning');

        self::assertFalse($reply['recorded']);
        self::assertSame('attempt_not_found', $reply['reason']);
        self::assertSame($retained, $run->fresh()->getAttributes());
        self::assertCount(0, $this->notices($run));
        self::assertSame(0, $run->historyEvents()->count());
        self::assertSame(0, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
        self::assertSame(0, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());
        self::assertNull(WorkflowRunSummary::query()->find($run->id));
    }

    public function testConcurrentLateCompletionAndFailureProduceOneImmutableDiagnostic(): void
    {
        $deadline = hrtime(true) + 30_000_000_000;
        [, $claim, $run] = $this->expiredOriginal();
        $before = $this->businessState($run);
        $prefix = $run->historyEvents()
            ->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $reports = $this->concurrentOutcomes($run, $claim, ['completed', 'failed'], $deadline);
        foreach ($reports as $report) {
            self::assertFalse($report['recorded']);
            self::assertSame('stale_attempt', $report['reason']);
        }

        $notices = $this->notices($run);
        self::assertCount(1, $notices);
        self::assertSame($claim['activity_attempt_id'], $notices[0]->payload['activity_attempt_id']);
        self::assertContains($notices[0]->payload['outcome'], ['completed', 'failed']);
        $retained = $notices[0]->getAttributes();
        $this->recordDiagnostic($claim, $run, $claim['activity_attempt_id']);
        self::assertSame($retained, $this->notices($run)[0]->getAttributes());
        self::assertSame($before, $this->businessState($run));
        self::assertSame($prefix, $run->historyEvents()->orderBy('sequence')->limit(count($prefix))
            ->get()
            ->map->getAttributes()
            ->all());
        self::assertStringNotContainsString('discarded concurrent', json_encode($notices[0]->payload));
    }

    public static function pruningAttemptStates(): array
    {
        return [
            'reclaimed expired attempt' => [true],
            'lease expired before maintenance' => [false],
        ];
    }

    #[DataProvider('pruningAttemptStates')]
    public function testConcurrentPruningAndLateOutcomeNeverRestoreDetails(bool $reclaimed): void
    {
        $deadline = hrtime(true) + 30_000_000_000;
        if ($reclaimed) {
            [, $claim, $run] = $this->expiredOriginal(closed: true);
        } else {
            [, $claim, $run] = $this->claimedOriginal();
            $this->travel(301)->seconds();
            $run->forceFill(['status' => RunStatus::Completed, 'closed_at' => now()])->save();
        }
        $status = $run->fresh()
->status;
        $reports = $this->concurrentOutcomes($run, $claim, ['completed', 'pruned'], $deadline);
        self::assertFalse($reports[0]['recorded']);
        self::assertContains($reports[0]['reason'], [
            'stale_attempt', 'lease_expired', 'attempt_not_found', 'task_not_found', 'activity_execution_missing',
        ]);
        self::assertSame('pruned', $reports[1]['reason']);
        self::assertSame($status, $run->fresh()->status);
        self::assertNotNull($run->fresh()->details_pruned_at);
        self::assertSame(0, $run->historyEvents()->count());
        self::assertSame(0, ActivityAttempt::query()->where('workflow_run_id', $run->id)->count());
        self::assertSame(0, ActivityExecution::query()->where('workflow_run_id', $run->id)->count());
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $run->id)->count());
        self::assertSame(0, WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->count());
        self::assertNull(WorkflowRunSummary::query()->find($run->id));
    }

    public function testDiagnosticAppendKeepsBoundedCountersAndTimelineCurrent(): void
    {
        [$bridge, $claim, $run] = $this->expiredOriginal(closed: true);
        self::assertFalse(
            RunTimelineProjector::driftStatusForRun($run->fresh())['stale'],
            'The original timeline must already be current.'
        );
        $summary = WorkflowRunSummary::query()->findOrFail($run->id);
        $summaryBefore = $summary->getAttributes();
        $prefix = WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->orderBy('sequence')
            ->get()
            ->map->getAttributes()
            ->all();
        $this->travel(2)
            ->seconds();
        $bridge->complete($claim['activity_attempt_id'], 'late');

        $summary = $summary->fresh();
        self::assertTrue(HistoryBudget::hasCompleteSummary($run->fresh(), $summary));
        self::assertSame((int) $summaryBefore['history_event_count'] + 1, $summary->history_event_count);
        self::assertGreaterThan((int) $summaryBefore['history_size_bytes'], $summary->history_size_bytes);
        self::assertEquals($summaryBefore['updated_at'], $summary->getRawOriginal('updated_at'));
        foreach (['status', 'next_task_id', 'last_failure_id', 'open_wait_count'] as $field) {
            self::assertEquals($summaryBefore[$field] ?? null, $summary->getRawOriginal($field));
        }
        $rows = WorkflowTimelineEntry::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        self::assertCount(count($prefix) + 1, $rows);
        self::assertSame($prefix, $rows->take(count($prefix))->map->getAttributes()->all());
        self::assertSame('ActivityOutcomeRejected', $rows->last()->type);
        self::assertStringContainsString('stale_attempt', $rows->last()->summary);
        self::assertFalse(RunTimelineProjector::driftStatusForRun($run->fresh())['stale']);
    }

    public static function publicationDeadlines(): array
    {
        return [
            'lease boundary' => ['lease_expired', false],
            'activity boundary' => ['start_to_close_timeout', false],
            'heartbeat boundary' => ['heartbeat_timeout', false],
            'activity lifetime boundary' => ['schedule_to_close_timeout', false],
            'run boundary' => ['run_timeout', false],
            'execution boundary' => ['execution_timeout', false],
            'completed request converted by payload guard' => ['lease_expired', true],
        ];
    }

    #[DataProvider('publicationDeadlines')]
    public function testRefusalDuringPreparationRetainsOriginalOutcome(string $reason, bool $payloadLimit): void
    {
        [$bridge, $claim, $run] = $this->claimedOriginal();
        $task = WorkflowTask::query()->findOrFail($claim['task_id']);
        $boundary = now()
            ->addSecond();
        $activityDeadline = match ($reason) {
            'start_to_close_timeout' => 'close_deadline_at',
            'heartbeat_timeout' => 'heartbeat_deadline_at',
            'schedule_to_close_timeout' => 'schedule_to_close_deadline_at',
            default => null,
        };
        if ($reason === 'lease_expired') {
            $boundary = $task->lease_expires_at;
        } elseif ($activityDeadline !== null) {
            ActivityExecution::query()->findOrFail($claim['activity_execution_id'])->forceFill([
                $activityDeadline => $boundary,
            ])->save();
        } else {
            $run->forceFill([
                ($reason === 'execution_timeout' ? 'execution_deadline_at' : 'run_deadline_at') => $boundary,
            ])->save();
        }
        if ($payloadLimit) {
            config([
                'workflows.v2.structural_limits.payload_size_bytes' => 1,
            ]);
        }
        $this->app->instance(ExternalPayloadStoragePolicy::class, new class(
            $boundary
        ) implements ExternalPayloadStoragePolicy {
            public function __construct(
                private readonly CarbonInterface $boundary
            ) {
            }

            public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
            {
                Date::setTestNow($this->boundary);

                return null;
            }

            public function thresholdBytesFor(?string $namespace): ?int
            {
                return null;
            }
        });
        $before = $this->businessState($run);

        $reply = $bridge->complete($claim['activity_attempt_id'], 'discarded-private-result');

        self::assertFalse($reply['recorded']);
        self::assertSame($reason, $reply['reason']);
        self::assertCount(1, $this->notices($run));
        $notice = $this->notices($run)[0];
        self::assertSame('completed', $notice->payload['outcome']);
        self::assertSame($reason, $notice->payload['reason']);
        self::assertSame($claim['activity_attempt_id'], $notice->payload['activity_attempt_id']);
        self::assertStringNotContainsString('discarded-private-result', json_encode($notice->payload));
        self::assertSame($before, $this->businessState($run));
    }

    /**
     * @param array<string, mixed> $claim
     * @param list<string> $outcomes
     * @return list<array<string, mixed>>
     */
    private function concurrentOutcomes(WorkflowRun $run, array $claim, array $outcomes, int $deadline): array
    {
        $connection = DB::connection($run->getConnectionName());
        if ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('The two-process control requires a shared file or network database.');
        }
        $directory = sys_get_temp_dir() . '/workflow-rejected-outcome-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($outcomes as $index => $outcome) {
                $path = $directory . '/input-' . $index . '.json';
                file_put_contents($path, json_encode([
                    'database' => $connection->getConfig(),
                    'attempt_id' => $claim['activity_attempt_id'],
                    'run_id' => $run->id,
                    'allow_pruned' => in_array('pruned', $outcomes, true),
                    'outcome' => $outcome,
                    'observed_at' => now()
                        ->toISOString(),
                    'deadline' => $deadline,
                    'ready' => $directory . '/ready-' . $index,
                    'go' => $directory . '/go',
                    'report' => $directory . '/report-' . $index . '.json',
                ], JSON_THROW_ON_ERROR));
                $worker = new Process([
                    PHP_BINARY,
                    dirname(__DIR__, 3) . '/vendor/bin/phpunit',
                    dirname(__DIR__, 2) . '/Support/ActivityOutcomeRejectionProcessTest.php',
                    '--do-not-cache-result',
                ], dirname(__DIR__, 3), [
                    'DWF_REJECTION_PROCESS_INPUT' => $path,
                    'APP_SERVICES_CACHE' => $directory . '/services-' . $index . '.php',
                    'APP_PACKAGES_CACHE' => $directory . '/packages-' . $index . '.php',
                    'XDEBUG_MODE' => 'off',
                ], timeout: 30);
                $workers[] = $worker;
                $worker->start();
            }
            while (! is_file($directory . '/ready-0') || ! is_file($directory . '/ready-1')) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        self::fail(substr($worker->getErrorOutput() . $worker->getOutput(), -8_192));
                    }
                }
                if (hrtime(true) >= $deadline) {
                    self::fail('Both processes must share the original budget.');
                }
                usleep(1_000);
            }
            file_put_contents($directory . '/go', 'go');
            while ($workers[0]->isRunning() || $workers[1]->isRunning()) {
                if (hrtime(true) >= $deadline) {
                    self::fail('The concurrent outcomes exceeded their original budget.');
                }
                usleep(1_000);
            }
            foreach ($workers as $worker) {
                self::assertSame(
                    0,
                    $worker->getExitCode(),
                    substr($worker->getErrorOutput() . $worker->getOutput(), -8_192)
                );
            }
            $first = json_decode(file_get_contents($directory . '/report-0.json'), true, flags: JSON_THROW_ON_ERROR);
            $second = json_decode(file_get_contents($directory . '/report-1.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertNotSame($first['pid'], $second['pid']);
            return [$first, $second];
        } finally {
            foreach ($workers as $worker) {
                $worker->stop(2);
            }
            foreach (glob($directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function expiredOriginal(bool $closed = false): array
    {
        [$bridge, $claim, $run] = $this->claimedOriginal();
        $this->travel(301)
            ->seconds();
        self::assertTrue(ActivityTimeoutEnforcer::enforceLeaseExpiry($claim['task_id'])['enforced']);
        if ($closed) {
            $this->travel(10)
                ->seconds();
            $retry = $bridge->poll(null, null, 1)[0];
            $replacement = $bridge->claimStatus($retry['task_id'], 'replacement-worker');
            self::assertTrue($replacement['claimed']);
            self::assertTrue($bridge->complete($replacement['activity_attempt_id'], 'accepted')['recorded']);
            $this->runWorkflowTask();
            self::assertTrue($run->fresh()->status->isTerminal());
        }

        return [$bridge, $claim, $run];
    }

    /**
     * @param class-string<\Workflow\V2\Workflow> $workflowClass
     */
    private function claimedOriginal(string $workflowClass = TestRetryWorkflow::class): array
    {
        $workflow = WorkflowStub::make($workflowClass, 'outcome-rejection-audit');
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
            ? $bridge->complete($attemptId, new class() {
                public function __serialize(): array
                {
                    throw new RuntimeException('Rejected output must never be serialized.');
                }
            })
            : $bridge->fail($attemptId, [
                'message' => 'private failure that must not enter audit history',
            ]);
    }

    private function recordDiagnostic(array $claim, WorkflowRun $run, string $attemptId): ?WorkflowHistoryEvent
    {
        return SqliteTransaction::forTask($claim['task_id'], static function () use ($claim, $run, $attemptId) {
            $task = WorkflowTask::query()->lockForUpdate()->findOrFail($claim['task_id']);
            $execution = ActivityExecution::query()->lockForUpdate()->findOrFail($claim['activity_execution_id']);
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);

            return ActivityOutcomeRejection::record(
                $lockedRun,
                $execution,
                $task,
                $attemptId,
                $claim['attempt_number'],
                'completed',
                'stale_attempt',
            );
        });
    }

    private function notices(WorkflowRun $run): array
    {
        return $run->historyEvents()
            ->where('event_type', 'ActivityOutcomeRejected')
            ->get()
            ->all();
    }

    private function businessState(WorkflowRun $run): array
    {
        $attributes = $run->fresh()
            ->getAttributes();
        unset($attributes['last_history_sequence'], $attributes['updated_at']);

        return [
            $attributes,
            WorkflowTask::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()
                ->map->getAttributes()
                ->all(),
            ActivityExecution::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()
                ->map->getAttributes()
                ->all(),
            ActivityAttempt::query()->where('workflow_run_id', $run->id)->orderBy('id')->get()
                ->map->getAttributes()
                ->all(),
        ];
    }
}
