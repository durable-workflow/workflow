<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestScheduledWorkflow;
use Tests\Support\TestDatabaseServiceProvider;
use Throwable;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\ScheduleStartResult;

final class ScheduleSqliteContentionTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'dw-schedule-contention-');
        parent::setUp();
        DB::connection()->getPdo()->exec('PRAGMA journal_mode=WAL');
        DB::connection()->getPdo()->exec('PRAGMA busy_timeout=20');
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        Queue::fake();
        Carbon::setTestNow('2026-10-10T10:00:00Z');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->databasePath . $suffix)) {
                unlink($this->databasePath . $suffix);
            }
        }
    }

    public static function writerModes(): array
    {
        return [
            'due / committed WAL snapshot' => [false, 'due'],
            'due / writer still holds lock' => [true, 'due'],
            'buffer / committed WAL snapshot' => [false, 'buffer'],
            'buffer / writer still holds lock' => [true, 'buffer'],
            'backfill / committed WAL snapshot' => [false, 'backfill'],
            'backfill / writer still holds lock' => [true, 'backfill'],
        ];
    }

    #[DataProvider('writerModes')]
    public function testContentionRetriesOriginalOccurrenceOnce(bool $holdWriter, string $admission): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'retry-original-occurrence',
            spec: [
                'intervals' => [[
                    'every' => 'PT2S',
                ]],
                'timezone' => 'UTC',
            ],
            action: [
                'workflow_class' => TestScheduledWorkflow::class,
                'input' => ['contention'],
            ],
            maxRuns: 1,
        );
        $due = $schedule->next_fire_at->copy();
        Carbon::setTestNow($due->copy()->addSeconds(8));
        if ($admission === 'buffer') {
            $schedule->bufferAction($due);
            $schedule->forceFill([
                'next_fire_at' => $due->copy()
                    ->addHour(),
            ])->save();
        }
        $nextFireAt = $schedule->next_fire_at->copy();
        $bufferBefore = $schedule->buffered_actions;
        $evaluate = static fn (): array => $admission === 'backfill'
            ? ScheduleManager::backfill($schedule, $due, $due->copy()->addSecond())
            : ScheduleManager::tick();
        DB::statement('CREATE TABLE contention_marker (writes INTEGER NOT NULL)');
        DB::statement('INSERT INTO contention_marker(writes) VALUES (0)');
        $writer = new PDO('sqlite:' . $this->databasePath);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=20');
        $injected = false;
        DB::listen(static function (QueryExecuted $query) use ($writer, $holdWriter, &$injected): void {
            if ($injected || $query->connection->transactionLevel() === 0
                || ! str_starts_with(strtolower($query->sql), 'select')
                || ! str_contains($query->sql, 'workflow_schedules')) {
                return;
            }
            $injected = true;
            $writer->beginTransaction();
            $writer->exec('UPDATE contention_marker SET writes=writes+1');
            if (! $holdWriter) {
                $writer->commit();
            }
        });

        $error = null;
        $results = [];
        try {
            $results = $evaluate();
        } catch (Throwable $exception) {
            $error = $exception;
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
        }

        $this->assertTrue($injected, 'A real second SQLite connection must contend with the schedule read.');
        $this->assertNull($error, 'A locked failure recorder must not crash the whole evaluator.');
        $this->assertCount(1, $results);
        $this->assertStringContainsString('database is locked', $results[0]['error']);
        $fresh = $schedule->fresh();
        $this->assertTrue($fresh->next_fire_at->equalTo($nextFireAt));
        $this->assertSame($bufferBefore, $fresh->buffered_actions);
        $this->assertNull($fresh->last_fired_at);
        $this->assertSame(0, (int) $fresh->fires_count);
        $this->assertSame(0, (int) $fresh->failures_count);
        $this->assertSame(1, (int) $fresh->remaining_actions);
        $this->assertSame(0, WorkflowInstance::query()->count());
        $this->assertSame(['ScheduleCreated'], $fresh->historyEvents()->pluck('event_type')->map(
            static fn ($event): string => $event->value,
        )->all());

        $retry = $evaluate();
        $this->assertCount(1, $retry);
        $this->assertArrayNotHasKey('error', $retry[0], json_encode($retry[0], JSON_THROW_ON_ERROR));
        if ($admission !== 'backfill') {
            $this->assertSame($admission === 'buffer' ? 'drained' : 'triggered', $retry[0]['outcome']);
        } else {
            $this->assertNotNull($retry[0]['instance_id']);
        }
        $fresh = $schedule->fresh();
        $this->assertSame(ScheduleStatus::Deleted, $fresh->status);
        $this->assertSame(1, (int) $fresh->fires_count);
        $this->assertSame(0, (int) $fresh->remaining_actions);
        $event = WorkflowScheduleHistoryEvent::query()->where('event_type', 'ScheduleTriggered')->sole();
        $this->assertSame($due->format('Y-m-d\TH:i:s.uP'), $event->payload['occurrence_time']);
        $this->assertSame(1, WorkflowInstance::query()->count());
        $this->assertSame([], ScheduleManager::tick());
    }

    public function testPermanentConstraintFailureStillRecordsAndAdvances(): void
    {
        $schedule = ScheduleManager::createFromSpec(
            scheduleId: 'permanent-admission-failure',
            spec: [
                'intervals' => [[
                    'every' => 'PT2S',
                ]],
                'timezone' => 'UTC',
            ],
            action: [
                'workflow_class' => TestScheduledWorkflow::class,
            ],
            maxRuns: 1,
        );
        $due = $schedule->next_fire_at->copy();
        Carbon::setTestNow($due->copy()->addSeconds(8));
        DB::statement('CREATE TABLE admission_constraint (id INTEGER PRIMARY KEY)');
        DB::statement('INSERT INTO admission_constraint(id) VALUES (1)');
        $this->app->instance(ScheduleWorkflowStarter::class, new class() implements ScheduleWorkflowStarter {
            public function start(
                WorkflowSchedule $schedule,
                ?DateTimeInterface $occurrenceTime,
                string $outcome,
                ?string $effectiveOverlapPolicy = null,
            ): ScheduleStartResult {
                DB::statement('INSERT INTO admission_constraint(id) VALUES (1)');

                throw new LogicException('The duplicate primary key must be rejected.');
            }
        });

        $results = ScheduleManager::tick();
        $this->assertCount(1, $results);
        $this->assertStringContainsString('UNIQUE constraint failed', $results[0]['error']);
        $fresh = $schedule->fresh();
        $this->assertSame(1, (int) $fresh->failures_count);
        $this->assertTrue($fresh->next_fire_at->greaterThan($due));
        $this->assertNotNull($fresh->last_fired_at);
        $this->assertSame(0, (int) $fresh->fires_count);
        $this->assertSame(1, (int) $fresh->remaining_actions);
        $this->assertSame(0, WorkflowInstance::query()->count());
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'schedule_sqlite');
        $app['config']->set('database.connections.schedule_sqlite', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 20,
            'journal_mode' => 'WAL',
            'transaction_mode' => 'DEFERRED',
        ]);
        $app['config']->set('workflows.storage.connection', 'schedule_sqlite');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('cache.default', 'array');
    }
}
