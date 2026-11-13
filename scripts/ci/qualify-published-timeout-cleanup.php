<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Exceptions\WorkflowTimeoutCleanupRequested;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use function Workflow\V2\activity;
use function Workflow\V2\timer;

$project = requiredEnvironment('WORKFLOW_TIMEOUT_PROJECT');
$state = requiredEnvironment('WORKFLOW_TIMEOUT_STATE');
require $project . '/vendor/autoload.php';

$startedAt = gmdate('c');
$version = requiredEnvironment('WORKFLOW_TIMEOUT_VERSION');
$revision = requiredEnvironment('WORKFLOW_TIMEOUT_REVISION');
$published = preg_match('/^2\.[0-9]+\.[0-9]+$/D', $version) === 1;
check($published || getenv('WORKFLOW_TIMEOUT_PROTOTYPE') === '1', 'A stable published version is required.');
check(InstalledVersions::getPrettyVersion('durable-workflow/workflow') === $version, 'Installed package version differs.');
check(InstalledVersions::getReference('durable-workflow/workflow') === $revision, 'Installed package source differs.');
$package = realpath(InstalledVersions::getInstallPath('durable-workflow/workflow'));
check(is_string($package) && str_starts_with($package, realpath($project . '/vendor') . '/'), 'Use a normal vendor installation.');
$source = requiredEnvironment('WORKFLOW_TIMEOUT_EXPECTED_SOURCE');
check(sourceInventory($package . '/src') === sourceInventory($source . '/src'), 'Installed runtime bytes differ from committed source.');
$manifest = json_decode(file_get_contents($package . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
check(! $published || ($manifest['extra']['durable-workflow']['product-train'] ?? null) === $version, 'Installed product train differs.');

#[Type('qualification.timeout-cleanup')]
final class TimeoutCleanupQualificationWorkflow extends Workflow
{
    public function handle(string $scenario): string
    {
        try {
            timer(3600);
            return 'not-interrupted';
        } catch (WorkflowTimeoutCleanupRequested $interruption) {
            if ($scenario === 'failed') {
                throw new RuntimeException('Synthetic cleanup failure.');
            }
            if ($scenario === 'rethrow') {
                throw $interruption;
            }
            if ($scenario === 'expired') {
                timer(3600);
            }
            if ($scenario === 'cold-replacement') {
                timer(2);
            }
            return activity(TimeoutCleanupQualificationActivity::class, $interruption->cancellation?->cause);
        }
    }
}

#[Type('qualification.timeout-effect')]
final class TimeoutCleanupQualificationActivity extends Activity
{
    public function handle(?string $cause): string
    {
        DB::table('timeout_cleanup_effects')->insertOrIgnore([
            'activity_id' => $this->activityId(), 'run_id' => $this->runId(), 'cause' => $cause,
        ]);
        return 'cleaned-up';
    }
}

foreach ([$project . '/bootstrap/cache', $state . '/storage/framework/views', $state . '/storage/logs'] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create the owned qualification state.');
    }
}
if (($argv[1] ?? '') !== 'worker') {
    check(! file_exists($state . '/database.sqlite'), 'Use fresh isolated state.');
    touch($state . '/database.sqlite');
}
$settings = [
    'app.env' => 'qualification',
    'app.timezone' => 'UTC',
    'database.default' => 'qualification',
    'database.connections.qualification' => [
        'driver' => 'sqlite', 'database' => $state . '/database.sqlite', 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 1000, 'journal_mode' => 'WAL',
    ],
    'queue.default' => 'database',
    'queue.connections.database' => [
        'driver' => 'database', 'connection' => 'qualification', 'table' => 'jobs',
        'queue' => 'timeout-cleanup', 'retry_after' => 30, 'after_commit' => false,
    ],
    'queue.failed.driver' => 'null',
    'cache.default' => 'array',
    'cache.stores.array' => ['driver' => 'array', 'serialize' => false],
    'workflows.storage.connection' => 'qualification',
    'workflows.v2.task_dispatch_mode' => 'queue',
    'workflows.v2.matching_role.queue_wake_enabled' => false,
    'workflows.v2.workflow_task_lease_seconds' => 10,
    'workflows.v2.activity_task_lease_seconds' => 10,
    'workflows.v2.types.workflows' => ['qualification.timeout-cleanup' => TimeoutCleanupQualificationWorkflow::class],
    'workflows.v2.types.activities' => ['qualification.timeout-effect' => TimeoutCleanupQualificationActivity::class],
];
$app = Application::configure(basePath: $project)->withProviders([WorkflowServiceProvider::class])->create();
$app->useStoragePath($state . '/storage');
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
    static function (Application $application) use ($settings): void {
        $application->make('config')->set($settings);
    });
$app->make(Kernel::class)->bootstrap();


if (($argv[1] ?? '') === 'worker') {
    Artisan::call('queue:work', [
        'connection' => 'database', '--queue' => 'timeout-cleanup', '--sleep' => 1,
        '--timeout' => 10, '--tries' => 1, '--max-time' => 40,
        '--once' => ($argv[2] ?? '') === 'once',
    ]);
    echo Artisan::output();
    exit(0);
}

Artisan::call('migrate', ['--force' => true]);
Schema::create('jobs', static function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->string('queue')->index();
    $table->longText('payload');
    $table->unsignedTinyInteger('attempts');
    $table->unsignedInteger('reserved_at')->nullable();
    $table->unsignedInteger('available_at');
    $table->unsignedInteger('created_at');
});
Schema::create('timeout_cleanup_effects', static function (Blueprint $table): void {
    $table->string('activity_id')->primary();
    $table->string('run_id');
    $table->string('cause');
});

$cases = [
    'completed' => 'completed', 'failed' => 'failed', 'rethrow' => 'completed',
    'expired' => 'expired', 'no-worker' => 'expired', 'late-detection' => 'expired',
    'preempted' => 'preempted', 'cold-replacement' => 'completed', 'legacy' => null,
];
foreach ($cases as $scenario => $expected) {
    $processes = [];
    $replaced = false;
    try {
        $stub = WorkflowStub::make(TimeoutCleanupQualificationWorkflow::class, 'timeout-probe-' . $scenario);
        $stub->start($scenario, new StartOptions(executionTimeoutSeconds: 20, timeoutCleanupSeconds: $scenario === 'legacy' ? null : 15));
        $runId = $stub->runId();
        $run = WorkflowRun::query()->findOrFail($runId);
        $hard = $run->execution_deadline_at->toISOString();
        $limit = microtime(true) + 35;
        if ($scenario === 'preempted') {
            $once = worker('once');
            $once->mustRun();
        } elseif (! in_array($scenario, ['no-worker', 'late-detection', 'legacy'], true)) {
            $processes[] = worker();
            $processes[0]->start();
        }
        while (! $run->status->isTerminal()) {
            check(microtime(true) < $limit, 'Scenario exceeded its wall-clock bound: ' . $scenario);
            if ($scenario !== 'late-detection' || now()->greaterThan($run->execution_deadline_at->copy()->addSecond())) {
                TaskWatchdog::runPass(runIds: [$runId]);
            }
            $entries = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)->count();
            if ($scenario === 'preempted' && $entries === 1) {
                $stub->cancel('Qualification hard cancellation.');
            }
            if ($scenario === 'cold-replacement' && ! $replaced && $entries === 1
                && WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)
                    ->where('event_type', HistoryEventType::TimerScheduled->value)->where('payload->delay_seconds', 2)->exists()) {
                $processes[0]->signal(SIGKILL);
                $processes[0]->wait();
                $processes[] = worker();
                $processes[1]->start();
                $replaced = true;
            }
            foreach ($processes as $index => $process) {
                if ($replaced && $index === 0) {
                    continue;
                }
                check($process->isRunning(), 'Queue worker exited before convergence: ' . $process->getErrorOutput());
            }
            usleep(100_000);
            $run = $run->fresh();
        }
        $terminal = WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->orderByDesc('sequence')->firstOrFail();
        $payload = $terminal->payload;
        check($run->status === ($scenario === 'preempted' ? RunStatus::Cancelled : RunStatus::TimedOut), 'Wrong business outcome: ' . $scenario);
        check(($payload['timeout_cleanup']['outcome'] ?? null) === $expected, 'Wrong cleanup outcome: ' . $scenario);
        check($run->execution_deadline_at->toISOString() === $hard, 'Hard deadline was renewed.');
        $expectedEntries = in_array($scenario, ['legacy', 'late-detection'], true) ? 0 : 1;
        check($entries === $expectedEntries, 'Cleanup entry was missing or repeated: ' . $scenario);
        $effects = DB::table('timeout_cleanup_effects')->where('run_id', $runId)->get();
        $expectedEffects = in_array($scenario, ['completed', 'cold-replacement'], true) ? 1 : 0;
        check($effects->count() === $expectedEffects, 'Wrong cleanup effect count.');
        if ($expectedEffects === 1) {
            check($effects[0]->cause === 'timeout', 'Cleanup lost its portable cause.');
        }
        check($scenario !== 'cold-replacement' || $replaced, 'The replacement scenario did not kill its first worker.');
        echo json_encode(['scenario' => $scenario, 'business_status' => $run->status->value,
            'cleanup_outcome' => $expected, 'cleanup_entries' => $entries, 'effects' => $effects->count(),
            'worker_replaced' => $replaced, 'hard_deadline_retained' => true], JSON_THROW_ON_ERROR), "\n";
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1, SIGKILL);
            }
        }
    }
}
echo json_encode(['outcome' => $published ? 'pass' : 'source-prototype-pass', 'version' => $version,
    'source' => $revision, 'php' => PHP_VERSION, 'framework' => app()->version(),
    'runtime_tree_sha256' => hash('sha256', json_encode(sourceInventory($package . '/src'), JSON_THROW_ON_ERROR)),
    'composer_lock_sha256' => hash_file('sha256', $project . '/composer.lock'),
    'started_at' => $startedAt, 'finished_at' => gmdate('c'), 'cases' => count($cases)], JSON_THROW_ON_ERROR), "\n";

function requiredEnvironment(string $key): string
{
    $value = getenv($key);
    if (! is_string($value) || $value === '') {
        throw new RuntimeException('Missing qualification input: ' . $key);
    }
    return $value;
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function worker(string $mode = 'daemon'): Process
{
    return new Process([PHP_BINARY, __FILE__, 'worker', $mode], timeout: 45);
}

function sourceInventory(string $directory): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        check(! $file->isLink() && $file->isFile(), 'Expected regular runtime source files.');
        $files[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);
    return $files;
}
