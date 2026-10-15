<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
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

$incomingHttp = PHP_SAPI === 'cli-server';
if ($incomingHttp) {
    $argv = [
        __FILE__, getenv('DW_TEST_TRACING_DIRECTORY'), 'admit', 'once',
        ...json_decode(getenv('DW_TEST_TRACING_AUTOLOADS'), true, flags: JSON_THROW_ON_ERROR),
    ];
}
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

    public function handle(bool $retry, TracingProcessWorkflowDependency $dependency, bool $twice = false): array
    {
        Log::info('workflow before activity');
        $result = self::activity(TracingProcessActivity::class, $retry);
        Log::info('workflow after activity');

        return $twice ? [$result, self::activity(TracingProcessActivity::class, $retry)] : $result;
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
    public static ?array $httpResponse = null;

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

        $url = getenv('DW_TEST_TRACING_HTTP_URL');
        if (is_string($url) && $url !== '') {
            $response = app(Client::class)->post($url, [
                'timeout' => 2,
                'connect_timeout' => 1,
                'headers' => [
                    'X-Request-ID' => 'activity-http-request',
                    'Authorization' => 'Bearer test-auth-sentinel',
                ],
                'json' => [
                    'private' => 'payload-private-sentinel',
                ],
                'on_stats' => static function (TransferStats $stats): void {
                    $context = Span::getCurrent()->getContext();
                    Log::info('http transfer', [
                        'request_id' => 'activity-http-request',
                        'active_trace_id' => $context->getTraceId(),
                        'active_span_id' => $context->getSpanId(),
                    ]);
                },
            ]);
            self::$httpResponse = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
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
            'workflows.tracing.baggage_allowlist' => ['approved'],
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
$propagator = new MultiTextMapPropagator([
    TraceContextPropagator::getInstance(),
    BaggagePropagator::getInstance(),
]);
$configuration = Configurator::create()->withTracerProvider($provider)
    ->withPropagator($propagator)
    ->activate();
$initial = Context::getCurrent();
try {
    $parent = $mode === 'admit'
        ? '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
        : '00-11111111111111111111111111111111-2222222222222222-01';
    $headers = [
        'traceparent' => $parent,
    ];
    if ($mode === 'admit' && getenv('DW_TEST_TRACING_HTTP_URL')) {
        $headers['tracestate'] = 'dwtest=one';
        $headers['baggage'] = 'approved=tenant-a,private=must-not-propagate';
    }
    $request = $incomingHttp ? Request::capture() : null;
    if ($request !== null) {
        $headers = [];
        foreach (['traceparent', 'tracestate', 'baggage'] as $header) {
            $value = $request->headers->get($header);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }
    }
    $parentContext = $propagator->extract($headers, context: $initial);
    // The fixture application owns its HTTP instrumentation, as a real Laravel app would.
    $requestSpan = $incomingHttp ? $provider->getTracer('test.application')
        ->spanBuilder('POST /start')
        ->setSpanKind(SpanKind::KIND_SERVER)->setParent($parentContext)->startSpan() : null;
    $scope = ($requestSpan?->storeInContext($parentContext) ?? $parentContext)
        ->activate();
    $outsideHttp = null;
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
            if ($request !== null) {
                $app['router']->post('/start', static function () use ($retry): JsonResponse {
                    $stub = WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0');
                    $stub->start(
                        $retry,
                        true,
                        new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                    );

                    return new JsonResponse([
                        'run_id' => $stub->runId(),
                    ], 201);
                });
                $kernel = $app->make(Kernel::class);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                if ($response->getStatusCode() !== 201) {
                    throw new \RuntimeException('HTTP admission failed: ' . $response->getContent());
                }
                http_response_code(201);
                header('Content-Type: application/json');
            } else {
                WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0')->start(
                    $retry,
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            }
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
            if (TracingProcessActivity::$httpResponse !== null) {
                $response = app(Client::class)->post(getenv('DW_TEST_TRACING_HTTP_URL'), [
                    'timeout' => 2,
                    'connect_timeout' => 1,
                    'headers' => [
                        'X-Request-ID' => 'outside-http-request',
                    ],
                ]);
                $outsideHttp = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            }
        } elseif ($mode !== 'inspect') {
            throw new \RuntimeException('Unknown fixture command.');
        }
    } finally {
        $scope->detach();
        $requestSpan?->end();
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
        'http_response' => TracingProcessActivity::$httpResponse,
        'outside_http' => $outsideHttp,
        'spans' => array_map(static fn ($span): array => [
            'name' => $span->getName(),
            'kind' => $span->getKind(),
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
