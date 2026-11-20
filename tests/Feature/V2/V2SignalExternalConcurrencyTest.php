<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\Fixtures\V2\TestSignalExternalPingWorkflow;
use Tests\Fixtures\V2\TestSignalExternalSlowCloseWorkflow;
use Tests\Fixtures\V2\TestSignalExternalTargetWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub;

/**
 * Durable signal-external under concurrency (.github#223 review): the sender
 * commits its request without touching the target, delivery runs in its own
 * transactions, and a transient failure or a crash is retried, never fatal.
 * Concurrent cases need separate database sessions, so they run on MySQL and
 * PostgreSQL in forked processes.
 */
final class V2SignalExternalConcurrencyTest extends TestCase
{
    private string $flag;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->flag = sys_get_temp_dir() . '/signal-external-flag-' . getmypid();
        @unlink($this->flag);
    }

    protected function tearDown(): void
    {
        @unlink($this->flag);
        parent::tearDown();
    }

    public function testMutualSignallingDeliversBothWaysWithoutFailingEitherRun(): void
    {
        $this->requiresConcurrentDatabase();

        for ($round = 0; $round < 3; $round++) {
            $a = 'sx-mutual-a-' . $round;
            $b = 'sx-mutual-b-' . $round;
            touch($this->flag);
            WorkflowStub::make(TestSignalExternalPingWorkflow::class, $a)->start($b, 'ping', $this->flag, 400, true);
            WorkflowStub::make(TestSignalExternalPingWorkflow::class, $b)->start($a, 'ping', $this->flag, 400, true);
            $taskA = $this->readyTask($a);
            $taskB = $this->readyTask($b);
            $at = microtime(true) + 0.5;
            $child = $this->fork(fn (): array => $this->runTaskAt($taskB, $at), "mutual-{$round}");
            $parent = $this->runTaskAt($taskA, $at);
            $childResult = $this->join($child);
            @unlink($this->flag);

            $this->assertTrue($parent['ok'], json_encode($parent));
            $this->assertTrue($childResult['ok'] ?? false, json_encode($childResult));
            $this->recover([$a, $b]);

            foreach ([[$a, $b], [$b, $a]] as [$self, $peer]) {
                $this->assertCompletedWithoutFailure($self);
                $output = WorkflowStub::load($self)->output();
                $this->assertSame('delivered', $output['sent']['outcome'], $self);
                $this->assertSame($peer, $output['got'], $self);
                $this->assertSingleRequestAndOutcome($self, 'ExternalSignalDelivered');
            }
        }
    }

    public function testLockWaitTimeoutOnTheTargetIsRetriedNotFatal(): void
    {
        $this->requiresConcurrentDatabase();
        $driver = DB::connection()->getDriverName();

        WorkflowStub::make(TestSignalExternalTargetWorkflow::class, 'sx-lock-b')->start();
        $this->drain();
        WorkflowStub::make(TestSignalExternalPingWorkflow::class, 'sx-lock-a')->start(
            'sx-lock-b',
            'approve',
            '',
            0,
            false
        );
        $taskA = $this->readyTask('sx-lock-a');
        $targetRun = WorkflowStub::load('sx-lock-b')->runId();

        $pdo = $this->rawPdo();
        $pdo->beginTransaction();
        $table = (new WorkflowRun())->getTable();
        $pdo->query("SELECT id FROM {$table} WHERE id = " . $pdo->quote($targetRun) . ' FOR UPDATE')->fetchAll();
        DB::statement($driver === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");

        try {
            $result = $this->runTaskAt($taskA, microtime(true));
        } finally {
            $pdo->rollBack();
            DB::statement($driver === 'mysql' ? 'SET SESSION innodb_lock_wait_timeout = 50' : 'SET lock_timeout = 0');
        }

        // The sender's task committed its request; delivery is still pending.
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(['ExternalSignalRequested'], $this->externalEvents('sx-lock-a'));
        $this->assertFalse(
            WorkflowRun::query()->findOrFail(WorkflowStub::load('sx-lock-a')->runId())->status->isTerminal()
        );
        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $targetRun)->count());

        $this->recover(['sx-lock-a', 'sx-lock-b']);

        $this->assertCompletedWithoutFailure('sx-lock-a');
        $this->assertSame('delivered', WorkflowStub::load('sx-lock-a')->output()['sent']['outcome']);
        $this->assertSingleRequestAndOutcome('sx-lock-a', 'ExternalSignalDelivered');
        $this->assertSame('sx-lock-a', WorkflowStub::load('sx-lock-b')->output()['approved']);
        $this->assertSame(1, WorkflowSignal::query()->where('workflow_run_id', $targetRun)->count());
    }

    public function testTargetCreatedWhileTheSenderRunsIsFound(): void
    {
        $this->requiresConcurrentDatabase();

        touch($this->flag);
        WorkflowStub::make(TestSignalExternalPingWorkflow::class, 'sx-late-a')->start(
            'sx-late-b',
            'approve',
            $this->flag,
            1500,
            false
        );
        $taskA = $this->readyTask('sx-late-a');
        $at = microtime(true) + 0.3;
        $child = $this->fork(static function () use ($at): array {
            time_sleep_until($at + 0.5);
            WorkflowStub::make(TestSignalExternalTargetWorkflow::class, 'sx-late-b')->start();

            return [
                'ok' => true,
            ];
        }, 'late');
        $result = $this->runTaskAt($taskA, $at);
        $this->join($child);
        @unlink($this->flag);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->recover(['sx-late-a', 'sx-late-b']);

        $this->assertCompletedWithoutFailure('sx-late-a');
        $this->assertSame('delivered', WorkflowStub::load('sx-late-a')->output()['sent']['outcome']);
        $this->assertSame('sx-late-a', WorkflowStub::load('sx-late-b')->output()['approved']);
    }

    public function testTargetTaskFinishingDuringTheSendStillGetsItsSignal(): void
    {
        $this->requiresConcurrentDatabase();

        WorkflowStub::make(TestSignalExternalTargetWorkflow::class, 'sx-stale-b')->start();
        $taskB = $this->readyTask('sx-stale-b');
        touch($this->flag);
        WorkflowStub::make(TestSignalExternalPingWorkflow::class, 'sx-stale-a')->start(
            'sx-stale-b',
            'approve',
            $this->flag,
            1500,
            false
        );
        $taskA = $this->readyTask('sx-stale-a');
        $at = microtime(true) + 0.3;
        $child = $this->fork(function () use ($at, $taskB): array {
            time_sleep_until($at + 0.5);

            return $this->runTaskAt($taskB, microtime(true));
        }, 'stale');
        $result = $this->runTaskAt($taskA, $at);
        $childResult = $this->join($child);
        @unlink($this->flag);

        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertTrue($childResult['ok'] ?? false, json_encode($childResult));
        $this->drain();

        $this->assertCompletedWithoutFailure('sx-stale-a');
        $this->assertSame('delivered', WorkflowStub::load('sx-stale-a')->output()['sent']['outcome']);
        $this->assertCompletedWithoutFailure('sx-stale-b');
        $this->assertSame('sx-stale-a', WorkflowStub::load('sx-stale-b')->output()['approved']);
    }

    public function testSignalWhileTheTargetClosesOrContinuesAsNew(): void
    {
        $this->requiresConcurrentDatabase();

        foreach (['complete', 'continue'] as $mode) {
            $a = 'sx-close-a-' . $mode;
            $b = 'sx-close-b-' . $mode;
            touch($this->flag);
            WorkflowStub::make(TestSignalExternalSlowCloseWorkflow::class, $b)->start($this->flag, 1200, $mode);
            $taskB = $this->readyTask($b);
            WorkflowStub::make(TestSignalExternalPingWorkflow::class, $a)->start($b, 'ping', '', 0, false);
            $taskA = $this->readyTask($a);
            $at = microtime(true) + 0.3;
            $child = $this->fork(fn (): array => $this->runTaskAt($taskB, $at), "close-{$mode}");
            $result = $this->runTaskAt($taskA, $at + 0.4);
            $childResult = $this->join($child);
            @unlink($this->flag);

            $this->assertTrue($result['ok'], json_encode($result));
            // MySQL can choose the target's own closing task as a deadlock
            // victim against the signal's admission, as it can for any signal
            // racing a continue-as-new; that task is retried below. The
            // sender is never the casualty.
            $this->assertTrue(
                ($childResult['ok'] ?? false) || str_contains((string) ($childResult['error'] ?? ''), 'Deadlock'),
                json_encode($childResult),
            );
            $this->recover([$a, $b]);

            $this->assertCompletedWithoutFailure($a);
            $sent = WorkflowStub::load($a)->output()['sent'];

            if ($mode === 'continue') {
                // Instance-targeted: the signal reaches the continued run.
                $this->assertSame('delivered', $sent['outcome']);
                $this->assertSame([
                    'generation' => 1,
                    'got' => $a,
                ], WorkflowStub::load($b)->output());
                $this->assertSingleRequestAndOutcome($a, 'ExternalSignalDelivered');
            } else {
                $this->assertContains($sent['outcome'], ['target_closed', 'delivered']);
                if ($sent['outcome'] === 'target_closed') {
                    $this->assertSame('run_not_active', $sent['reason']);
                }
                $this->assertCount(2, $this->externalEvents($a));
            }
        }
    }

    public function testCrashBetweenTheSendersCommitAndDeliveryIsRecovered(): void
    {
        $this->requiresConcurrentDatabase();

        WorkflowStub::make(TestSignalExternalTargetWorkflow::class, 'sx-crash-b')->start();
        $this->drain();
        WorkflowStub::make(TestSignalExternalPingWorkflow::class, 'sx-crash-a')->start(
            'sx-crash-b',
            'approve',
            '',
            0,
            false
        );
        $taskA = $this->readyTask('sx-crash-a');
        $targetRun = WorkflowStub::load('sx-crash-b')->runId();

        // The worker process dies right after the sender's task commits,
        // before its post-commit delivery runs.
        $child = $this->fork(function () use ($taskA): array {
            Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
                if (str_contains($query->sql, 'insert')
                    && in_array(HistoryEventType::ExternalSignalRequested->value, $query->bindings, true)) {
                    DB::afterCommit(static fn () => posix_kill(posix_getpid(), SIGKILL));
                }
            });

            return $this->runTaskAt($taskA, microtime(true));
        }, 'crash');
        $this->assertSame([
            'missing_child_result' => true,
        ], $this->join($child));

        $this->assertSame(['ExternalSignalRequested'], $this->externalEvents('sx-crash-a'));
        $this->assertSame(0, WorkflowSignal::query()->where('workflow_run_id', $targetRun)->count());

        $this->recover(['sx-crash-a', 'sx-crash-b']);

        $this->assertCompletedWithoutFailure('sx-crash-a');
        $this->assertSame('delivered', WorkflowStub::load('sx-crash-a')->output()['sent']['outcome']);
        $this->assertSingleRequestAndOutcome('sx-crash-a', 'ExternalSignalDelivered');
        $this->assertSame(1, WorkflowSignal::query()->where('workflow_run_id', $targetRun)->count());
        $this->assertSame('sx-crash-a', WorkflowStub::load('sx-crash-b')->output()['approved']);
    }

    private function requiresConcurrentDatabase(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concurrent signal-external cases need MySQL or PostgreSQL and pcntl.');
        }
    }

    private function assertCompletedWithoutFailure(string $instanceId): void
    {
        $run = WorkflowRun::query()->findOrFail(WorkflowStub::load($instanceId)->runId());
        $this->assertSame(RunStatus::Completed, $run->status, $instanceId);
        $this->assertSame(0, WorkflowFailure::query()->where('workflow_run_id', $run->id)->count(), $instanceId);
    }

    private function assertSingleRequestAndOutcome(string $instanceId, string $outcomeEvent): void
    {
        $this->assertSame(['ExternalSignalRequested', $outcomeEvent], $this->externalEvents($instanceId));
    }

    /**
     * @return list<string>
     */
    private function externalEvents(string $instanceId): array
    {
        $runIds = WorkflowRun::query()->where('workflow_instance_id', $instanceId)->pluck('id')->all();

        return WorkflowHistoryEvent::query()
            ->whereIn('workflow_run_id', $runIds)
            ->whereIn('event_type', [
                HistoryEventType::ExternalSignalRequested->value,
                HistoryEventType::ExternalSignalDelivered->value,
                HistoryEventType::ExternalSignalFailed->value,
            ])
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
            ->all();
    }

    private function rawPdo(): PDO
    {
        $config = DB::connection()->getConfig();
        $dsn = DB::connection()->getDriverName() === 'mysql'
            ? "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}"
            : "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function readyTask(string $instanceId): string
    {
        $runId = WorkflowStub::load($instanceId)->runId();

        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('status', TaskStatus::Ready->value)
            ->where('task_type', TaskType::Workflow->value)
            ->sole()
            ->id;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function runTaskAt(string $taskId, float $at): array
    {
        if ($at > microtime(true)) {
            time_sleep_until($at);
        }

        try {
            $this->app->call([new RunWorkflowTask($taskId), 'handle']);

            return [
                'ok' => true,
            ];
        } catch (\Throwable $throwable) {
            return [
                'ok' => false,
                'error' => $throwable::class . ': ' . substr($throwable->getMessage(), 0, 300),
            ];
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function fork(callable $operation, string $tag): array
    {
        $file = sys_get_temp_dir() . "/signal-external-child-{$tag}-" . getmypid() . '.json';
        @unlink($file);
        DB::disconnect();
        $pid = pcntl_fork();

        if ($pid === 0) {
            DB::reconnect();
            try {
                $result = $operation();
            } catch (\Throwable $throwable) {
                $result = [
                    'ok' => false,
                    'error' => $throwable::class . ': ' . $throwable->getMessage(),
                ];
            }
            file_put_contents($file, json_encode($result));
            DB::disconnect();
            posix_kill(posix_getpid(), SIGKILL);
        }

        DB::reconnect();

        return [$pid, $file];
    }

    private function join(array $child): mixed
    {
        pcntl_waitpid($child[0], $status);
        $result = is_file($child[1])
            ? json_decode((string) file_get_contents($child[1]), true)
            : [
                'missing_child_result' => true,
            ];
        @unlink($child[1]);

        return $result;
    }

    /**
     * Run due tasks and watchdog passes, moving time past every delayed
     * delivery retry.
     *
     * @param list<string> $instances
     */
    private function recover(array $instances): void
    {
        for ($pass = 0; $pass < 6; $pass++) {
            $this->drain();
            $this->travel(2)
                ->minutes();
            $runIds = WorkflowRun::query()->whereIn('workflow_instance_id', $instances)->pluck('id')->all();
            TaskWatchdog::runPass(respectThrottle: false, runIds: $runIds);
        }

        $this->drain();
    }

    private function drain(): void
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            /** @var WorkflowTask|null $task */
            // Due tasks only: a delayed delivery retry waits for its time.
            $task = WorkflowTask::query()
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->get()
                ->first(static fn (WorkflowTask $ready): bool => $ready->available_at === null
                    || ! $ready->available_at->isFuture());

            if ($task === null) {
                return;
            }

            $job = match ($task->task_type) {
                TaskType::Workflow => new RunWorkflowTask($task->id),
                TaskType::Activity => new RunActivityTask($task->id),
                TaskType::Timer => new RunTimerTask($task->id),
            };

            $this->app->call([$job, 'handle']);
        }

        $this->fail('Timed out draining ready workflow tasks.');
    }
}
