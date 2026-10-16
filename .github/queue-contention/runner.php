<?php

declare(strict_types=1);

require getenv('DW_QUALIFICATION_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Monolog\Formatter\JsonFormatter;
use Orchestra\Testbench\Foundation\Application;
use Symfony\Component\Process\Process;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

final class QueueConsumerFailure extends RuntimeException
{
}

final class QueueFanOutActivity extends Workflow\V2\Activity
{
    public int $tries = 1;

    public function handle(int $member): array
    {
        Illuminate\Support\Facades\Log::info('large callback', [
            'member' => $member,
            'actor_pid' => getmypid(),
        ]);
        return [
            'member' => $member,
            'value' => [9007199254740993, null, '雪'],
        ];
    }
}

final class QueueFanOutWorkflow extends Workflow\V2\Workflow
{
    public function handle(): array
    {
        $members = [];
        foreach (range(0, 63) as $member) {
            $members[] = static fn (): mixed => self::activity(QueueFanOutActivity::class, $member);
        }
        return self::all($members);
    }
}

function launch(string $directory, string $mode, ?float $deadline = null): Process
{
    $workerSeconds = getenv('DW_PROBE_DRAIN_SECONDS') === '80' ? 80 : 45;
    $timeout = 20;
    if ($mode === 'drain') {
        $timeout = $workerSeconds === 80 ? 85 : 60;
    }
    if ($deadline !== null) {
        $timeout = max(0.1, min($timeout, $deadline - microtime(true)));
    }
    return new Process([PHP_BINARY, __FILE__, $directory, $mode], timeout: $timeout);
}

function readProcess(Process $process): array
{
    if (! $process->isStarted()) {
        $process->start();
    }
    $process->wait();
    if (! $process->isSuccessful() || $process->getErrorOutput() !== '') {
        throw new QueueConsumerFailure('Baseline role failed: ' . substr($process->getErrorOutput(), -2048));
    }
    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function transportTaskId(array $payload): ?string
{
    $job = unserialize($payload['data']['command'], [
        'allowed_classes' => [
            Workflow\V2\Jobs\RunWorkflowTask::class, Workflow\V2\Jobs\RunActivityTask::class,
            Workflow\V2\Jobs\RunTimerTask::class,
        ],
    ]);
    return $job->taskId ?? null;
}

if (($argv[1] ?? '') === 'cohort') {
    $directory = sys_get_temp_dir() . '/dw-published-claim-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $workers = [];
    try {
        $admission = readProcess(launch($directory, 'admit'));
        $deadline = microtime(true) + 90;
        $initial = readProcess(launch($directory, 'once'));
        if ($initial['activities'] !== [
            'pending' => 64,
        ] || $initial['ready_jobs'] !== 64) {
            throw new QueueConsumerFailure('The baseline did not create all 64 real pending activities.');
        }
        for ($index = 0; $index < 4; ++$index) {
            $workers[] = $worker = launch($directory, 'drain', $deadline);
            $worker->start();
        }
        $roles = array_map(readProcess(...), $workers);
        $final = readProcess(launch($directory, 'inspect', $deadline));
        $final['roles'] = array_column($roles, 'pid');
        $final['original_timing_preserved'] = $admission['timing'] === $final['timing'];
        $final['original_prefix_preserved'] = $initial['history_hashes']
            === array_slice($final['history_hashes'], 0, count($initial['history_hashes']));
        $final['observer_within_original_90s'] = microtime(true) < $deadline;
        $final['output_exact'] = $final['output'] === array_map(static fn (int $member): array => [
            'member' => $member,
            'value' => [9007199254740993, null, '雪'],
        ], range(0, 63));
        $final['business_invariants_pass'] = $final['status'] === 'completed' && $final['output_exact']
            && $final['original_timing_preserved'] && $final['original_prefix_preserved']
            && $final['observer_within_original_90s'] && $final['callbacks'] === 64
            && count($final['callback_members']) === 64 && $final['attempts'] === 64
            && $final['attempt_numbers'] === [1] && ($final['history_counts']['WorkflowCompleted'] ?? 0) === 1;
        $final['clean_acceptance_pass'] = $final['business_invariants_pass'] && $final['failed_jobs'] === 0
            && $final['repairs'] === 0 && $final['jobs'] === 0;
        unset($final['history_hashes'], $final['output']);
        echo json_encode($final, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
        $exitCode = $final['clean_acceptance_pass'] ? 0 : 1;
    } finally {
        foreach ($workers as $worker) {
            $worker->stop(1, 9);
        }
        (new Filesystem())->deleteDirectory($directory);
    }
    exit($exitCode);
}

final class PublishedClaimApplication extends Application
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'baseline',
            'database.migrations' => 'migrations',
            'database.connections.baseline' => [
                'driver' => 'sqlite',
                'database' => $app->basePath('database.sqlite'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'workflows.storage.connection' => 'baseline',
            'workflows.v1.enabled' => false,
            'workflows.v2.compatibility.namespace' => '0',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.v2.matching_role.queue_wake_enabled' => getenv('DW_PROBE_QUEUE_WAKE') === 'true',
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids',
                'database' => 'baseline',
                'table' => 'failed_jobs',
            ],
            'cache.default' => 'array',
            'cache.stores.array' => [
                'driver' => 'array',
                'serialize' => false,
            ],
            'logging.default' => 'single',
            'logging.channels.single' => [
                'driver' => 'single',
                'path' => $app->basePath('activity.log'),
                'formatter' => JsonFormatter::class,
            ],
        ]);
    }
}

$directory = $argv[1];
$mode = $argv[2];
foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views', 'config'] as $path) {
    if (! is_dir($directory . '/' . $path)) {
        mkdir($directory . '/' . $path, 0700, true);
    }
}
if (! is_file($directory . '/database.sqlite')) {
    touch($directory . '/database.sqlite');
}
$app = PublishedClaimApplication::create(basePath: $directory, options: [
    'extra' => [
        'providers' => [WorkflowServiceProvider::class],
        'dont-discover' => ['*'],
    ],
]);
$diagnostics = (object) [
    'rollback' => null,
    'errors' => 0,
    'repairs' => 0,
];
$deliveryErrorPath = $directory . '/delivery-errors.jsonl';
$repairObservationPath = $directory . '/repair-observations.jsonl';
if (getenv('DW_PROBE_REPAIR_OBSERVATIONS') === 'true') {
    WorkflowTask::updating(static function (WorkflowTask $task) use ($diagnostics, $repairObservationPath): void {
        if (! $task->isDirty('repair_count') || ++$diagnostics->repairs > 8) {
            return;
        }
        $reference = $task->queue_delivery_reference;
        $row = is_array($reference) && isset($reference['job_id'])
            ? DB::table('jobs')->useWritePdo()->where('id', $reference['job_id'])
                ->first(['id', 'reserved_at', 'attempts', 'available_at'])
            : null;
        file_put_contents($repairObservationPath, json_encode([
            'stage' => 'before_update',
            'pid' => getmypid(),
            'task_id' => $task->id,
            'status' => $task->status->value,
            'repair_before' => $task->getOriginal('repair_count'),
            'repair_after' => $task->repair_count,
            'native_row' => $row,
            'observed_unix' => time(),
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    });
}
$app['events']->listen(Illuminate\Queue\Events\JobProcessing::class, static function () use ($diagnostics): void {
    $diagnostics->rollback = null;
});
$app['events']->listen(Illuminate\Database\Events\TransactionRolledBack::class, static function ($event) use (
    $diagnostics
): void {
    $pdo = $event->connection->getRawPdo();
    $diagnostics->rollback = [
        'level' => $event->connection->transactionLevel(),
        'pdo_in_transaction' => $pdo instanceof PDO ? $pdo->inTransaction() : null,
    ];
});
$app['events']->listen(Illuminate\Queue\Events\JobExceptionOccurred::class, static function ($event) use (
    $deliveryErrorPath,
    $diagnostics
): void {
    if (++$diagnostics->errors > 8) {
        return;
    }
    $frames = array_filter(explode("\n", (string) $event->exception), static fn (string $line): bool =>
        str_contains($line, '/src/V2/') || str_contains($line, '/src/Queue/'));
    file_put_contents($deliveryErrorPath, json_encode([
        'pid' => getmypid(),
        'queue_job' => $event->job->getJobId(),
        'task_id' => transportTaskId($event->job->payload()),
        'summary' => substr($event->exception->getMessage(), 0, 384),
        'frames' => array_slice(array_values($frames), 0, 8),
        'last_rollback' => $diagnostics->rollback,
    ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
});
if ($mode === 'admit') {
    if (Artisan::call('migrate', [
        '--force' => true,
    ]) !== 0) {
        throw new QueueConsumerFailure(Artisan::output());
    }
    DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL,
        payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER,
        available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
    DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
        connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL,
        failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $stub = WorkflowStub::make(QueueFanOutWorkflow::class, 'published-claim', '0');
    $stub->start(new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100));
    file_put_contents($directory . '/run-id', $stub->runId());
} elseif ($mode !== 'inspect') {
    if ($mode === 'drain') {
        file_put_contents($directory . '/ready-worker-' . getmypid(), 'ready');
        $readyDeadline = hrtime(true) + 10_000_000_000;
        while (count(glob($directory . '/ready-worker-*') ?: []) < 4 && hrtime(true) < $readyDeadline) {
            usleep(10_000);
        }
        if (count(glob($directory . '/ready-worker-*') ?: []) !== 4) {
            throw new QueueConsumerFailure('Original four-worker barrier elapsed.');
        }
    }
    $options = [
        'connection' => 'database',
        '--queue' => 'default',
        '--once' => $mode === 'once',
        '--tries' => 1,
        '--timeout' => 30,
    ];
    if ($mode === 'drain') {
        $options += [
            '--max-jobs' => 128,
            '--max-time' => getenv('DW_PROBE_DRAIN_SECONDS') === '80' ? 80 : 45,
            '--stop-when-empty' => getenv('DW_PROBE_CONTINUOUS') !== 'true',
        ];
    }
    if (Artisan::call('queue:work', $options) !== 0) {
        throw new QueueConsumerFailure(Artisan::output());
    }
}
$run = WorkflowRun::query()->findOrFail(trim(file_get_contents($directory . '/run-id')));
$history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
$failures = DB::table('failed_jobs')->orderBy('id')->limit(4)->get(['exception'])->map(static function ($row): array {
    $lines = explode("\n", $row->exception);
    $frames = array_filter($lines, static fn (string $line): bool => str_contains($line, '/src/V2/'));
    return [
        'summary' => substr($lines[0], 0, 256),
        'stage_frames' => array_slice(array_values($frames), 0, 8),
    ];
})->all();
$records = is_file($directory . '/activity.log') ? array_map(
    static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
    file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
) : [];
$callbacks = array_values(
    array_filter($records, static fn (array $record): bool => $record['message'] === 'large callback')
);
$transportTasks = DB::table('jobs')->get(['payload'])->map(static function ($row): ?string {
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    return transportTaskId($payload);
})->all();
$multiplicities = array_count_values(array_filter($transportTasks, is_string(...)));
echo json_encode([
    'runtime_profile' => getenv('DW_QUALIFICATION_AUTOLOAD') ? 'explicit-autoloader' : 'locked-published-package',
    'dispatcher_sha256' => hash_file(
        'sha256',
        (new ReflectionClass(\Workflow\V2\Support\TaskDispatcher::class))->getFileName()
    ),
    'candidate_scanner_sha256' => hash_file(
        'sha256',
        (new ReflectionClass(\Workflow\V2\Support\TaskRepairCandidates::class))->getFileName()
    ),
    'database_delivery_sha256' => class_exists(\Workflow\Queue\DatabaseTaskDelivery::class) ? hash_file(
        'sha256',
        (new ReflectionClass(\Workflow\Queue\DatabaseTaskDelivery::class))->getFileName()
    ) : null,
    'pid' => getmypid(),
    'run_id' => $run->id,
    'status' => $run->status->value,
    'output' => WorkflowStub::load('published-claim', '0')->output(),
    'timing' => [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')],
    'activities' => ActivityExecution::query()->get()->groupBy('status')->map->count()->all(),
    'tasks' => WorkflowTask::query()->get()->groupBy('status')->map->count()->all(),
    'repairs' => (int) WorkflowTask::query()->sum('repair_count'),
    'repaired_tasks' => WorkflowTask::query()->where('repair_count', '>', 0)->get([
        'id', 'task_type', 'repair_count', 'attempt_count',
    ])->toArray(),
    'attempts' => ActivityAttempt::query()->count(),
    'attempt_numbers' => ActivityAttempt::query()->orderBy('attempt_number')->distinct()->pluck(
        'attempt_number'
    )->all(),
    'jobs' => DB::table('jobs')->count(),
    'ready_jobs' => DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', time())->count(),
    'reserved_jobs' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'repair_observations' => is_file($repairObservationPath)
        ? array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($repairObservationPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        )
        : [],
    'failures' => $failures,
    'callbacks' => count($callbacks),
    'callback_members' => array_count_values(
        array_map(static fn (array $record): int => $record['context']['member'], $callbacks)
    ),
    'callback_actors' => array_values(
        array_unique(array_map(static fn (array $record): int => $record['context']['actor_pid'], $callbacks))
    ),
    'delivery_exceptions' => is_file($deliveryErrorPath) ? array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($deliveryErrorPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    ) : [],
    'history_counts' => $history->groupBy('event_type')
        ->map->count()
        ->all(),
    'history_hashes' => $history->map(
        static fn ($event): string => hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR))
    )
        ->all(),
    'sqlite_version' => DB::selectOne('select sqlite_version() as version')->version,
    'busy_timeout' => DB::selectOne('PRAGMA busy_timeout')->timeout,
    'journal' => DB::selectOne('PRAGMA journal_mode')->journal_mode,
    'matching_wake' => config('workflows.v2.matching_role.queue_wake_enabled'),
    'worker_seconds' => getenv('DW_PROBE_DRAIN_SECONDS') === '80' ? 80 : 45,
    'continuous_workers' => getenv('DW_PROBE_CONTINUOUS') === 'true',
    'distinct_transport_tasks' => count($multiplicities),
    'max_transport_copies' => $multiplicities === [] ? 0 : max($multiplicities),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
