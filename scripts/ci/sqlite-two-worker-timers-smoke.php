<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\WorkflowStub;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
date_default_timezone_set('UTC');
config(['workflows.v1.enabled' => true, 'queue.connections.database.retry_after' => 90]);
$pdo = DB::connection()->getPdo();
$pdo->exec('PRAGMA busy_timeout = 5000');

if (($argv[1] ?? null) === 'worker') {
    exit(Artisan::call('queue:work', [
        'connection' => 'database', '--queue' => 'timer-embedded', '--sleep' => 1,
        '--tries' => 3, '--timeout' => 60, '--max-time' => 150,
    ]));
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$assert(DB::connection()->getDriverName() === 'sqlite', 'Requires isolated SQLite.');
$assert(config('queue.default') === 'database', 'Requires database queue.');
$assert(in_array(strtoupper((string) config('database.connections.sqlite.transaction_mode', 'DEFERRED')), ['', 'DEFERRED'], true), 'Requires default DEFERRED transactions.');
$pdo->exec('PRAGMA journal_mode = DELETE');
$assert(DB::table('jobs')->where('queue', 'timer-embedded')->count() === 0, 'Qualification queue must start empty.');
$initialFailedJobIds = DB::table('failed_jobs')->pluck('id')->all();
$invocations = getcwd() . '/sqlite-queue-invocations.jsonl';
$assert(! file_exists($invocations), 'Invocation evidence must start fresh.');

$fixtureDirectory = getcwd() . '/app/SqliteQueueQualification';
if (! is_dir($fixtureDirectory)) {
    mkdir($fixtureDirectory, 0700, true);
}
file_put_contents($fixtureDirectory . '/TimerWorkflow.php', <<<'PHP'
<?php
namespace App\SqliteQueueQualification;
final class TimerWorkflow extends \Workflow\Workflow
{
    public $queue = 'timer-embedded';
    public function execute(string $label): \Generator
    {
        yield \Workflow\WorkflowStub::timer(1);
        return yield \Workflow\ActivityStub::make(ReceiptActivity::class, $label);
    }
}
PHP);
file_put_contents($fixtureDirectory . '/ReceiptActivity.php', <<<'PHP'
<?php
namespace App\SqliteQueueQualification;
final class ReceiptActivity extends \Workflow\Activity
{
    public $queue = 'timer-embedded';
    public function execute(string $label): array
    {
        $receipt = ['label' => $label, 'pid' => getmypid(), 'activity_started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')];
        if (file_put_contents(getenv('WORKFLOW_QUEUE_INVOCATIONS'), json_encode($receipt, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Cannot record actual activity invocation.');
        }
        return $receipt;
    }
}
PHP);

$out = getenv('WORKFLOW_QUEUE_QUALIFICATION_EVIDENCE') ?: getcwd() . '/sqlite-queue-qualification.json';
$report = [
    'schema' => 'durable-workflow.sqlite-two-worker-qualification/v1',
    'workflow' => Composer\InstalledVersions::getPrettyVersion('durable-workflow/workflow'),
    'workflow_reference' => Composer\InstalledVersions::getReference('durable-workflow/workflow'),
    'laravel' => $app->version(), 'php' => PHP_VERSION,
    'sqlite' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
    'journal_mode' => $pdo->query('PRAGMA journal_mode')->fetchColumn(),
    'busy_timeout_ms' => $pdo->query('PRAGMA busy_timeout')->fetchColumn(),
    'transaction_mode' => config('database.connections.sqlite.transaction_mode') ?: 'DEFERRED',
    'retry_after_seconds' => config('queue.connections.database.retry_after'),
    'worker_command' => 'queue:work database --queue=timer-embedded --sleep=1 --tries=3 --timeout=60 --max-time=150',
    'worker_count' => 2, 'observation_seconds' => 30, 'drain_seconds' => 15,
    'bursts' => [], 'workers' => [], 'outcome' => 'running',
];
$save = static function () use (&$report, $out): void {
    file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
};
$queueRows = static function (): array {
    return DB::table('jobs')->where('queue', 'timer-embedded')->get()->map(static function ($job): array {
        $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
        return ['id' => $job->id, 'class' => $payload['displayName'] ?? null,
            'attempts' => $job->attempts, 'reserved_at' => $job->reserved_at,
            'available_at' => $job->available_at];
    })->all();
};
$workers = [];
try {
    for ($index = 0; $index < 2; ++$index) {
        $worker = new Process([PHP_BINARY, __FILE__, 'worker'], getcwd(), [
            'DW_V1_ENABLED' => 'true', 'WORKFLOW_QUEUE_INVOCATIONS' => $invocations,
        ]);
        $worker->setTimeout(null);
        $worker->start();
        $workers[] = $worker;
    }
    for ($rep = 0; $rep < 3; ++$rep) {
        $burst = ['rep' => $rep, 'started_at' => gmdate('c'), 'rows' => []];
        $started = hrtime(true);
        $deadline = $started + 30_000_000_000;
        for ($index = 0; $index < 10; ++$index) {
            $assert(hrtime(true) < $deadline, 'Original admission/observation window expired.');
            $label = 'queue-qualification-' . $rep . '-' . $index;
            $handle = WorkflowStub::make(App\SqliteQueueQualification\TimerWorkflow::class);
            $burst['rows'][] = ['workflow_id' => $handle->id(), 'label' => $label];
            $report['bursts'][$rep] = $burst;
            $save();
            $handle->start($label);
        }
        do {
            $ids = array_column($burst['rows'], 'workflow_id');
            $states = DB::table('workflows')->whereIn('id', $ids)->pluck('status', 'id')->all();
            if (count($states) === 10 && count(array_filter($states, static fn ($status): bool => $status === 'completed')) === 10) {
                break;
            }
            usleep(100_000);
        } while (hrtime(true) < $deadline);
        $burst['observation_elapsed_seconds'] = (hrtime(true) - $started) / 1_000_000_000;
        foreach ($burst['rows'] as &$row) {
            $stored = StoredWorkflow::query()->findOrFail($row['workflow_id']);
            $row['status'] = $stored->getRawOriginal('status');
            $row['timers'] = $stored->timers()->get()->map(static fn ($timer): array => ['id' => $timer->id, 'stop_at' => $timer->stop_at?->toJSON()])->all();
            $row['logs'] = $stored->logs()->get()->map(static fn ($log): array => ['id' => $log->id, 'index' => $log->index, 'class' => $log->class, 'result' => Serializer::unserialize($log->result)])->all();
            $row['output'] = WorkflowStub::load($row['workflow_id'])->output();
        }
        unset($row);
        $burst['queue_at_observation'] = $queueRows();
        $report['bursts'][$rep] = $burst;
        $save();
        $assert($burst['observation_elapsed_seconds'] <= 30, 'Original observation window expired.');
        foreach ($burst['rows'] as $row) {
            $assert($row['status'] === 'completed' && ($row['output']['label'] ?? null) === $row['label'], 'Original workflow did not complete with its exact output.');
            $assert(count($row['timers']) === 1 && count($row['logs']) === 2, 'Timer/activity history is missing or duplicated.');
            $assert(count(array_filter($row['logs'], static fn ($log): bool => $log['class'] === App\SqliteQueueQualification\ReceiptActivity::class)) === 1, 'Activity outcome is missing or duplicated.');
        }
        $drainDeadline = hrtime(true) + 15_000_000_000;
        do {
            $remaining = $queueRows();
            $work = array_filter($remaining, static fn ($row): bool => ! (
                $row['class'] === Workflow\Watchdog::class
                && $row['reserved_at'] === null && $row['available_at'] > time()
            ));
            if ($work === []) {
                break;
            }
            usleep(100_000);
        } while (hrtime(true) < $drainDeadline);
        $report['bursts'][$rep]['queue_after_drain'] = $remaining;
        $report['bursts'][$rep]['workflow_jobs_drained'] = $work === [];
        $save();
        $assert($work === [], 'Workflow queue jobs did not drain within the original window.');
        foreach ($workers as $worker) {
            $assert($worker->isRunning(), 'An original queue worker exited.');
        }
        $assert(DB::table('failed_jobs')->whereNotIn('id', $initialFailedJobIds)->count() === 0, 'A queue job failed during qualification.');
    }
    $report['activity_invocations'] = array_map(
        static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($invocations, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    );
    $counts = array_count_values(array_column($report['activity_invocations'], 'label'));
    $assert(count($counts) === 30 && count($report['activity_invocations']) === 30, 'Actual activity invocations are missing or duplicated.');
    foreach ($report['bursts'] as $burst) {
        foreach ($burst['rows'] as $row) {
            $assert(($counts[$row['label']] ?? 0) === 1, 'Original activity did not execute exactly once.');
        }
    }
    $report['outcome'] = 'passed';
} catch (Throwable $exception) {
    $report['outcome'] = 'failed';
    $report['error'] = get_class($exception) . ': ' . $exception->getMessage();
    throw $exception;
} finally {
    foreach ($workers as $worker) {
        $worker->stop(5);
        $report['workers'][] = ['exit_code' => $worker->getExitCode(), 'stdout' => $worker->getOutput(), 'stderr' => $worker->getErrorOutput()];
    }
    $report['failed_jobs'] = DB::table('failed_jobs')->whereNotIn('id', $initialFailedJobIds)->get(['id', 'exception'])->map(static fn ($job): array => (array) $job)->all();
    if ($report['outcome'] === 'passed' && $report['failed_jobs'] !== []) {
        $report['outcome'] = 'failed';
        $report['error'] = 'A queue job failed before worker retirement.';
    }
    if (! isset($report['activity_invocations'])) {
        $report['activity_invocations'] = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            is_file($invocations) ? (file($invocations, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [],
        );
    }
    $report['final_queue'] = $queueRows();
    $save();
}
$assert($report['outcome'] === 'passed', $report['error'] ?? 'Qualification did not pass.');
echo json_encode(['outcome' => $report['outcome'], 'workflow' => $report['workflow'], 'laravel' => $report['laravel'], 'php' => $report['php'], 'completed' => 30], JSON_THROW_ON_ERROR), "\n";
