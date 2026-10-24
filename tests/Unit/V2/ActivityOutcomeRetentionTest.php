<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Fixtures\V2\TestRetryWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\ActivityLeaseRenewal;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimelineEntry;
use Workflow\V2\Support\ActivityOutcomeRecorder;
use Workflow\V2\Support\ActivityTimeoutEnforcer;
use Workflow\V2\Support\WorkflowRunRetentionCleanup;
use Workflow\V2\WorkflowStub;

final class ActivityOutcomeRetentionTest extends TestCase
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

    public static function retainedOutcomes(): array
    {
        $cases = [];
        foreach ([RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled, RunStatus::Terminated] as $status) {
            foreach ([false, true] as $reclaimed) {
                foreach (['completed', 'failed'] as $outcome) {
                    $key = $status->value . '/' . (int) $reclaimed . '/' . $outcome;
                    $cases[$key . '/bridge'] = [$status, $reclaimed, $outcome, false];
                    $cases[$key . '/inline'] = [$status, $reclaimed, $outcome, true];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('retainedOutcomes')]
    public function testPruningAndRefusedOutcomeCannotDeadlockOrRestoreDetails(
        RunStatus $status,
        bool $reclaimed,
        string $outcome,
        bool $direct,
    ): void {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('This deterministic row-lock interleaving requires PostgreSQL or MySQL.');
        }
        $deadline = hrtime(true) + 30_000_000_000;
        [, $claim, $run] = $this->claimedOriginal();
        $this->travel(301)
            ->seconds();
        if ($reclaimed) {
            self::assertTrue(ActivityTimeoutEnforcer::enforceLeaseExpiry($claim['task_id'])['enforced']);
        }
        $run->forceFill([
            'status' => $status,
            'closed_at' => now(),
        ])->save();
        $reports = $this->interleavePruning($run, $claim, $outcome, $deadline, $direct);

        self::assertNotSame($reports[0]['pid'], $reports[1]['pid']);
        self::assertSame('pruned', $reports[0]['reason']);
        self::assertFalse($reports[1]['recorded']);
        self::assertContains($reports[1]['reason'], [
            'stale_attempt', 'attempt_not_found', 'task_not_found', 'activity_execution_missing',
        ]);
        self::assertSame($status, $run->fresh()->status);
        self::assertNotNull($run->fresh()->details_pruned_at);
        self::assertSame(0, $run->historyEvents()->count());
        foreach ([
            ActivityAttempt::class,
            ActivityExecution::class,
            WorkflowTask::class,
            WorkflowTimelineEntry::class,
        ] as $model) {
            self::assertSame(0, $model::query()->where('workflow_run_id', $run->id)->count());
        }
        self::assertNull(WorkflowRunSummary::query()->find($run->id));
    }

    public function testRefusedExpiredOutcomeDoesNotPreparePayloadOrWriteHistory(): void
    {
        [$bridge, $claim, $run] = $this->claimedOriginal();
        $this->travel(301)
            ->seconds();
        $before = $run->historyEvents()
            ->get()
            ->map->getAttributes()
            ->all();
        $reply = $bridge->complete($claim['activity_attempt_id'], new class() {
            public function __serialize(): array
            {
                throw new \RuntimeException('An expired result must not be serialized.');
            }
        });

        self::assertFalse($reply['recorded']);
        self::assertSame('lease_expired', $reply['reason']);
        self::assertSame($before, $run->historyEvents()->get()->map->getAttributes()->all());
        self::assertSame(
            ActivityAttemptStatus::Running,
            ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->status
        );
    }

    public static function outcomeEntryPoints(): array
    {
        return [
            'service bridge' => [false],
            'inline callback' => [true],
        ];
    }

    #[DataProvider('outcomeEntryPoints')]
    public function testACommittedRenewalKeepsAuthorityAfterAnOlderRead(bool $direct): void
    {
        [$bridge, $claim] = $this->claimedOriginal();
        self::assertInstanceOf(ActivityLeaseRenewal::class, $bridge);
        $this->travel(295)
            ->seconds();
        $armed = true;
        $observedExpiry = null;
        ActivityAttempt::retrieved(function (ActivityAttempt $attempt) use (
            &$armed,
            &$observedExpiry,
            $bridge,
            $claim,
        ): void {
            if (! $armed || $attempt->id !== $claim['activity_attempt_id']) {
                return;
            }
            $armed = false;
            $observedExpiry = $attempt->lease_expires_at;
            self::assertTrue(
                $bridge->renewLease($claim['activity_attempt_id'], $claim['lease_owner'])['lease_renewed']
            );
            $this->travel(10)
                ->seconds();
        });

        $reply = $direct
            ? ActivityOutcomeRecorder::record(
                $claim['task_id'],
                $claim['activity_attempt_id'],
                $claim['attempt_number'],
                'accepted',
                null,
                2,
                5
            )
            : $bridge->complete($claim['activity_attempt_id'], 'accepted');

        self::assertFalse($armed);
        self::assertNotNull($observedExpiry);
        self::assertTrue(now()->gte($observedExpiry));
        self::assertTrue($reply['recorded']);
        self::assertSame(
            ActivityAttemptStatus::Completed,
            ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->status
        );
    }

    public function testSqliteRetentionOwnsTheWriterBeforeReadingItsRunSnapshot(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('Writer admission requires two connections to the same SQLite file.');
        }
        [, , $run] = $this->claimedOriginal();
        $run->forceFill([
            'status' => RunStatus::Completed,
            'closed_at' => now(),
        ])->save();
        $checked = false;
        $writerHeld = false;
        $database = $connection->getDatabaseName();
        $connection->listen(static function ($event) use (&$checked, &$writerHeld, $database): void {
            if ($checked || ! str_starts_with(strtolower(ltrim($event->sql)), 'select')
                || ! str_contains($event->sql, 'workflow_runs')) {
                return;
            }
            $checked = true;
            $other = new PDO('sqlite:' . $database, options: [
                PDO::ATTR_TIMEOUT => 0,
            ]);
            $other->exec('PRAGMA busy_timeout=0');
            try {
                $other->exec('BEGIN IMMEDIATE');
                $other->exec('ROLLBACK');
            } catch (PDOException $error) {
                self::assertContains(((int) ($error->errorInfo[1] ?? 0)) & 255, [5, 6]);
                $writerHeld = true;
            }
        });

        WorkflowRunRetentionCleanup::pruneRun($run);

        self::assertTrue($checked);
        self::assertTrue($writerHeld, 'The retention snapshot must already own SQLite writer admission.');
        self::assertNotNull($run->fresh()->details_pruned_at);
        self::assertSame(0, $run->historyEvents()->count());
    }

    public function testSqliteRefusalMetadataKeepsTheOriginalPublicationBudget(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() === ':memory:') {
            $this->markTestSkipped('Publication contention requires two connections to one SQLite file.');
        }
        [, $claim, $run] = $this->claimedOriginal();
        $pdo = $connection->getPdo();
        $previousTimeout = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
        $previousJournal = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
        self::assertContains(strtolower($previousJournal), ['delete', 'wal', 'truncate', 'persist', 'memory', 'off']);
        $pdo->exec('PRAGMA journal_mode=DELETE');
        $pdo->exec('PRAGMA busy_timeout=3000');
        $other = new PDO('sqlite:' . $connection->getDatabaseName());
        $other->exec('BEGIN EXCLUSIVE');
        $started = hrtime(true);
        try {
            try {
                ActivityOutcomeRecorder::record(
                    $claim['task_id'],
                    $claim['activity_attempt_id'],
                    $claim['attempt_number'],
                    'must not be published',
                    null,
                    2,
                    5,
                    admissionDeadline: $started + 250_000_000,
                );
                self::fail('The held writer cannot admit this outcome.');
            } catch (QueryException $error) {
                self::assertInstanceOf(PDOException::class, $error->getPrevious());
                self::assertContains(((int) ($error->getPrevious()->errorInfo[1] ?? 0)) & 255, [5, 6]);
            }
            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        } finally {
            $other->exec('ROLLBACK');
            $pdo->exec('PRAGMA busy_timeout=' . $previousTimeout);
            $pdo->exec('PRAGMA journal_mode=' . $previousJournal);
        }

        self::assertLessThan(1.0, $elapsed, 'A metadata read must not consume the unrelated three-second SQLite timeout.');
        self::assertSame(RunStatus::Waiting, $run->fresh()->status);
        self::assertSame(ActivityAttemptStatus::Running, ActivityAttempt::query()->findOrFail($claim['activity_attempt_id'])->status);
    }

    private function claimedOriginal(): array
    {
        $workflow = WorkflowStub::make(TestRetryWorkflow::class, 'outcome-retention');
        $workflow->start('Ada');
        $task = WorkflowTask::query()->where('task_type', TaskType::Workflow)->where(
            'status',
            TaskStatus::Ready
        )->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        $bridge = $this->app->make(ActivityTaskBridge::class);
        $task = $bridge->poll(null, null, 1)[0];
        $claim = $bridge->claimStatus($task['task_id'], 'original-worker');
        self::assertTrue($claim['claimed']);

        return [$bridge, $claim, WorkflowRun::query()->findOrFail($workflow->runId())];
    }

    private function interleavePruning(
        WorkflowRun $run,
        array $claim,
        string $outcome,
        int $deadline,
        bool $direct
    ): array {
        $directory = sys_get_temp_dir() . '/workflow-outcome-retention-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach (['pruned', $outcome] as $index => $operation) {
                $path = $directory . '/input-' . $index . '.json';
                file_put_contents($path, json_encode([
                    'database' => DB::connection($run->getConnectionName())->getConfig(),
                    'run_id' => $run->id,
                    'attempt_id' => $claim['activity_attempt_id'],
                    'task_id' => $claim['task_id'],
                    'attempt_number' => $claim['attempt_number'],
                    'direct' => $direct,
                    'outcome' => $operation,
                    'observed_at' => now()
                        ->toISOString(),
                    'deadline' => $deadline,
                    'ready' => $directory . '/ready-' . $index,
                    'continue' => $directory . '/continue',
                    'report' => $directory . '/report-' . $index . '.json',
                ], JSON_THROW_ON_ERROR));
                $worker = new Process([
                    PHP_BINARY,
                    dirname(__DIR__, 3) . '/vendor/bin/phpunit',
                    dirname(__DIR__, 2) . '/Support/ActivityOutcomeRetentionProcessTest.php',
                    '--do-not-cache-result',
                ], dirname(__DIR__, 3), [
                    'DWF_RETENTION_PROCESS_INPUT' => $path,
                    'APP_SERVICES_CACHE' => $directory . '/services-' . $index . '.php',
                    'APP_PACKAGES_CACHE' => $directory . '/packages-' . $index . '.php',
                    'XDEBUG_MODE' => 'off',
                ], timeout: 30);
                $workers[] = $worker;
                $worker->start();
                $this->awaitFile($directory . '/ready-' . $index, $workers, $deadline);
            }
            file_put_contents($directory . '/continue', 'continue');
            while ($workers[0]->isRunning() || $workers[1]->isRunning()) {
                if (hrtime(true) >= $deadline) {
                    self::fail('The original concurrent retention budget expired.');
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

            return [
                json_decode(file_get_contents($directory . '/report-0.json'), true, flags: JSON_THROW_ON_ERROR),
                json_decode(file_get_contents($directory . '/report-1.json'), true, flags: JSON_THROW_ON_ERROR),
            ];
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

    private function awaitFile(string $path, array $workers, int $deadline): void
    {
        while (! is_file($path)) {
            foreach ($workers as $worker) {
                if (! $worker->isRunning()) {
                    self::fail(substr($worker->getErrorOutput() . $worker->getOutput(), -8_192));
                }
            }
            if (hrtime(true) >= $deadline) {
                self::fail('The original retention interleaving budget expired.');
            }
            usleep(1_000);
        }
    }
}
