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
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\MultiTextMapPropagator;
use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\Foundation\Application;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\UpdateMethod;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Events\WorkflowStarted;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\TaskDispatcher;
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
$signalStart = getenv('DW_TEST_TRACING_SIGNAL_START') === 'true';
$signalLinks = getenv('DW_TEST_TRACING_SIGNAL_LINKS') === 'true';
$updateLinks = getenv('DW_TEST_TRACING_UPDATE_LINKS') === 'true';
$timings = getenv('DW_TEST_TRACING_TIMINGS') === 'true';
$dispatchProbe = getenv('DW_TEST_TRACING_DISPATCH') === 'true';
$scheduleProbe = getenv('DW_TEST_TRACING_SCHEDULE');
$admissionProbe = getenv('DW_TEST_TRACING_ADMISSION') === 'true'
    || getenv('DW_TEST_TRACING_SIGNAL_START_ADMISSION') === 'true';
$childRetry = getenv('DW_TEST_TRACING_CHILD_RETRY') === 'true';
$fanOut = getenv('DW_TEST_TRACING_FAN_OUT') === 'true';
$failureOutcomes = getenv('DW_TEST_TRACING_FAILURE_OUTCOMES');
$cancellation = getenv('DW_TEST_TRACING_CANCELLATION');
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

final class TracingTimingWorkflow extends Workflow
{
    public function handle(): array
    {
        self::timer(1);

        return self::activity(TracingProcessActivity::class, new ActivityOptions(
            maxAttempts: 2,
            backoff: [1],
            startToCloseTimeout: 10,
            scheduleToCloseTimeout: 30,
        ), true);
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

final class TracingRetryParentWorkflow extends Workflow
{
    public function handle(): array
    {
        return self::child(TracingRetryChildWorkflow::class);
    }
}

final class TracingRetryChildWorkflow extends Workflow
{
    public function handle(): array
    {
        if (getenv('DW_TEST_TRACING_FAIL_CHILD') === 'true') {
            throw new \RuntimeException('deliberate first child failure');
        }

        return self::activity(TracingProcessActivity::class, false);
    }
}

#[Signal('ready')]
final class TracingCancellationWorkflow extends Workflow
{
    public function handle(): void
    {
        try {
            self::awaitSignal('ready');
        } finally {
            Log::info('cancellation cleanup', [
                'approved_baggage' => ExecutionTracing::current() === []
                    ? null : Baggage::fromContext(Context::getCurrent())->getValue('approved'),
            ]);
            $result = self::activity(TracingProcessActivity::class, false);
            self::upsertMemo([
                'cleanup' => $result,
            ]);
        }
    }
}

#[Signal('ready')]
final class TracingUpdateLinksWorkflow extends Workflow
{
    private array $values = [];

    public function handle(): array
    {
        self::awaitSignal('ready');

        return [
            'updates' => $this->values,
            'activity' => self::activity(TracingProcessActivity::class, false),
        ];
    }

    #[UpdateMethod]
    public function accept(array $value): array
    {
        Log::info('update handler', [
            'approved_baggage' => ExecutionTracing::current() === []
                ? null : Baggage::fromContext(Context::getCurrent())->getValue('approved'),
        ]);
        $this->values[] = $value;

        return $value;
    }

    #[UpdateMethod]
    public function refuse(): never
    {
        throw new \RuntimeException('deliberate update failure');
    }
}

#[Signal('ready')]
final class TracingSignalLinksWorkflow extends Workflow
{
    public function handle(): array
    {
        $first = self::awaitSignal('ready');
        Log::info('signal applied', [
            'approved_baggage' => ExecutionTracing::current() === []
                        ? null : Baggage::fromContext(Context::getCurrent())->getValue('approved'),
        ]);
        $second = self::awaitSignal('ready');
        Log::info('signal applied', [
            'approved_baggage' => ExecutionTracing::current() === []
                        ? null : Baggage::fromContext(Context::getCurrent())->getValue('approved'),
        ]);

        return [
            'first' => $first,
            'second' => $second,
            'activity' => self::activity(TracingProcessActivity::class, false),
        ];
    }
}

#[Signal('ready')]
final class TracingSignalStartWorkflow extends Workflow
{
    public function handle(): array
    {
        $signal = self::awaitSignal('ready');

        return [
            'signal' => $signal,
            'activity' => self::activity(TracingProcessActivity::class, false),
        ];
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
$admissionProcessor = ($admissionProbe || $dispatchProbe || $scheduleProbe) ? new class(
    $exporter,
    $scheduleProbe ? 'durable.schedule.trigger' : ($dispatchProbe ? 'durable.task.dispatch' : 'durable.workflow.admission')
) extends SimpleSpanProcessor {
    public array $boundaries = [];

    public function __construct(
        InMemoryExporter $exporter,
        private readonly string $observedName
    ) {
        parent::__construct($exporter);
    }

    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
    {
        if ($span->getName() === $this->observedName) {
            $this->boundaries[] = [
                'event' => 'start',
                'transaction_level' => DB::transactionLevel(),
            ];
        }
        parent::onStart($span, $parentContext);
    }

    public function onEnd(ReadableSpanInterface $span): void
    {
        if ($span->getName() === $this->observedName) {
            $this->boundaries[] = [
                'event' => 'end',
                'transaction_level' => DB::transactionLevel(),
            ];
        }
        parent::onEnd($span);
    }
} : null;
$processors = [$admissionProcessor ?? new SimpleSpanProcessor($exporter)];
$exportEndpoint = getenv('DW_TEST_TRACING_EXPORTER_ENDPOINT');
$exportJournal = getenv('DW_TEST_TRACING_EXPORTER_JOURNAL');
$exportRequests = static fn (): int => $exportJournal && is_file($exportJournal)
    ? count(file($exportJournal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : 0;
$initialExportRequests = $exportRequests();
if ($exportEndpoint) {
    $processors[] = TracingExporter::processor($exportEndpoint);
}
$provider = new TracerProvider($processors);
$propagator = new MultiTextMapPropagator([
    TraceContextPropagator::getInstance(),
    BaggagePropagator::getInstance(),
]);
$recordedPropagator = null;
if ($signalStart || $signalLinks || $cancellation || $updateLinks || $admissionProbe || $scheduleProbe) {
    $recordedPropagator = new class($propagator) implements TextMapPropagatorInterface {
        public array $transactionLevels = [];

        public function __construct(
            private readonly TextMapPropagatorInterface $delegate
        ) {
        }

        public function fields(): array
        {
            return $this->delegate->fields();
        }

        public function inject(
            mixed &$carrier,
            ?PropagationSetterInterface $setter = null,
            ?ContextInterface $context = null,
        ): void {
            $this->transactionLevels[] = DB::transactionLevel();
            $this->delegate->inject($carrier, $setter, $context);
        }

        public function extract(
            $carrier,
            ?PropagationGetterInterface $getter = null,
            ?ContextInterface $context = null,
        ): ContextInterface {
            return $this->delegate->extract($carrier, $getter, $context);
        }
    };
    $propagator = $recordedPropagator;
}
$configuration = Configurator::create()->withTracerProvider($provider)
    ->withPropagator($propagator)
    ->activate();
$taskOutcomeQueries = 0;
if ($fanOut || $failureOutcomes) {
    DB::listen(static function ($query) use (&$taskOutcomeQueries): void {
        if (str_contains($query->sql, 'workflow_tasks') && str_contains($query->sql, 'observability_context')) {
            ++$taskOutcomeQueries;
        }
    });
}
$initial = Context::getCurrent();
$admissionForeignKey = Context::createKey('test.application.admission');
$dispatchDelivered = [];
$dispatchQueue = static function (): array {
    return DB::table('jobs')->orderBy('id')->get(['id', 'payload'])->map(static function ($row): array {
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $job = unserialize($payload['data']['command'], [
            'allowed_classes' => [RunWorkflowTask::class, RunActivityTask::class, RunTimerTask::class],
        ]);
        if (! $job instanceof RunWorkflowTask && ! $job instanceof RunActivityTask && ! $job instanceof RunTimerTask) {
            throw new \RuntimeException('Unexpected queued tracing fixture job.');
        }

        return [
            'id' => $row->id,
            'class' => $job::class,
            'task_id' => $job->taskId,
            'trace' => $job->dispatchObservabilityContext ?? null,
        ];
    })->all();
};
$admissionCallbacks = [];
if ($admissionProbe) {
    $app['events']->listen(WorkflowStarted::class, static function () use (
        &$admissionCallbacks,
        $admissionForeignKey
    ): void {
        $context = Span::getCurrent()->getContext();
        $admissionCallbacks[] = [
            'transaction_level' => DB::transactionLevel(),
            'foreign' => Context::getCurrent()->get($admissionForeignKey),
            'fields' => ExecutionTracing::current(),
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
        ];
        Log::info('admission callback', [
            'request_id' => 'application-request',
        ]);
    });
}
try {
    $parent = $mode === 'admit'
        ? '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
        : '00-11111111111111111111111111111111-2222222222222222-01';
    $headers = [
        'traceparent' => $parent,
    ];
    if ($signalLinks && $mode === 'signal') {
        $headers['traceparent'] = getenv('DW_TEST_TRACING_SIGNAL_CALLER');
        $headers['baggage'] = 'approved=caller';
    } elseif ($scheduleProbe === 'buffered' && in_array($mode, ['buffer', 'buffer-full', 'buffer-next'], true)) {
        $headers['traceparent'] = $mode === 'buffer'
            ? '00-cccccccccccccccccccccccccccccccc-dddddddddddddddd-01'
            : '00-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee-ffffffffffffffff-01';
        $headers['baggage'] = 'approved=buffer-only';
    } elseif ($updateLinks && $mode === 'update') {
        $headers['traceparent'] = getenv('DW_TEST_TRACING_UPDATE_CALLER');
        $headers['baggage'] = 'approved=update-caller';
    } elseif ($updateLinks && $mode === 'signal') {
        $headers['traceparent'] = '00-77777777777777777777777777777777-8888888888888888-01';
    } elseif ($cancellation && $mode === 'cancel') {
        $headers['traceparent'] = getenv('DW_TEST_TRACING_CANCEL_DUPLICATE') === 'true'
            ? '00-cccccccccccccccccccccccccccccccc-dddddddddddddddd-01'
            : '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';
        $headers['baggage'] = 'approved=caller';
    } elseif (($signalLinks || $cancellation || $updateLinks) && $mode === 'admit') {
        $headers['baggage'] = 'approved=origin';
    }
    if ($mode === 'admit' && getenv('DW_TEST_TRACING_HTTP_URL')) {
        $headers['tracestate'] = 'dwtest=one';
        $headers['baggage'] = 'approved=tenant-a,private=must-not-propagate';
    }
    if ($admissionProbe && in_array($mode, ['admit', 'duplicate', 'reject', 'reject-signal-start'], true)) {
        $headers['traceparent'] = $mode === 'admit'
            ? '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'
            : '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';
        if (getenv('DW_TEST_TRACING_ADMISSION_NO_PARENT') === 'true') {
            unset($headers['traceparent']);
        }
        $headers['baggage'] = 'approved=origin,private=caller-local';
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
    if ($admissionProbe) {
        $parentContext = $parentContext->with($admissionForeignKey, 'caller-scope');
    }
    // The fixture application owns its HTTP instrumentation, as a real Laravel app would.
    $requestSpan = $incomingHttp ? $provider->getTracer('test.application')
        ->spanBuilder('POST /start')
        ->setSpanKind(SpanKind::KIND_SERVER)->setParent($parentContext)->startSpan() : null;
    $scope = ($requestSpan?->storeInContext($parentContext) ?? $parentContext)
        ->activate();
    $outsideHttp = null;
    $repairReport = null;
    $signalStartResult = null;
    $cancellationResult = null;
    $updateResult = null;
    $admissionResult = null;
    $scheduleBufferResult = null;
    $scheduleDrainResults = null;
    $scheduleBackfillResults = null;
    $admissionContextPreserved = null;
    $childOutcomeControl = null;
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
            } elseif ($scheduleProbe) {
                $creator = $propagator->extract([
                    'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                ], context: $initial)->activate();
                try {
                    $schedule = ScheduleManager::create(
                        scheduleId: 'cold-schedule',
                        workflowClass: TracingProcessWorkflow::class,
                        cronExpression: $scheduleProbe === 'buffered' ? '0 0 1 1 *' : '* * * * *',
                        arguments: [false],
                        overlapPolicy: $scheduleProbe === 'buffered'
                            ? ScheduleOverlapPolicy::from(getenv('DW_TEST_TRACING_BUFFER_POLICY') ?: 'buffer_one')
                            : ScheduleOverlapPolicy::Skip,
                        namespace: '0',
                        executionTimeoutSeconds: 60,
                        runTimeoutSeconds: 45
                    );
                } finally {
                    $creator->detach();
                }
                if ($scheduleProbe === 'scheduled') {
                    $schedule->forceFill([
                        'next_fire_at' => now()
                            ->subSecond(),
                    ])->save();
                    $triggered = ScheduleManager::tick(1);
                    $instanceId = $triggered[0]['instance_id'] ?? null;
                } elseif ($scheduleProbe === 'backfill') {
                    $from = now()
                        ->startOfMinute()
                        ->subMinutes(2);
                    file_put_contents($directory . '/backfill-from', $from->toIso8601String());
                    $scheduleBackfillResults = ScheduleManager::backfill($schedule, $from, $from->copy()->addMinute());
                    $instanceId = $scheduleBackfillResults[0]['instance_id'] ?? null;
                } else {
                    $instanceId = ScheduleManager::trigger($schedule);
                }
                if (! is_string($instanceId) || $instanceId === '') {
                    throw new \RuntimeException('The actual schedule did not admit a workflow.');
                }
                file_put_contents($directory . '/schedule-instance', $instanceId);
                if ($scheduleProbe === 'backfill') {
                    file_put_contents($directory . '/backfill-first-instance', $instanceId);
                }
            } elseif ($childRetry) {
                // A real bridge-authored policy, not an invented PHP child option or history edit.
                config()
                    ->set('workflows.v2.task_dispatch_mode', 'poll');
                WorkflowStub::make(TracingRetryParentWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
                $parentRun = WorkflowRun::query()->where('workflow_instance_id', 'cold-tracing')->sole();
                $parentTask = WorkflowTask::query()->where('workflow_run_id', $parentRun->id)->sole();
                $bridge = app(DefaultWorkflowTaskBridge::class);
                if (! $bridge->claimStatus($parentTask->id)['claimed']) {
                    throw new \RuntimeException('Parent claim refused.');
                }
                config()
                    ->set('workflows.v2.task_dispatch_mode', 'queue');
                $completion = $bridge->complete($parentTask->id, [[
                    'type' => 'start_child_workflow',
                    'workflow_type' => TracingRetryChildWorkflow::class,
                    'arguments' => Serializer::serializeWithCodec('avro', []),
                    'retry_policy' => [
                        'max_attempts' => 2,
                        'backoff_seconds' => [1],
                    ],
                    'execution_timeout_seconds' => 30,
                    'run_timeout_seconds' => 20,
                ]]);
                if (! $completion['completed']) {
                    throw new \RuntimeException('Parent child admission refused.');
                }
                foreach ($completion['created_task_ids'] as $taskId) {
                    TaskDispatcher::dispatch(WorkflowTask::query()->findOrFail($taskId));
                }
            } elseif ($timings) {
                WorkflowStub::make(TracingTimingWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($updateLinks) {
                WorkflowStub::make(TracingUpdateLinksWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($signalLinks) {
                WorkflowStub::make(TracingSignalLinksWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($cancellation) {
                WorkflowStub::make(TracingCancellationWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($signalStart) {
                $signalStartResult = WorkflowStub::make(TracingSignalStartWorkflow::class, 'cold-tracing', '0')
                    ->signalWithStart(
                        'ready',
                        [[9007199254740993, null, '雪']],
                        StartOptions::returnExistingActive()->withExecutionTimeout(60)->withRunTimeout(45)
                    );
            } elseif ($failureOutcomes) {
                WorkflowStub::make(TracingFailureOutcomeWorkflow::class, 'cold-tracing', '0')->start(
                    $failureOutcomes === 'local',
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($fanOut) {
                WorkflowStub::make(TracingFanOutWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif (in_array($transition, ['child', 'child-failure', 'continue'], true)) {
                $workflow = match ($transition) {
                    'child' => TracingChildParentWorkflow::class,
                    'child-failure' => TracingHandledChildFailureWorkflow::class,
                    default => TracingContinuationWorkflow::class,
                };
                WorkflowStub::make($workflow, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } elseif ($recovery) {
                WorkflowStub::make(TracingRecoveryWorkflow::class, 'cold-tracing', '0')->start(
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
            } else {
                $result = WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0')->start(
                    $retry,
                    new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45),
                );
                if ($admissionProbe) {
                    $admissionResult = [
                        'command_id' => $result->commandId(),
                        'outcome' => $result->outcome(),
                    ];
                }
            }
        } elseif ($scheduleProbe === 'backfill' && in_array(
            $mode,
            ['backfill-duplicate', 'backfill-fail', 'backfill-next'],
            true
        )) {
            $schedule = WorkflowSchedule::query()->where('schedule_id', 'cold-schedule')->sole();
            $from = \Carbon\CarbonImmutable::parse(trim(file_get_contents($directory . '/backfill-from')));
            if ($mode !== 'backfill-duplicate') {
                $from = $from->addMinute();
            }
            if ($mode === 'backfill-fail') {
                $app['events']->listen(WorkflowStarted::class, static function (): never {
                    throw new \RuntimeException('deliberate backfill admission rollback');
                });
            }
            $scheduleBackfillResults = ScheduleManager::backfill($schedule, $from, $from->addMinute());
            if ($mode === 'backfill-next') {
                $instanceId = $scheduleBackfillResults[0]['instance_id'] ?? null;
                if (! is_string($instanceId) || $instanceId === '') {
                    throw new \RuntimeException('The next backfill occurrence did not admit a workflow.');
                }
                file_put_contents($directory . '/schedule-instance', $instanceId);
            }
        } elseif ($scheduleProbe === 'buffered' && in_array($mode, ['buffer', 'buffer-full', 'buffer-next'], true)) {
            $schedule = WorkflowSchedule::query()->where('schedule_id', 'cold-schedule')->sole();
            $scheduleBufferResult = ScheduleManager::triggerDetailed($schedule)->outcome;
        } elseif ($scheduleProbe === 'buffered' && $mode === 'buffer-metadata') {
            $schedule = WorkflowSchedule::query()->where('schedule_id', 'cold-schedule')->sole();
            $buffer = $schedule->buffered_actions;
            if (getenv('DW_TEST_TRACING_BUFFER_METADATA') === 'legacy') {
                unset($buffer[0]['observability_context']);
            } else {
                $buffer[0]['observability_context'] = 'malformed optional context';
            }
            $schedule->buffered_actions = $buffer;
            $schedule->save();
        } elseif ($scheduleProbe === 'buffered' && in_array($mode, ['drain', 'drain-fail'], true)) {
            if ($mode === 'drain-fail') {
                $app['events']->listen(WorkflowStarted::class, static function (): never {
                    throw new \RuntimeException('deliberate buffered admission rollback');
                });
            }
            $triggered = ScheduleManager::tick(1);
            $scheduleDrainResults = $triggered;
            $instanceId = $triggered[0]['instance_id'] ?? null;
            if ($mode === 'drain' && (! is_string($instanceId) || $instanceId === '')) {
                throw new \RuntimeException('The actual buffer did not admit its workflow.');
            }
            if ($mode === 'drain') {
                file_put_contents($directory . '/schedule-instance', $instanceId);
            }
        } elseif ($mode === 'update' && $updateLinks) {
            $step = getenv('DW_TEST_TRACING_UPDATE_STEP');
            $stub = WorkflowStub::make(TracingUpdateLinksWorkflow::class, 'cold-tracing', '0')
                ->withCommandContext(CommandContext::phpApi()->with([
                    'request' => [
                        'request_id' => 'tracing-update-' . $step,
                    ],
                ]));
            $result = match ($step) {
                'first' => $stub->submitUpdate('accept', [9007199254740993, null, '雪']),
                'failure' => $stub->submitUpdate('refuse'),
                'invalid' => $stub->submitUpdate('notDeclared'),
                default => throw new \RuntimeException('Unknown update step.'),
            };
            $updateResult = [
                'accepted' => $result->accepted(),
                'command_id' => $result->commandId(),
                'update_id' => $result->updateId(),
            ];
        } elseif ($mode === 'signal' && $updateLinks) {
            WorkflowStub::make(TracingUpdateLinksWorkflow::class, 'cold-tracing', '0')->signal('ready');
        } elseif ($mode === 'signal' && $signalLinks) {
            WorkflowStub::make(TracingSignalLinksWorkflow::class, 'cold-tracing', '0')->signal(
                'ready',
                getenv('DW_TEST_TRACING_SIGNAL_STEP') === 'first' ? [9007199254740993, null, '雪'] : [
                    false,
                    null,
                    'é',
                ]
            );
        } elseif ($mode === 'cancel' && $cancellation) {
            $stub = WorkflowStub::make(TracingCancellationWorkflow::class, 'cold-tracing', '0');
            $result = match ($cancellation) {
                'request' => $stub->requestCancellation('deliberate cancellation', 20),
                'cancel' => $stub->cancel('deliberate cancellation'),
                'terminate' => $stub->terminate('deliberate termination'),
                default => throw new \RuntimeException('Unknown cancellation mode.'),
            };
            $cancellationResult = [
                'accepted' => $result->accepted(),
                'command_id' => $result->commandId(),
            ];
        } elseif ($mode === 'duplicate' && $signalStart) {
            $signalStartResult = WorkflowStub::make(TracingSignalStartWorkflow::class, 'cold-tracing', '0')
                ->signalWithStart('ready', [['later']], StartOptions::returnExistingActive());
            if (! $signalStartResult->returnedExistingActive()) {
                throw new \RuntimeException('Expected the original signal-with-start admission.');
            }
        } elseif ($mode === 'reject-signal-start' && $signalStart) {
            $signalStartResult = WorkflowStub::make(TracingSignalStartWorkflow::class, 'cold-tracing', '0')
                ->attemptSignalWithStart('notDeclared', ['refused-admission-private']);
            if (! $signalStartResult->rejected()) {
                throw new \RuntimeException('Expected an undeclared signal-with-start refusal.');
            }
        } elseif ($mode === 'reject' && $admissionProbe) {
            $result = WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0')->attemptStart($retry);
            if (! $result->rejected()) {
                throw new \RuntimeException('Expected an ordinary duplicate refusal.');
            }
            $admissionResult = [
                'command_id' => $result->commandId(),
                'outcome' => $result->outcome(),
            ];
        } elseif ($mode === 'duplicate') {
            $result = WorkflowStub::make(TracingProcessWorkflow::class, 'cold-tracing', '0')
                ->attemptStart($retry, StartOptions::returnExistingActive());
            if (! $result->returnedExistingActive()) {
                throw new \RuntimeException('Expected the original active admission.');
            }
            if ($admissionProbe) {
                $admissionResult = [
                    'command_id' => $result->commandId(),
                    'outcome' => $result->outcome(),
                ];
            }
        } elseif ($mode === 'bridge-fail' && $childRetry) {
            $child = WorkflowRun::query()->where('workflow_class', TracingRetryChildWorkflow::class)
                ->where('run_number', 1)
                ->sole();
            $childTask = WorkflowTask::query()->where('workflow_run_id', $child->id)->sole();
            $bridge = app(DefaultWorkflowTaskBridge::class);
            if (! $bridge->claimStatus($childTask->id)['claimed']
                || ! $bridge->complete($childTask->id, [[
                    'type' => 'fail_workflow',
                    'message' => 'deliberate first child failure',
                    'exception_class' => \RuntimeException::class,
                ]])['completed']) {
                throw new \RuntimeException('Child failure admission refused.');
            }
        } elseif ($mode === 'invalidate-child-outcome' && $transition === 'child') {
            $parent = WorkflowRun::query()->where('workflow_instance_id', 'cold-tracing')->sole();
            $terminal = WorkflowHistoryEvent::query()->where('event_type', 'WorkflowCompleted')
                ->where('workflow_run_id', '!=', $parent->id)
                ->sole();
            $task = WorkflowTask::query()->findOrFail($terminal->workflow_task_id);
            $carrier = json_decode($task->getRawOriginal('observability_context'), true, flags: JSON_THROW_ON_ERROR);
            $field = match (getenv('DW_TEST_TRACING_CHILD_OUTCOME_CONTROL')) {
                'wrong namespace' => 'namespace',
                'wrong task' => 'task_id',
                default => throw new \RuntimeException('Unknown optional-carrier control.'),
            };
            $carrier[$field] = 'unrelated';
            DB::table('workflow_tasks')->where('id', $task->id)->update([
                'observability_context' => json_encode($carrier, JSON_THROW_ON_ERROR),
            ]);
            $childOutcomeControl = [
                'field' => $field,
                'task_id' => $task->id,
            ];
        } elseif ($mode === 'work') {
            if ($dispatchProbe) {
                $dispatchDelivered = $dispatchQueue();
            }
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
        } elseif (! in_array($mode, ['inspect', 'inspect-first'], true)) {
            throw new \RuntimeException('Unknown fixture command.');
        }
        if ($admissionProbe) {
            $admissionContextPreserved = Context::getCurrent() === $parentContext;
        }
    } finally {
        $scope->detach();
        $requestSpan?->end();
    }
    $selectedInstance = $scheduleProbe
        ? trim(
            file_get_contents(
                $directory . ($mode === 'inspect-first' ? '/backfill-first-instance' : '/schedule-instance')
            )
        )
        : 'cold-tracing';
    $runs = WorkflowRun::query()->where('workflow_instance_id', $selectedInstance);
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
            'status_code' => $span->getStatus()
                ->getCode(),
            'kind' => $span->getKind(),
            'trace_id' => $span->getContext()
                ->getTraceId(),
            'span_id' => $span->getContext()
                ->getSpanId(),
            'parent_id' => $span->getParentContext()
                ->getSpanId(),
            'fields' => $span->getAttributes()
                ->toArray(),
            'links' => array_map(static fn ($link): array => [
                'trace_id' => $link->getSpanContext()
                    ->getTraceId(),
                'span_id' => $link->getSpanContext()
                    ->getSpanId(),
                'fields' => $link->getAttributes()
                    ->toArray(),
            ], $span->getLinks()),
        ], $exporter->getSpans()),
    ];
    // Preserve the complete export for dispatch controls. Execution checks separately
    // inspect their admission/consumer spans and application command links.
    $output['execution_spans'] = array_values(array_filter(
        $output['spans'],
        static fn (array $span): bool => $span['name'] !== 'durable.task.dispatch'
    ));
    foreach ($output['execution_spans'] as &$executionSpan) {
        $executionSpan['links'] = array_values(array_filter(
            $executionSpan['links'],
            static fn (array $link): bool => ! in_array(
                $link['fields']['durable.link.type'] ?? null,
                ['queue_dispatch', 'task_outcome'],
                true,
            )
        ));
    }
    unset($executionSpan);
    if ($scheduleProbe) {
        $schedule = WorkflowSchedule::query()->where('schedule_id', 'cold-schedule')->sole();
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['schedule'] = [
            'id' => $schedule->schedule_id,
            'namespace' => $schedule->namespace,
            'fires_count' => (int) $schedule->fires_count,
            'failures_count' => (int) $schedule->failures_count,
            'run_count' => WorkflowRun::query()->count(),
            'latest_instance_id' => $schedule->latest_workflow_instance_id,
            'buffer_result' => $scheduleBufferResult,
            'drain_results' => $scheduleDrainResults,
            'backfill_results' => $scheduleBackfillResults,
            'buffered_actions' => $schedule->buffered_actions,
            'public_buffered_actions' => $schedule->toDetail()['info']['buffered_actions'],
            'instance_id' => $run->workflow_instance_id,
            'boundaries' => $admissionProcessor->boundaries,
            'capture_transaction_levels' => $recordedPropagator->transactionLevels,
            'history_hashes' => $history->map(static fn ($event): string => hash(
                'sha256',
                json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR)
            ))->all(),
            'history_types' => $history->map(static fn ($event): string => $event->event_type->value)
                ->all(),
            'tasks' => WorkflowTask::query()->orderBy('created_at')->get([
                'id', 'status', 'attempt_count', 'repair_count',
            ])->toArray(),
        ];
    }
    if ($exportEndpoint) {
        $output['exports_before_shutdown'] = $exportRequests() - $initialExportRequests;
    }
    if ($signalStart) {
        $history = WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id)->orderBy('sequence')->get();
        $output['signal_start_outcome'] = $signalStartResult?->startOutcome();
        $output['signal_start_result'] = $signalStartResult === null ? null : [
            'command_id' => $signalStartResult->commandId(),
            'status' => $signalStartResult->status(),
            'outcome' => $signalStartResult->outcome(),
            'rejection_reason' => $signalStartResult->rejectionReason(),
            'start_command_id' => $signalStartResult->startCommandId(),
            'start_status' => $signalStartResult->startStatus(),
            'start_outcome' => $signalStartResult->startOutcome(),
        ];
        $output['capture_transaction_levels'] = $recordedPropagator?->transactionLevels ?? [];
        $output['run_count'] = WorkflowRun::query()->count();
        $output['signal_count'] = $history->where('event_type.value', 'SignalReceived')->count();
        $output['completion_count'] = $history->where('event_type.value', 'ActivityCompleted')->count();
        $output['history_hashes'] = $history->map(static fn ($event): string => hash(
            'sha256',
            json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR),
        ))->all();
        $output['history_payloads'] = $history->pluck('payload')->all();
        $output['signal_commands'] = WorkflowCommand::query()->where('command_type', 'signal')->orderBy(
            'command_sequence'
        )
            ->get()
            ->map(static fn ($command): array => [
                'id' => $command->id,
                'carrier' => $command->getRawOriginal('observability_context'),
            ])->all();
    }
    if (in_array($transition, ['child', 'child-failure', 'continue'], true)) {
        $output['runs'] = WorkflowRun::query()->orderBy('id')->get()->map(static fn ($run): array => [
            'id' => $run->id,
            'instance_id' => $run->workflow_instance_id,
            'status' => $run->status->value,
            'closed_reason' => $run->closed_reason,
            'carrier' => $run->getRawOriginal('observability_context'),
            'execution_deadline' => $run->getRawOriginal('execution_deadline_at'),
            'run_deadline' => $run->getRawOriginal('run_deadline_at'),
            'history_hashes' => $run->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->map(
                    static fn ($event): string => hash(
                        'sha256',
                        json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR)
                    ),
                )->all(),
        ])->all();
        $output['activity_count'] = ActivityExecution::query()->count();
        $output['completion_count'] = WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->count();
        $output['history_payloads'] = WorkflowHistoryEvent::query()->orderBy('id')->pluck('payload')->all();
        $output['child_outcome_control'] = $childOutcomeControl;
        $output['transition_events'] = WorkflowHistoryEvent::query()
            ->whereIn('event_type', ['WorkflowCompleted', 'WorkflowFailed', 'ChildRunCompleted', 'ChildRunFailed'])
            ->orderBy('id')
            ->get()
            ->map(static fn ($event): array => [
                'id' => $event->id,
                'run_id' => $event->workflow_run_id,
                'task_id' => $event->workflow_task_id,
                'type' => $event->event_type->value,
                'sequence' => $event->sequence,
                'payload' => $event->payload,
            ])->all();
    }
    if ($childRetry) {
        $output['runs'] = WorkflowRun::query()->orderBy('id')->get()->map(static fn ($row): array => [
            'id' => $row->id,
            'instance_id' => $row->workflow_instance_id,
            'number' => $row->run_number,
            'status' => $row->status->value,
            'carrier' => $row->getRawOriginal('observability_context'),
            'execution_deadline' => $row->getRawOriginal('execution_deadline_at'),
            'run_deadline' => $row->getRawOriginal('run_deadline_at'),
            'started_at' => $row->getRawOriginal('started_at'),
            'history_hashes' => $row->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->map(static function ($event): string {
                    return hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR));
                })->all(),
        ])->all();
        $output['activity_count'] = ActivityExecution::query()->count();
        $output['completion_count'] = WorkflowHistoryEvent::query()->where('event_type', 'ActivityCompleted')->count();
        $output['policy'] = WorkflowChildCall::query()->sole()->retry_policy;
        $output['links'] = WorkflowLink::query()->orderBy('id')->get([
            'id', 'parent_workflow_run_id', 'child_workflow_instance_id', 'child_workflow_run_id',
        ])->toArray();
        $output['task_rows'] = WorkflowTask::query()->orderBy('id')->get()->map(static fn ($row): array => [
            'id' => $row->id,
            'run_id' => $row->workflow_run_id,
            'status' => $row->status->value,
            'attempt_count' => $row->attempt_count,
            'available_at' => $row->getRawOriginal('available_at'),
            'leased_at' => $row->getRawOriginal('leased_at'),
        ])->all();
        $output['history_payloads'] = WorkflowHistoryEvent::query()->orderBy('id')->pluck('payload')->all();
    }
    if ($cancellation) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['cancellation'] = [
            'result' => $cancellationResult,
            'deadline' => $run->getRawOriginal('cancellation_deadline_at'),
            'memo' => WorkflowStub::make(TracingCancellationWorkflow::class, 'cold-tracing', '0')->memo(),
            'commands' => WorkflowCommand::query()->orderBy('id')->get(['id', 'command_type'])->toArray(),
            'capture_transaction_levels' => $recordedPropagator->transactionLevels,
            'request_carrier' => $run->cancellation_request_command_id === null ? null
                : WorkflowCommand::query()->findOrFail($run->cancellation_request_command_id)
                    ->getRawOriginal('observability_context'),
            'history_types' => $history->map(static fn ($event): string => $event->event_type->value)
                ->all(),
            'history_hashes' => $history->map(static function ($event): string {
                return hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR));
            })->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'activities' => ActivityExecution::query()->get(['id', 'status', 'attempt_count'])->toArray(),
            'tasks' => WorkflowTask::query()->get(['id', 'status', 'attempt_count'])->toArray(),
        ];
    }
    if ($updateLinks) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['updates'] = [
            'submitted' => $updateResult,
            'signal_command_id' => WorkflowCommand::query()->where('command_type', 'signal')->value('id'),
            'capture_transaction_levels' => $recordedPropagator->transactionLevels,
            'commands' => WorkflowCommand::query()->where('command_type', 'update')->orderBy('command_sequence')->get()
                ->map(static fn ($command): array => [
                    'id' => $command->id,
                    'status' => $command->status->value,
                    'outcome' => $command->outcome?->value,
                    'carrier' => $command->getRawOriginal('observability_context'),
                    'immutable' => array_intersect_key($command->getRawOriginal(), array_flip([
                        'id', 'workflow_run_id', 'command_sequence', 'payload_codec', 'payload', 'context',
                        'request_id', 'observability_context',
                    ])),
                ])->all(),
            'rows' => WorkflowUpdate::query()->orderBy('command_sequence')->get()->map(static fn ($update): array => [
                'id' => $update->id,
                'command_id' => $update->workflow_command_id,
                'status' => $update->status->value,
                'result' => $update->updateResult(),
                'failure_message' => $update->failure_message,
            ])->all(),
            'history_hashes' => $history->map(static function ($event): string {
                return hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR));
            })->all(),
            'history_types' => $history->map(static fn ($event): string => $event->event_type->value)
                ->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'tasks' => WorkflowTask::query()->get(['id', 'status', 'attempt_count'])->toArray(),
        ];
    }
    if ($timings) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['timings'] = [
            'history_hashes' => $history->map(static function ($event): string {
                return hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR));
            })->all(),
            'history_types' => $history->map(static fn ($event): string => $event->event_type->value)
                ->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'tasks' => WorkflowTask::query()->orderBy('created_at')->get()->map(static fn ($task): array => [
                'id' => $task->id,
                'type' => $task->task_type->value,
                'status' => $task->status->value,
                'attempt_count' => $task->attempt_count,
                'available_at' => $task->getRawOriginal('available_at'),
                'leased_at' => $task->getRawOriginal('leased_at'),
                'payload' => $task->payload,
            ])->all(),
            'timer' => WorkflowTimer::query()->first()?->getRawOriginal(),
            'attempts' => ActivityAttempt::query()->orderBy('attempt_number')->get([
                'id', 'attempt_number', 'status',
            ])->toArray(),
            'policy' => ActivityExecution::query()->first()?->retry_policy,
            'completion_count' => $history->where('event_type.value', 'ActivityCompleted')
                ->count(),
        ];
    }
    if ($dispatchProbe) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['dispatch'] = [
            'queued' => $dispatchQueue(),
            'delivered' => $dispatchDelivered,
            'boundaries' => $admissionProcessor->boundaries,
            'history_hashes' => $history->map(static fn ($event): string => hash(
                'sha256',
                json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR)
            ))->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'activity_completions' => $history->where('event_type.value', 'ActivityCompleted')
                ->count(),
        ];
    }
    if ($admissionProbe) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['admission'] = [
            'result' => $admissionResult,
            'context_preserved' => $admissionContextPreserved,
            'boundaries' => $admissionProcessor->boundaries,
            'callbacks' => $admissionCallbacks,
            'capture_transaction_levels' => $recordedPropagator->transactionLevels,
            'history_hashes' => $history->map(static function ($event): string {
                return hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR));
            })->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'run_count' => WorkflowRun::query()->count(),
        ];
    }
    if ($signalLinks) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output['signals'] = [
            'capture_transaction_levels' => $recordedPropagator->transactionLevels,
            'commands' => WorkflowCommand::query()->where('command_type', 'signal')->orderBy('command_sequence')
                ->get()
                ->map(static fn ($command): array => [
                    'id' => $command->id,
                    'carrier' => $command->getRawOriginal('observability_context'),
                    'immutable' => array_intersect_key($command->getRawOriginal(), array_flip([
                        'id', 'workflow_run_id', 'command_sequence', 'message_sequence', 'payload_codec', 'payload', 'context',
                        'observability_context',
                    ])),
                ])->all(),
            'history_hashes' => $history->map(static function ($event): string {
                return hash('sha256', json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR));
            })->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'tasks' => WorkflowTask::query()->get(['id', 'status', 'attempt_count'])->toArray(),
        ];
    }
    if ($fanOut || $failureOutcomes) {
        $history = $run->historyEvents()
            ->orderBy('sequence')
            ->get();
        $output[$fanOut ? 'fan_out' : 'failure_outcomes'] = [
            'task_outcome_queries' => $taskOutcomeQueries,
            'ready_jobs' => DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', time())->count(),
            'history_hashes' => $history->map(static fn ($event): string => hash(
                'sha256',
                json_encode($event->getRawOriginal(), JSON_THROW_ON_ERROR),
            ))->all(),
            'history_types' => $history->map(static fn ($event): string => $event->event_type->value)
                ->all(),
            'history_payloads' => $history->pluck('payload')
                ->all(),
            'activities' => ActivityExecution::query()->orderBy('id')->get([
                'id', 'status', 'attempt_count', 'current_attempt_id', 'retry_policy',
            ])->toArray(),
            'attempts' => ActivityAttempt::query()->orderBy('id')->get([
                'id', 'activity_execution_id', 'workflow_task_id', 'attempt_number', 'status',
            ])->toArray(),
            'tasks' => WorkflowTask::query()->orderBy('id')->get([
                'id', 'status', 'task_type', 'attempt_count', 'repair_count',
            ])->toArray(),
            'outcome_contexts' => WorkflowTask::query()->orderBy('id')->get()->map(static fn ($row): array => [
                'task_id' => $row->id,
                'run_id' => $row->workflow_run_id,
                'namespace' => $row->namespace,
                'context' => $row->getRawOriginal('observability_context'),
            ])->filter(static fn (array $row): bool => $row['context'] !== null)->values()->all(),
            'outcome_events' => $history->filter(static fn ($event): bool => in_array(
                $event->event_type->value,
                ['ActivityCompleted', 'ActivityFailed', 'TimerFired', 'WorkflowCompleted'],
                true,
            ))->map(static fn ($event): array => [
                'id' => $event->id,
                'sequence' => $event->sequence,
                'type' => $event->event_type->value,
                'task_id' => $event->workflow_task_id,
                'payload' => $event->payload,
            ])->values()
                ->all(),
            'public_tasks' => WorkflowTask::query()->get()->toArray(),
        ];
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
    $shutdownStarted = hrtime(true);
    $provider->shutdown();
}
if ($exportEndpoint) {
    $output['export_shutdown_seconds'] = (hrtime(true) - $shutdownStarted) / 1e9;
}
echo json_encode($output, JSON_THROW_ON_ERROR), PHP_EOL;
