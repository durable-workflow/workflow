<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use TracingBenchmark\BenchmarkApplication;
use TracingBenchmark\BenchmarkWorkflow;
use TracingBenchmark\CountingProcessor;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

require __DIR__ . '/vendor/autoload.php';
$state = getenv('BENCH_STATE');
if (! is_string($state) || ! is_dir($state)) {
    throw new InvalidArgumentException('BENCH_STATE must select an existing disposable directory.');
}
foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/views', 'config'] as $path) {
    if (! is_dir($state . '/' . $path)) {
        mkdir($state . '/' . $path, 0700, true);
    }
}
$app = BenchmarkApplication::create(basePath: $state, options: [
    'extra' => ['providers' => [WorkflowServiceProvider::class], 'dont-discover' => ['*']],
]);
$mode = PHP_SAPI === 'cli-server' ? 'http' : ($argv[1] ?? '');
if ($mode === 'prepare') {
    if (Schema::hasTable('migrations')) {
        throw new LogicException('Prepare requires a fresh disposable database.');
    }
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new UnexpectedValueException(Artisan::output());
    }
    if (config('queue.default') === 'database') {
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
    Schema::create('failed_jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
    Schema::create('benchmark_callbacks', static function (Blueprint $table): void {
        $table->string('activity_id')->primary();
        $table->string('run_id');
        $table->unsignedInteger('member');
        $table->unique(['run_id', 'member']);
    });
    echo json_encode(['prepared' => true, 'framework' => $app->version()], JSON_THROW_ON_ERROR), PHP_EOL;
    exit(0);
}
if ($mode === 'audit') {
    $tables = ['workflow_runs', 'workflow_tasks', 'activity_executions'];
    $metadata = [];
    foreach ($tables as $table) {
        $metadata[$table] = Schema::hasColumn($table, 'observability_context')
            ? (int) DB::table($table)->selectRaw('COALESCE(SUM(OCTET_LENGTH(observability_context)), 0) AS bytes')
                ->value('bytes') : 0;
    }
    $result = [
        'runs' => WorkflowRun::query()->count(),
        'unfinished_runs' => WorkflowRun::query()->where('status', '!=', 'completed')->count(),
        'activities' => ActivityExecution::query()->count(),
        'unfinished_activities' => ActivityExecution::query()->where('status', '!=', 'completed')->count(),
        'tasks' => WorkflowTask::query()->count(),
        'unfinished_tasks' => WorkflowTask::query()->where('status', '!=', 'completed')->count(),
        'nonfirst_tasks' => WorkflowTask::query()->where('attempt_count', '!=', 1)->count(),
        'repairs' => (int) WorkflowTask::query()->sum('repair_count'),
        'unfinished_attempts' => ActivityAttempt::query()->where('status', '!=', 'completed')->count(),
        'nonfirst_attempts' => ActivityAttempt::query()->where('attempt_number', '!=', 1)->count(),
        'callbacks' => DB::table('benchmark_callbacks')->count(),
        'queue' => $app['queue']->connection()->size(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
        'history_types' => WorkflowHistoryEvent::query()->selectRaw('event_type, COUNT(*) AS count')
            ->groupBy('event_type')->pluck('count', 'event_type')->all(),
        'metadata_bytes' => $metadata,
    ];
    echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
    exit((array_sum(array_intersect_key($result, array_flip([
        'unfinished_runs', 'unfinished_activities', 'unfinished_tasks', 'nonfirst_tasks',
        'unfinished_attempts',
        'nonfirst_attempts', 'queue', 'failed_jobs',
    ]))) === 0 && $result['callbacks'] === $result['activities']) ? 0 : 1);
}
if (! in_array($mode, ['work', 'http'], true)) {
    throw new InvalidArgumentException('Expected prepare, work, audit or the HTTP router.');
}
$http = new HttpFactory();
$exportScope = Configurator::createNoop()->storeInContext(Context::getRoot())->activate();
try {
    $transport = (new PsrTransportFactory(
        new Client(['timeout' => 0.1, 'connect_timeout' => 0.1]), $http, $http,
    ))->create(getenv('BENCH_COLLECTOR') ?: 'http://collector:4318/v1/traces', ContentTypes::PROTOBUF, maxRetries: 0);
    $processor = new CountingProcessor(new BatchSpanProcessor(
        new SpanExporter($transport), Clock::getDefault(),
        maxQueueSize: 256, maxExportBatchSize: 64, autoFlush: false,
    ));
} finally {
    $exportScope->detach();
}
$provider = new TracerProvider([$processor]);
$configuration = Configurator::create()->withTracerProvider($provider)
    ->withPropagator(TraceContextPropagator::getInstance())->activate();
$exitCode = 0;
try {
    if ($mode === 'work') {
        $nextFlush = hrtime(true) + 1_000_000_000;
        $app['events']->listen(\Illuminate\Queue\Events\Looping::class, static function () use (
            $provider,
            &$nextFlush
        ): void {
            if (hrtime(true) >= $nextFlush) {
                $provider->forceFlush();
                $nextFlush = hrtime(true) + 1_000_000_000;
            }
        });
        $exitCode = Artisan::call('queue:work', [
            'connection' => config('queue.default'), '--queue' => 'default', '--sleep' => 1,
            '--tries' => 1, '--timeout' => 125, '--max-time' => 900, '--memory' => 256,
        ]);
    } else {
        respond();
    }
} finally {
    $configuration->detach();
    $provider->shutdown();
}
exit($exitCode);

function respond(): void
{
    header('Content-Type: application/json');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/health') {
        echo json_encode([
            'ready' => true,
            'queue_wake_enabled' => (bool) config('workflows.v2.matching_role.queue_wake_enabled'),
            'queue_driver' => config('queue.default'),
        ], JSON_THROW_ON_ERROR);
        return;
    }
    $id = $path === '/workflows' ? null : substr($path, strlen('/workflows/'));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/workflows') {
        $input = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_string($input['id'] ?? null) || ! preg_match('/^[a-z0-9-]{1,80}$/D', $input['id'])
            || ! in_array($input['members'] ?? null, [1, 64], true)) {
            throw new InvalidArgumentException('Expected a synthetic id and 1 or 64 members.');
        }
        $scope = TraceContextPropagator::getInstance()->extract(array_change_key_case(getallheaders()))->activate();
        try {
            WorkflowStub::make(BenchmarkWorkflow::class, $input['id'], 'default')->start(
                new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 100), $input['members'],
            );
        } finally {
            $scope->detach();
        }
        $id = $input['id'];
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET' || ! str_starts_with($path, '/workflows/')) {
        http_response_code(404);
        echo '{}';
        return;
    }
    $run = WorkflowRun::query()->where('workflow_instance_id', $id)->where('namespace', 'default')->sole();
    echo json_encode([
        'id' => $id, 'run_id' => $run->id, 'status' => $run->status->value,
        'execution_deadline' => $run->getRawOriginal('execution_deadline_at'),
        'run_deadline' => $run->getRawOriginal('run_deadline_at'), 'output' => $run->workflowOutput(),
    ], JSON_THROW_ON_ERROR);
}
