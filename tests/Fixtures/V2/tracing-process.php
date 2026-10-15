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
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOptions;
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
$recovery = getenv('DW_TEST_TRACING_RECOVERY') === 'true';
$transition = getenv('DW_TEST_TRACING_TRANSITION');
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

final class TracingRecoveryWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::activity(TracingProcessActivity::class, new ActivityOptions(
            maxAttempts: 2,
            backoff: [0],
            startToCloseTimeout: 2,
            scheduleToCloseTimeout: 10,
        ), false);
    }
}

final class TracingChildParentWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::child(TracingProcessWorkflow::class, false);
    }
}

final class TracingContinuationWorkflow extends Workflow
{
    public function handle(bool $continued = false): array
    {
        if (! $continued) {
            return self::continueAsNew(true);
        }

        return self::activity(TracingProcessActivity::class, false);
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
        if (getenv('DW_TEST_TRACING_CRASH_FIRST_ATTEMPT') === 'true' && $this->attemptCount() === 1) {
            $marker = app()
                ->basePath('claimed.json');
            file_put_contents($marker . '.tmp', json_encode(
                ExecutionTracing::current() + [
                    'actor_pid' => getmypid(),
                    'workflow_run_id' => $this->runId(),
                    'activity_id' => $this->activityId(),
                    'activity_attempt_id' => $this->attemptId(),
                    'activity_attempt_number' => $this->attemptCount(),
                    'task_id' => $this->taskId,
                    'tracing_context' => ExecutionTracing::current(),
                ],
                JSON_THROW_ON_ERROR,
            ));
            rename($marker . '.tmp', $marker);
            // The parent test kills this actual worker after observing its committed claim.
            while (true) {
                usleep(100_000);
            }
        }
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
            'workflows.tracing.enabled' => getenv('DW_TEST_TRACING_ENABLED') !== 'false',
            'workflows.tracing.baggage_allowlist' => ['approved'],
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'table' => 'jobs',
                'queue' => 'default',
                'retry_after' => getenv('DW_TEST_TRACING_RECOVERY') === 'true' ? 3 : 90,
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
    $repairReport = null;
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
            } elseif ($transition === 'child' || $transition === 'continue') {
                $workflow = $transition === 'child'
                    ? TracingChildParentWorkflow::class
                    : TracingContinuationWorkflow::class;
                WorkflowStub::make($workflow, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($recovery) {
                WorkflowStub::make(TracingRecoveryWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
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
                '--timeout' => $recovery ? 1 : 30,
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
        } elseif ($mode === 'repair' && $recovery) {
            $runId = WorkflowRun::query()->where('workflow_instance_id', 'cold-tracing')->sole()->id;
            if (Artisan::call('workflow:v2:repair-pass', [
                '--run-id' => [$runId],
                '--json' => true,
            ]) !== 0) {
                throw new \RuntimeException(Artisan::output());
            }
            $repairReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        } elseif ($mode !== 'inspect') {
            throw new \RuntimeException('Unknown fixture command.');
        }
    } finally {
        $scope->detach();
        $requestSpan?->end();
    }
    $runs = WorkflowRun::query()->where('workflow_instance_id', 'cold-tracing');
    $run = $transition === 'continue' ? $runs->orderByDesc('run_number')
        ->firstOrFail() : $runs->sole();
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
    if ($transition === 'child' || $transition === 'continue') {
        $output['runs'] = WorkflowRun::query()->orderBy('id')->get()->map(static fn ($run): array => [
            'id' => $run->id,
            'instance_id' => $run->workflow_instance_id,
            'status' => $run->status->value,
            'closed_reason' => $run->closed_reason,
            'carrier' => $run->getRawOriginal('observability_context'),
            'execution_deadline' => $run->getRawOriginal('execution_deadline_at'),
            'run_deadline' => $run->getRawOriginal('run_deadline_at'),
        ])->all();
        $output['activity_count'] = ActivityExecution::query()->count();
        $output['completion_count'] = WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->count();
        $output['history_payloads'] = WorkflowHistoryEvent::query()->orderBy('id')->pluck('payload')->all();
    }
    if ($recovery) {
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $output['recovery'] = [
            'repair_report' => $repairReport,
            'failed_deliveries' => DB::table('failed_jobs')->get(['exception', 'payload'])->map(
                static fn ($job): array => [
                    'exception' => substr($job->exception, 0, 700),
                    'payload' => $job->payload,
                ]
            )->all(),
            'activities' => ActivityExecution::query()->orderBy('id')->get()->map(static fn ($activity): array => [
                'id' => $activity->id,
                'status' => $activity->status->value,
                'attempt_count' => $activity->attempt_count,
                'retry_policy' => $activity->retry_policy,
                'schedule_deadline_at' => $activity->getRawOriginal('schedule_deadline_at'),
                'schedule_to_close_deadline_at' => $activity->getRawOriginal('schedule_to_close_deadline_at'),
                'close_deadline_at' => $activity->getRawOriginal('close_deadline_at'),
            ])->all(),
            'attempts' => ActivityAttempt::query()->orderBy('attempt_number')->get()->map(
                static function ($attempt): array {
                    return [
                        'id' => $attempt->id,
                        'activity_id' => $attempt->activity_execution_id,
                        'task_id' => $attempt->workflow_task_id,
                        'number' => $attempt->attempt_number,
                        'status' => $attempt->status->value,
                        'lease_expires_at' => $attempt->getRawOriginal('lease_expires_at'),
                        'started_at' => $attempt->getRawOriginal('started_at'),
                    ];
                }
            )->all(),
            'history_types' => $history->map(static fn ($event): string => $event->event_type->value)
                ->all(),
            'history_hashes' => $history->map(static fn ($event): string => hash(
                'sha256',
                json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR),
            ))->all(),
            'tasks' => WorkflowTask::query()->orderBy('created_at')->get()->map(static fn ($task): array => [
                'id' => $task->id,
                'type' => $task->task_type->value,
                'status' => $task->status->value,
                'attempt_count' => $task->attempt_count,
                'repair_count' => $task->repair_count,
                'payload' => $task->payload,
            ])->all(),
        ];
    }
} finally {
    $configuration->detach();
    $provider->shutdown();
}
echo json_encode($output, JSON_THROW_ON_ERROR), PHP_EOL;
