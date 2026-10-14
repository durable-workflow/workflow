<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$path = '/state/claim-contention.sqlite';
if (file_exists($path)) {
    throw new RuntimeException('Use a fresh isolated state directory.');
}
touch($path);
config(['database.connections.qualified.database' => $path, 'database.default' => 'qualified',
    'workflows.storage.connection' => 'qualified']);
Illuminate\Support\Facades\DB::purge('qualified');
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$connection = Illuminate\Support\Facades\DB::connection('qualified');
$connection->statement('CREATE TABLE contention_probe (id INTEGER PRIMARY KEY)');
$writer = new PDO('sqlite:' . $path);
$writer->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$writer->exec('PRAGMA journal_mode = WAL');
$writer->exec('PRAGMA busy_timeout = 5000');
$stub = Workflow\V2\WorkflowStub::make(App\Workflows\HttpWorkflow::class, 'claim-contention', 'qualification-alpha');
$stub->start('alpha', 'fast');
$runId = $stub->runId();
$injected = false;
$connection->beforeExecuting(static function (string $sql, array $bindings, $database) use ($writer, &$injected): void {
    if ($injected || ! str_starts_with($sql, 'update "workflow_tasks"') || ! in_array('leased', $bindings, true)) {
        return;
    }
    if ($database->transactionLevel() !== 1 || ! $database->getPdo()->inTransaction()) {
        throw new RuntimeException('Expected the actual root claim transaction.');
    }
    $writer->exec('INSERT INTO contention_probe (id) VALUES (1)');
    $injected = true;
});
Illuminate\Support\Facades\Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default',
    '--once' => true, '--tries' => 1, '--timeout' => 30]);
$db = $connection->getPdo();
$tasks = $connection->table('workflow_tasks')->where('workflow_run_id', $runId)
    ->get(['id', 'status', 'attempt_count', 'lease_owner', 'lease_expires_at'])->toArray();
$failed = $connection->table('failed_jobs')->get(['exception'])->map(static fn ($job): string =>
    explode("\n", $job->exception)[0])->toArray();
echo json_encode(['injected' => $injected, 'run_id' => $runId, 'status' => $stub->refresh()->status(),
    'tasks' => $tasks, 'failed_jobs' => $failed, 'queue_jobs' => $connection->table('jobs')->count(),
    'history_events' => $connection->table('workflow_history_events')->where('workflow_run_id', $runId)->count(),
    'root_transaction_level' => $connection->transactionLevel(), 'pdo_in_transaction' => $db->inTransaction(),
], JSON_THROW_ON_ERROR), "\n";
