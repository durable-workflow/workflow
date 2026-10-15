<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\Foundation\Application;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Tracing\LogContext;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

$retry = $argv[3] === 'retry';
foreach (array_slice($argv, 4) as $autoload) {
    require_once $autoload;
}

final class TracingProcessWorkflow extends Workflow
{
    public function __construct(TracingProcessWorkflowDependency $dependency)
    {
        Log::info('workflow constructor');
    }

    public function handle(bool $retry, TracingProcessWorkflowDependency $dependency): array
    {
        Log::info('workflow before activity');
        $result = self::activity(TracingProcessActivity::class, $retry);
        Log::info('workflow after activity');

        return $result;
    }
}

final class TracingProcessWorkflowDependency
{
    public function __construct()
    {
        Log::info('workflow dependency');
    }
}

final class TracingProcessDependency
{
    public function __construct()
    {
        Log::info('dependency');
    }
}

final class TracingProcessActivity extends Activity
{
    public int $tries = 2;

    public function __construct(TracingProcessDependency $dependency)
    {
        Log::info('constructor');
    }

    public function backoff(): int
    {
        return 0;
    }

    public function handle(bool $retry, TracingProcessDependency $dependency): array
    {
        Log::info('callback');
        if ($retry && $this->attemptCount() === 1) {
            throw new \RuntimeException('deliberate original attempt failure');
        }

        return [9007199254740993, null, '雪'];
    }
}

final class TracingProcessApplication extends Application
{
    protected function defineEnvironment($app): void
    {
        $directory = $app->basePath();
        $app['config']->set([
            'database.default' => 'tracing',
            'database.migrations' => 'migrations',
            'database.connections.tracing' => [
                'driver' => 'sqlite',
                'database' => $directory . '/database.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'workflows.storage.connection' => 'tracing',
            'workflows.v1.enabled' => false,
            'workflows.v2.compatibility.namespace' => '0',
            'workflows.v2.task_dispatch_mode' => 'queue',
            'workflows.tracing.enabled' => true,
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => 90,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids',
                'database' => 'tracing',
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
                'path' => $directory . '/activity.log',
                'tap' => [LogContext::class],
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
$app = TracingProcessApplication::create(
    basePath: $directory,
    options: [
        'extra' => [
            'providers' => [WorkflowServiceProvider::class],
            'dont-discover' => ['*'],
        ],
    ],
);
$exporter = new InMemoryExporter();
$provider = new TracerProvider(new SimpleSpanProcessor($exporter));
$configuration = Configurator::create()->withTracerProvider($provider)
    ->withPropagator(TraceContextPropagator::getInstance())->activate();
$initial = Context::getCurrent();
try {
    $parent = $mode === 'admit'
        ? '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
        : '00-11111111111111111111111111111111-2222222222222222-01';
    $scope = TraceContextPropagator::getInstance()->extract([
        'traceparent' => $parent,
    ])->activate();
    try {
        if ($mode === 'admit') {
            if (Artisan::call('migrate', [
                '--force' => true,
            ]) !== 0) {
                throw new \RuntimeException(Artisan::output());
            }
            DB::statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL,
            payload TEXT NOT NULL, attempts INTEGER NOT NULL, reserved_at INTEGER,
            available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
            DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT NOT NULL UNIQUE,
            connection TEXT NOT NULL, queue TEXT NOT NULL, payload TEXT NOT NULL, exception TEXT NOT NULL,
            failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0')->start(
                $retry,
                new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
            );
        } elseif ($mode === 'duplicate') {
            $result = WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0')
                ->attemptStart($retry, StartOptions::returnExistingActive());
            if (! $result->returnedExistingActive()) {
                throw new \RuntimeException('Expected the original active admission.');
            }
        } elseif ($mode === 'work') {
            if (Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'default',
                '--once' => true,
                '--tries' => 1,
                '--timeout' => 30,
            ]) !== 0) {
                throw new \RuntimeException(Artisan::output());
            }
            Log::info('worker outside');
        } elseif ($mode !== 'inspect') {
            throw new \RuntimeException('Unknown fixture command.');
        }
    } finally {
        $scope->detach();
    }
    $run = WorkflowRun::query()->where('workflow_instance_id', 'cold-tracing')->sole();
    $output = [
        'pid' => getmypid(),
        'framework' => $app->version(),
        'run_id' => $run->id,
        'status' => $run->status->value,
        'output' => $run->workflowOutput(),
        'carrier' => $run->getRawOriginal('observability_context'),
        'execution_deadline' => $run->getRawOriginal('execution_deadline_at'),
        'run_deadline' => $run->getRawOriginal('run_deadline_at'),
        'jobs' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(),
        'tasks' => WorkflowTask::query()->count(),
        'repairs' => (int) WorkflowTask::query()->sum('repair_count'),
        'outside_context' => ExecutionTracing::current(),
        'scope_restored' => Context::getCurrent() === $initial,
        'spans' => array_map(static fn ($span): array => [
            'name' => $span->getName(),
            'trace_id' => $span->getContext()
                ->getTraceId(),
            'span_id' => $span->getContext()
                ->getSpanId(),
            'parent_id' => $span->getParentContext()
                ->getSpanId(),
            'fields' => $span->getAttributes()
                ->toArray(),
        ], $exporter->getSpans()),
    ];
} finally {
    $configuration->detach();
    $provider->shutdown();
}
echo json_encode($output, JSON_THROW_ON_ERROR), PHP_EOL;
