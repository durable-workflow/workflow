<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\V2\Activity;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ExecutionTracing;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class ActivityExecutionTracingTest extends TestCase
{
    private InMemoryExporter $exporter;

    private TracerProvider $provider;

    private ScopeInterface $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--force' => true,
        ])->run();
        DB::statement('CREATE TABLE IF NOT EXISTS jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            queue TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL,
            reserved_at INTEGER, available_at INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        DB::statement('CREATE TABLE IF NOT EXISTS failed_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE, connection TEXT NOT NULL, queue TEXT NOT NULL,
            payload TEXT NOT NULL, exception TEXT NOT NULL, failed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->exporter = new InMemoryExporter();
        $this->provider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->configuration = Configurator::create()->withTracerProvider($this->provider)
            ->withPropagator(TraceContextPropagator::getInstance())->activate();
        TracedActivity::$observations = [];
    }

    protected function tearDown(): void
    {
        self::assertSame([], ExecutionTracing::current());
        $this->configuration->detach();
        $this->provider->shutdown();
        parent::tearDown();
    }

    public static function modes(): array
    {
        return [
            'default disabled' => [false, false],
            'enabled legacy run' => [true, false],
            'enabled retry' => [true, true],
        ];
    }

    public function testAdmissionPersistsItsOriginalParentAndDuplicateCannotReplaceIt(): void
    {
        config([
            'workflows.tracing.enabled' => true,
        ]);
        $parent = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => $parent,
        ])->activate();
        try {
            $stub = WorkflowStub::make(TracedWorkflow::class, 'captured-original', '0');
            $stub->start(false, new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 45));
        } finally {
            $scope->detach();
        }
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        self::assertSame($parent, $run->observability_context['carrier']['traceparent']);
        self::assertSame([false], Serializer::unserializeWithCodec($run->payload_codec, $run->arguments));
        $original = $run->getRawOriginal('observability_context');
        $deadlines = [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')];
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-11111111111111111111111111111111-2222222222222222-01',
        ])->activate();
        try {
            self::assertTrue(
                $stub->attemptStart(false, StartOptions::returnExistingActive())->returnedExistingActive()
            );
            $run->refresh();
            self::assertSame($original, $run->getRawOriginal('observability_context'));
            self::assertSame(
                $deadlines,
                [$run->getRawOriginal('execution_deadline_at'), $run->getRawOriginal('run_deadline_at')]
            );
            for ($remaining = 4; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
                $this->workOnce();
                self::assertSame([], ExecutionTracing::current());
            }
        } finally {
            $scope->detach();
        }
        $stub->refresh();
        self::assertSame([9007199254740993, null, '雪'], $stub->output());
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $spans[0]->getContext()->getTraceId());
        self::assertSame('00f067aa0ba902b7', $spans[0]->getParentContext()->getSpanId());
        self::assertStringNotContainsString('observability_context', json_encode(
            WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->pluck('payload')->all(),
            JSON_THROW_ON_ERROR,
        ));
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    public function testExistingRunMigrationLeavesItsDataUntouchedAndAllowsExecutionWithoutAParent(): void
    {
        $scope = TraceContextPropagator::getInstance()->extract([
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->activate();
        try {
            $stub = WorkflowStub::make(TracedWorkflow::class, 'legacy-run', '0');
            $stub->start(false);
        } finally {
            $scope->detach();
        }
        self::assertNull(WorkflowRun::query()->findOrFail($stub->runId())->observability_context);
        $migration = require __DIR__ . '/../../../src/migrations/2026_10_15_000100_add_observability_context_to_workflow_runs.php';
        $migration->down();
        $before = (array) DB::table('workflow_runs')->where('id', $stub->runId())->sole();
        $migration->up();
        $migration->up();
        $after = (array) DB::table('workflow_runs')->where('id', $stub->runId())->sole();
        self::assertArrayHasKey('observability_context', $after);
        self::assertNull($after['observability_context']);
        unset($after['observability_context']);
        self::assertSame($before, $after);
        config([
            'workflows.tracing.enabled' => true,
        ]);
        for ($remaining = 4; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
        }
        self::assertSame([9007199254740993, null, '雪'], $stub->refresh()->output());
        $spans = $this->exporter->getSpans();
        self::assertCount(1, $spans);
        self::assertFalse($spans[0]->getParentContext()->isValid());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
    }

    public static function coldModes(): array
    {
        return [
            'single attempt' => [false],
            'cold retry' => [true],
        ];
    }

    #[DataProvider('coldModes')]
    public function testIndependentQueueProcessesUseThePersistedAdmissionParent(bool $retry): void
    {
        $directory = sys_get_temp_dir() . '/dw-tracing-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $run = static function (string $mode) use ($directory, $retry): array {
            $packageVendor = dirname(__DIR__, 3) . '/vendor';
            $otherVendors = array_filter(
                array_keys(ClassLoader::getRegisteredLoaders()),
                static fn (string $vendor): bool => $vendor !== $packageVendor
            );
            $process = new Process([
                PHP_BINARY, __DIR__ . '/../../Fixtures/V2/tracing-process.php', $directory, $mode, $retry ? 'retry' : 'once',
                ...array_map(
                    static fn (string $vendor): string => $vendor . '/autoload.php',
                    [...$otherVendors, $packageVendor]
                ),
            ]);
            $process->setTimeout(30);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            self::assertJson($process->getOutput(), $mode . ': ' . substr($process->getOutput(), 0, 2048));
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame([], $result['outside_context']);
            self::assertTrue($result['scope_restored']);

            return $result;
        };
        try {
            $admitted = $run('admit');
            $duplicate = $run('duplicate');
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $duplicate[$key]);
            }
            $workflow = $run('work');
            $activity = $run('work');
            $retried = $retry ? $run('work') : null;
            $completion = $run('work');
            $workerPids = [$workflow['pid'], $activity['pid'], $completion['pid']];
            if ($retried !== null) {
                $workerPids[] = $retried['pid'];
                self::assertCount(1, $retried['spans']);
                self::assertSame(
                    $activity['spans'][0]['fields']['activity_id'],
                    $retried['spans'][0]['fields']['activity_id']
                );
                self::assertNotSame(
                    $activity['spans'][0]['fields']['activity_attempt_id'],
                    $retried['spans'][0]['fields']['activity_attempt_id']
                );
                self::assertNotSame($activity['spans'][0]['span_id'], $retried['spans'][0]['span_id']);
                self::assertSame($activity['spans'][0]['trace_id'], $retried['spans'][0]['trace_id']);
                self::assertSame($activity['spans'][0]['parent_id'], $retried['spans'][0]['parent_id']);
            }
            self::assertCount($retry ? 4 : 3, array_unique($workerPids));
            self::assertSame([], $workflow['spans']);
            self::assertSame([], $completion['spans']);
            self::assertCount(1, $activity['spans']);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $activity['spans'][0]['trace_id']);
            self::assertSame('00f067aa0ba902b7', $activity['spans'][0]['parent_id']);
            $finished = $run('inspect');
            self::assertSame('completed', $finished['status']);
            self::assertSame([9007199254740993, null, '雪'], $finished['output']);
            self::assertSame($retry ? 4 : 3, $finished['tasks']);
            self::assertSame(0, $finished['repairs']);
            self::assertSame(0, $finished['jobs']);
            self::assertSame(0, $finished['failed_jobs']);
            foreach (['run_id', 'carrier', 'execution_deadline', 'run_deadline'] as $key) {
                self::assertSame($admitted[$key], $finished[$key]);
            }
            $records = array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($directory . '/activity.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
            );
            $callbacks = array_values(
                array_filter($records, static fn (array $record): bool => $record['message'] === 'callback')
            );
            self::assertCount($retry ? 2 : 1, $callbacks);
            foreach ($callbacks as $callback) {
                self::assertSame($admitted['run_id'], $callback['context']['workflow_run_id']);
                self::assertSame($activity['spans'][0]['trace_id'], $callback['context']['trace_id']);
                self::assertArrayHasKey('activity_attempt_id', $callback['context']);
                self::assertSame('0', $callback['context']['namespace']);
                $constructed = array_values(
                    array_filter(
                        $records,
                        static fn (array $record): bool => $record['message'] === 'constructor' && ($record['context']['activity_attempt_id'] ?? null) === $callback['context']['activity_attempt_id']
                    )
                );
                self::assertCount(1, $constructed);
                self::assertSame($callback['context'], $constructed[0]['context']);
                $dependencies = array_values(
                    array_filter(
                        $records,
                        static fn (array $record): bool => $record['message'] === 'dependency' && ($record['context']['activity_attempt_id'] ?? null) === $callback['context']['activity_attempt_id']
                    )
                );
                self::assertCount(2, $dependencies);
                foreach ($dependencies as $dependency) {
                    self::assertSame($callback['context'], $dependency['context']);
                }
            }
            self::assertCount(
                $retry ? 4 : 3,
                array_filter($records, static fn (array $record): bool => $record['message'] === 'worker outside')
            );
            foreach ($records as $record) {
                if ($record['message'] === 'worker outside') {
                    self::assertArrayNotHasKey('workflow_run_id', $record['context']);
                } elseif (isset($record['context']['activity_attempt_id'])) {
                    self::assertSame($admitted['run_id'], $record['context']['workflow_run_id']);
                    self::assertSame($activity['spans'][0]['trace_id'], $record['context']['trace_id']);
                }
            }
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    #[DataProvider('modes')]
    public function testDatabaseQueueScopesDependenciesAndPreservesOriginalTaskRetries(bool $enabled, bool $retry): void
    {
        config([
            'workflows.tracing.enabled' => $enabled,
        ]);
        $stub = WorkflowStub::make(TracedWorkflow::class, 'tracing-original', '0');
        $stub->start($retry);
        $this->workOnce();
        // Scheduling can inspect an activity's policy. Only actual execution is traced.
        TracedActivity::$observations = [];
        for ($remaining = 6; $remaining > 0 && DB::table('jobs')->count() > 0; --$remaining) {
            $this->workOnce();
            self::assertSame([], ExecutionTracing::current());
        }
        $stub->refresh();
        self::assertSame([9007199254740993, null, '雪'], $stub->output());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
        self::assertSame(0, (int) WorkflowTask::query()->sum('repair_count'));
        self::assertSame($retry ? 2 : 1, ActivityAttempt::query()->count());
        $spans = $this->exporter->getSpans();
        self::assertCount($enabled ? ($retry ? 2 : 1) : 0, $spans);
        $attempts = [];
        foreach (TracedActivity::$observations as [$phase, $fields]) {
            if (! $enabled) {
                self::assertSame([], $fields);
                continue;
            }
            self::assertSame($stub->runId(), $fields['workflow_run_id']);
            self::assertSame('tracing-original', $fields['workflow_instance_id']);
            self::assertSame('0', $fields['namespace']);
            self::assertNotEmpty($fields['trace_id']);
            self::assertNotEmpty($fields['span_id']);
            $attempts[$fields['activity_attempt_id']][$phase] = $fields;
        }
        if ($enabled) {
            self::assertCount($retry ? 2 : 1, $attempts);
            foreach ($attempts as $phases) {
                self::assertSame($phases['dependency'], $phases['constructor']);
                self::assertSame($phases['constructor'], $phases['callback']);
            }
            if ($retry) {
                self::assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
                self::assertSame(RuntimeException::class, $spans[0]->getAttributes()->get('error.type'));
                self::assertNotSame($spans[0]->getContext()->getSpanId(), $spans[1]->getContext()->getSpanId());
                self::assertSame(
                    $spans[0]->getAttributes()->get('activity_id'),
                    $spans[1]->getAttributes()->get('activity_id')
                );
            }
        }
    }

    protected function getPackageProviders($app): array
    {
        return [TestDatabaseServiceProvider::class, WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('workflows.storage.connection', 'testing');
        $app['config']->set('workflows.v1.enabled', false);
        $app['config']->set('workflows.v2.task_dispatch_mode', 'queue');
        $app['config']->set('workflows.v2.compatibility.namespace', '0');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
        $app['config']->set('queue.failed', [
            'driver' => 'database-uuids',
            'database' => 'testing',
            'table' => 'failed_jobs',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'stderr');
    }

    private function workOnce(): void
    {
        self::assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--tries' => 1,
            '--timeout' => 30,
        ]));
    }
}

final class TracedWorkflow extends Workflow
{
    public function handle(bool $retry): array
    {
        return self::activity(TracedActivity::class, $retry);
    }
}

final class TracedDependency
{
    public function __construct()
    {
        TracedActivity::$observations[] = ['dependency', ExecutionTracing::current()];
    }
}

final class TracedActivity extends Activity
{
    public static array $observations = [];

    public int $tries = 2;

    public function __construct(TracedDependency $dependency)
    {
        self::$observations[] = ['constructor', ExecutionTracing::current()];
    }

    public function backoff(): int
    {
        return 0;
    }

    public function handle(bool $retry, TracedDependency $dependency): array
    {
        self::$observations[] = ['callback', ExecutionTracing::current()];
        if ($retry && $this->attemptCount() === 1) {
            throw new RuntimeException('business failure must not become exported text');
        }

        return [9007199254740993, null, '雪'];
    }
}
