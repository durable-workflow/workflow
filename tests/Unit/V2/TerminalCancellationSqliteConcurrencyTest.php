<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type('terminal-close-sqlite-concurrency')]
final class TerminalCloseSqliteConcurrentWorkflow extends Workflow
{
    public function handle(): void
    {
    }
}

final class TerminalCancellationSqliteConcurrencyTest extends TestCase
{
    private string $path;

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (isset($this->path) && is_file($this->path . $suffix)) {
                unlink($this->path . $suffix);
            }
        }
    }

    public static function commands(): iterable
    {
        yield 'cancel' => ['cancel'];
        yield 'terminate' => ['terminate'];
    }

    #[DataProvider('commands')]
    public function testRootCloseOwnsTheWriterBeforeItsFirstStateRead(string $command): void
    {
        Queue::fake();
        DB::statement('CREATE TABLE terminal_close_writer_probe (id INTEGER PRIMARY KEY, value INTEGER NOT NULL)');
        DB::insert('INSERT INTO terminal_close_writer_probe VALUES (1, 0)');
        $workflow = WorkflowStub::make(TerminalCloseSqliteConcurrentWorkflow::class);
        $workflow->start();
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        $database = DB::connection();
        $writer = new PDO('sqlite:' . $this->path);
        $writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $writer->exec('PRAGMA busy_timeout=0');
        $observed = 0;
        $blocked = false;
        DB::listen(function (QueryExecuted $query) use ($database, $writer, &$observed, &$blocked): void {
            if ($observed !== 0 || $query->connection !== $database || $database->transactionLevel() === 0
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains($query->sql, 'workflow_instances')) {
                return;
            }
            ++$observed;
            try {
                $writer->exec('UPDATE terminal_close_writer_probe SET value=value+1 WHERE id=1');
            } catch (PDOException $error) {
                $this->assertSame(5, ($error->errorInfo[1] ?? 0) & 255);
                $blocked = true;
            }
        });

        $result = $command === 'cancel' ? $workflow->attemptCancel('concurrent maintenance')
            : $workflow->attemptTerminate('concurrent maintenance');

        $this->assertTrue($result->accepted());
        $this->assertSame(1, $observed);
        $this->assertTrue($blocked, 'A maintenance writer must not invalidate the close transaction snapshot.');
        $this->assertSame($command === 'cancel' ? 'cancelled' : 'terminated', $run->fresh()->status->value);
        $this->assertSame(1, $run->historyEvents()->where('event_type', $command === 'cancel'
            ? HistoryEventType::CancelRequested : HistoryEventType::TerminateRequested)->count());
        $this->assertSame(0, $database->transactionLevel());
        $writer->exec('UPDATE terminal_close_writer_probe SET value=value+1 WHERE id=1');
        $this->assertSame(1, (int) $database->table('terminal_close_writer_probe')->value('value'));
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'terminal-close-sqlite-');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'journal_mode' => 'WAL',
            'busy_timeout' => 20,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('workflows.v1.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
            '--force' => true,
        ])->assertExitCode(0);
        $this->assertSame('wal', strtolower((string) DB::selectOne('PRAGMA journal_mode')->journal_mode));
    }
}
