<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabaseServiceProvider;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\WorkflowTask;
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
        $this->configuration = Configurator::create()->withTracerProvider($this->provider)->activate();
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
