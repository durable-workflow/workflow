<?php

declare(strict_types=1);

namespace Tests;

use Dotenv\Dotenv;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseServiceProvider;
use Throwable;
use Workflow\Models\StoredWorkflow;
use Workflow\V2\Models\WorkflowCommand as V2WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent as V2WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance as V2WorkflowInstance;
use Workflow\V2\Models\WorkflowRun as V2WorkflowRun;
use Workflow\V2\Models\WorkflowTask as V2WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate as V2WorkflowUpdate;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\TaskWatchdog;
use Workflow\V2\WorkflowStub as V2WorkflowStub;
use Workflow\WorkflowStub;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTruncation;
    use WithLaravelMigrations;

    public const NUMBER_OF_WORKERS = 2;

    protected const USE_DATABASE_TRUNCATION = true;

    private const V1_WATCHDOG_LOOP_THROTTLE_KEY = 'workflow:watchdog:looping';

    private const WATCHDOG_THROTTLE_TTL_SECONDS = 600;

    private const WORKER_OUTPUT_LIMIT_BYTES = 8_192;

    /**
     * Keep this harness's traits out of Testbench's cache for tests that extend
     * Orchestra directly in the same PHPUnit process.
     *
     * @var array<class-string, class-string>|null
     */
    protected static ?array $cachedTestCaseUses = null;

    /**
     * @var array<int, Process>
     */
    private static array $workers = [];

    /**
     * @var array<int, array{
     *     stdout: string,
     *     stderr: string,
     *     cache_directory: string,
     *     services_cache: string,
     *     packages_cache: string
     * }>
     */
    private static array $workerResources = [];

    public static function setUpBeforeClass(): void
    {
        $currentSuite = TestSuiteSubscriber::getCurrentSuite();

        if ($currentSuite === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif ($currentSuite === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($value) && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }

        self::flushRedis();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopWorkers();
        self::flushRedis();
    }

    protected function setUp(): void
    {
        // DatabaseTruncation needs DBAL for Laravel 10 table discovery. Keep
        // schema mutations on Laravel's native path so installing DBAL does not
        // change rollback behavior exercised by migration tests.
        if (method_exists(SchemaBuilder::class, 'useNativeSchemaOperationsIfPossible')) {
            SchemaBuilder::useNativeSchemaOperationsIfPossible();
        }

        $currentSuite = TestSuiteSubscriber::getCurrentSuite();

        if ($currentSuite === 'feature') {
            Dotenv::createImmutable(__DIR__, '.env.feature')->safeLoad();
        } elseif ($currentSuite === 'unit') {
            Dotenv::createImmutable(__DIR__, '.env.unit')->safeLoad();
        }

        self::stopWorkers();
        self::flushRedis();
        self::resetWorkflowDefinitionRegistrations();

        parent::setUp();

        Queue::swap($this->app->make('queue'));

        Cache::flush();

        self::flushRedis();

        if ($currentSuite === 'feature') {
            // Block BOTH the V2 TaskWatchdog and the V1 Watchdog for the
            // duration of every feature test. The two testbench queue
            // workers spawned after the database reset both wake()s on every
            // Looping event in separate PHP processes.
            //
            // V2: almost every V2 feature test uses Queue::fake() with
            // Carbon::setTestNow() pointing at a past date, so Ready tasks
            // carry created_at days behind the workers' real wall clock
            // and TaskRepairPolicy::dispatchOverdue re-claims them before
            // the test's next runReadyTaskForRun / waitFor lookup.
            //
            // V1: Watchdog::wake queries workflow_logs on every poll, and
            // while the test process resets committed database state that
            // query can race with the reset. Blocking V1's
            // 'workflow:watchdog:looping' here makes
            // Watchdog::wake's Cache::add return false and short-circuit
            // before the SELECT.
            //
            // TTL is 600s (10 minutes) so slow CI runners can take any
            // individual test well beyond either watchdog's internal
            // throttle without expiring mid-test. Each setUp re-arms
            // the keys.
            //
            // Tests that legitimately need either watchdog to fire call
            // their local wakeTaskWatchdog() helper, which forgets the
            // key and calls runPass(respectThrottle: false) directly.
            Cache::put(TaskWatchdog::LOOP_THROTTLE_KEY, true, self::WATCHDOG_THROTTLE_TTL_SECONDS);
            Cache::put(self::V1_WATCHDOG_LOOP_THROTTLE_KEY, true, self::WATCHDOG_THROTTLE_TTL_SECONDS);
            self::primeWatchdogThrottle();
            self::startWorkers();
        }
    }

    protected function tearDown(): void
    {
        try {
            self::stopWorkers();
            self::flushRedis();
        } finally {
            parent::tearDown();
        }
    }

    protected function setUpDatabaseTruncation(): void
    {
        // Testbench's PendingCommand binds a mocked OutputStyle while it runs
        // migrations. Remove that per-command binding so application commands
        // invoked by a test can write to the output buffer supplied by Artisan.
        $this->app->offsetUnset(OutputStyle::class);
    }

    protected function setUpWithLaravelMigrations(): void
    {
        if (! static::USE_DATABASE_TRUNCATION) {
            return;
        }

        $this->app->make('migrator')
            ->path(\Orchestra\Testbench\default_migration_path());
    }

    protected function setUpTheTestEnvironmentTraitToBeIgnored(string $use): bool
    {
        // HandlesDatabases invokes this concern before DatabaseTruncation. Do
        // not invoke it again during generic trait setup after the migrated
        // flag changes, because that would schedule a per-test rollback.
        return $use === WithLaravelMigrations::class
            || parent::setUpTheTestEnvironmentTraitToBeIgnored($use);
    }

    /**
     * @return array<class-string, class-string>
     */
    protected function setUpTraitsWithoutDatabase(): array
    {
        $uses = static::cachedUsesForTestCase();

        unset($uses[DatabaseTruncation::class], $uses[WithLaravelMigrations::class]);

        return $this->setUpTheTestEnvironmentTraits($uses);
    }

    protected function getPackageProviders($app)
    {
        return [TestDatabaseServiceProvider::class, \Workflow\Providers\WorkflowServiceProvider::class];
    }

    protected function assertSameJsonObject(mixed $expected, mixed $actual): void
    {
        $this->assertIsArray($expected);
        $this->assertIsArray($actual);

        $this->assertSame(self::normalizeJsonObject($expected), self::normalizeJsonObject($actual));
    }

    /**
     * @template TWorkflow of WorkflowStub|V2WorkflowStub
     * @param TWorkflow $workflow
     * @param (callable(TWorkflow): bool)|null $condition
     */
    protected function waitForWorkflow(
        WorkflowStub|V2WorkflowStub $workflow,
        ?callable $condition = null,
        string $expectedState = 'a terminal state',
        float $timeoutSeconds = 30.0,
    ): void {
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException('Workflow polling timeout must be greater than zero.');
        }

        $condition ??= static fn (WorkflowStub|V2WorkflowStub $workflow): bool => ! $workflow->running();
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

        do {
            $workerDiagnostics = self::workerDiagnostics();

            foreach ($workerDiagnostics as $worker) {
                if (! $worker['running']) {
                    $this->failWorkflowPolling(
                        $workflow,
                        sprintf(
                            'Queue worker %d exited while waiting for workflow to reach %s.',
                            $worker['worker'],
                            $expectedState,
                        ),
                        $workerDiagnostics,
                    );
                }
            }

            if ($condition($workflow)) {
                return;
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                break;
            }

            usleep((int) min(50_000, max(1, (int) ceil($remainingNanoseconds / 1_000))));
        } while (true);

        $this->failWorkflowPolling(
            $workflow,
            sprintf(
                'Timed out after %.3f seconds waiting for workflow to reach %s.',
                $timeoutSeconds,
                $expectedState,
            ),
            self::workerDiagnostics(),
        );
    }

    protected static function stopWorkers(): void
    {
        foreach (self::$workers as $worker) {
            try {
                $worker->stop(3);
            } catch (Throwable) {
                // Continue stopping peers and removing output files.
            }
        }

        foreach (self::$workerResources as $resources) {
            self::removeWorkerResources($resources);
        }

        self::$workers = [];
        self::$workerResources = [];
    }

    protected static function restartQueueWorkers(): void
    {
        self::stopWorkers();
        self::primeWatchdogThrottle();
        self::startWorkers();
    }

    /**
     * @return array<int, array{
     *     worker: int,
     *     running: bool,
     *     exit_code: int|null,
     *     exit_status: string|null,
     *     stdout: string,
     *     stderr: string,
     *     services_cache: string,
     *     packages_cache: string
     * }>
     */
    protected static function workerDiagnostics(): array
    {
        $diagnostics = [];

        foreach (self::$workers as $index => $worker) {
            $running = $worker->isRunning();
            $resources = self::$workerResources[$index] ?? [
                'stdout' => '/dev/null',
                'stderr' => '/dev/null',
                'services_cache' => '[worker cache unavailable]',
                'packages_cache' => '[worker cache unavailable]',
            ];

            $diagnostics[] = [
                'worker' => $index,
                'running' => $running,
                'exit_code' => $running ? null : $worker->getExitCode(),
                'exit_status' => $running ? null : $worker->getExitCodeText(),
                'stdout' => self::readWorkerOutput($resources['stdout']),
                'stderr' => self::readWorkerOutput($resources['stderr']),
                'services_cache' => $resources['services_cache'],
                'packages_cache' => $resources['packages_cache'],
            ];
        }

        return $diagnostics;
    }

    private static function normalizeJsonObject(array $value): array
    {
        $normalized = array_map(
            static fn (mixed $item): mixed => is_array($item) ? self::normalizeJsonObject($item) : $item,
            $value,
        );

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private static function resetWorkflowDefinitionRegistrations(): void
    {
        $registrations = new \ReflectionProperty(WorkflowDefinition::class, 'workflowTypeRegistrations');
        $registrations->setValue(null, []);
    }

    /**
     * @param array<int, array<string, mixed>> $workers
     */
    private function failWorkflowPolling(
        WorkflowStub|V2WorkflowStub $workflow,
        string $failure,
        array $workers,
    ): void {
        $diagnostics = $this->workflowPollingDiagnostics($workflow);
        $diagnostics['workers'] = $workers;

        // A failed worker may still hold a workflow lock or leave its peer
        // consuming the next test's jobs. Stop both before failure teardown.
        self::stopWorkers();

        $encodedDiagnostics = json_encode(
            $diagnostics,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        $this->fail(sprintf(
            '%s Durable diagnostics: %s',
            $failure,
            $encodedDiagnostics === false ? 'unavailable' : $encodedDiagnostics,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowPollingDiagnostics(WorkflowStub|V2WorkflowStub $workflow): array
    {
        if ($workflow instanceof V2WorkflowStub) {
            return $this->v2WorkflowPollingDiagnostics($workflow);
        }

        $workflowId = (string) $workflow->id();

        try {
            $model = config('workflows.stored_workflow_model', StoredWorkflow::class);

            if (! is_string($model) || ! is_a($model, StoredWorkflow::class, true)) {
                throw new RuntimeException('The configured stored workflow model is invalid.');
            }

            /** @var StoredWorkflow|null $rootWorkflow */
            $rootWorkflow = $model::query()
                ->find($workflow->id());
            $activeWorkflow = $rootWorkflow?->active();

            if ($rootWorkflow === null || $activeWorkflow === null) {
                throw new RuntimeException('The durable workflow row could not be loaded.');
            }

            $connection = $activeWorkflow->effectiveConnection() ?? (string) config('queue.default');
            $queue = $activeWorkflow->effectiveQueue()
                ?? (string) config("queue.connections.{$connection}.queue", 'default');

            $latestLog = $activeWorkflow->logs()
                ->reorder('id', 'desc')
                ->first(['id', 'index', 'class', 'created_at']);
            $latestSignal = $activeWorkflow->signals()
                ->reorder('id', 'desc')
                ->first(['id', 'method', 'created_at']);
            $latestException = $activeWorkflow->exceptions()
                ->reorder('id', 'desc')
                ->first(['id', 'class', 'created_at']);

            try {
                $queuedJobs = Queue::connection($connection)
                    ->size($queue);
            } catch (Throwable $exception) {
                $queuedJobs = 'unavailable: ' . $exception->getMessage();
            }

            return [
                'workflow' => [
                    'id' => $workflowId,
                    'class' => $rootWorkflow->class,
                    'status' => $rootWorkflow->getRawOriginal('status'),
                ],
                'run' => [
                    'id' => (string) $activeWorkflow->getKey(),
                    'status' => $activeWorkflow->getRawOriginal('status'),
                ],
                'task' => [
                    'connection' => $connection,
                    'queue' => $queue,
                    'status' => is_int($queuedJobs)
                        ? ($queuedJobs > 0 ? 'queued' : 'no queued job')
                        : 'unavailable',
                    'queued_jobs' => $queuedJobs,
                ],
                'history' => [
                    'logs' => $activeWorkflow->logs()
                        ->count(),
                    'latest_log' => $latestLog?->only(['id', 'index', 'class', 'created_at']),
                    'signals' => $activeWorkflow->signals()
                        ->count(),
                    'latest_signal' => $latestSignal?->only(['id', 'method', 'created_at']),
                    'exceptions' => $activeWorkflow->exceptions()
                        ->count(),
                    'latest_exception' => $latestException?->only(['id', 'class', 'created_at']),
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'workflow' => [
                    'id' => $workflowId,
                ],
                'diagnostic_error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function v2WorkflowPollingDiagnostics(V2WorkflowStub $workflow): array
    {
        $workflowId = $workflow->id();
        $runId = $workflow->runId();

        try {
            /** @var V2WorkflowInstance|null $instance */
            $instance = V2WorkflowInstance::query()
                ->find($workflowId);
            /** @var V2WorkflowRun|null $run */
            $run = $runId === null
                ? $instance?->currentRun()
                    ->first()
                : V2WorkflowRun::query()
                    ->find($runId);

            if ($instance === null || $run === null) {
                throw new RuntimeException('The durable v2 workflow instance or run could not be loaded.');
            }

            $connection = is_string($run->connection) && $run->connection !== ''
                ? $run->connection
                : (string) config('queue.default');
            $queue = is_string($run->queue) && $run->queue !== ''
                ? $run->queue
                : (string) config("queue.connections.{$connection}.queue", 'default');

            try {
                $queuedJobs = Queue::connection($connection)
                    ->size($queue);
            } catch (Throwable $exception) {
                $queuedJobs = 'unavailable: ' . $exception->getMessage();
            }

            $taskQuery = V2WorkflowTask::query()
                ->where('workflow_run_id', $run->id);
            /** @var V2WorkflowTask|null $latestTask */
            $latestTask = (clone $taskQuery)
                ->latest('created_at')
                ->latest('id')
                ->first();
            $taskStatuses = (clone $taskQuery)
                ->get(['status'])
                ->countBy(static fn (V2WorkflowTask $task): string => (string) $task->getRawOriginal('status'))
                ->all();

            /** @var V2WorkflowHistoryEvent|null $latestHistoryEvent */
            $latestHistoryEvent = V2WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->latest('sequence')
                ->first();
            /** @var V2WorkflowCommand|null $latestCommand */
            $latestCommand = V2WorkflowCommand::query()
                ->where('workflow_run_id', $run->id)
                ->latest('command_sequence')
                ->latest('created_at')
                ->first();
            /** @var V2WorkflowUpdate|null $latestUpdate */
            $latestUpdate = V2WorkflowUpdate::query()
                ->where('workflow_run_id', $run->id)
                ->latest('command_sequence')
                ->latest('created_at')
                ->first();

            return [
                'workflow' => [
                    'id' => $workflowId,
                    'class' => $instance->workflow_class,
                    'type' => $instance->workflow_type,
                    'current_run_id' => $instance->current_run_id,
                ],
                'run' => [
                    'id' => (string) $run->getKey(),
                    'number' => $run->run_number,
                    'status' => $run->getRawOriginal('status'),
                    'last_history_sequence' => $run->last_history_sequence,
                    'last_command_sequence' => $run->last_command_sequence,
                    'last_progress_at' => $run->last_progress_at,
                    'closed_at' => $run->closed_at,
                ],
                'task' => [
                    'connection' => $connection,
                    'queue' => $queue,
                    'status' => $taskStatuses,
                    'queued_jobs' => $queuedJobs,
                    'latest' => $latestTask === null ? null : [
                        'id' => (string) $latestTask->getKey(),
                        'type' => $latestTask->getRawOriginal('task_type'),
                        'status' => $latestTask->getRawOriginal('status'),
                        'attempt_count' => $latestTask->attempt_count,
                        'repair_count' => $latestTask->repair_count,
                        'available_at' => $latestTask->available_at,
                        'leased_at' => $latestTask->leased_at,
                        'lease_owner' => $latestTask->lease_owner,
                        'lease_expires_at' => $latestTask->lease_expires_at,
                        'last_dispatch_attempt_at' => $latestTask->last_dispatch_attempt_at,
                        'last_dispatched_at' => $latestTask->last_dispatched_at,
                        'last_dispatch_error' => $latestTask->last_dispatch_error,
                        'last_claim_failed_at' => $latestTask->last_claim_failed_at,
                        'last_claim_error' => $latestTask->last_claim_error,
                    ],
                ],
                'history' => [
                    'events' => V2WorkflowHistoryEvent::query()
                        ->where('workflow_run_id', $run->id)
                        ->count(),
                    'latest' => $latestHistoryEvent === null ? null : [
                        'id' => (string) $latestHistoryEvent->getKey(),
                        'sequence' => $latestHistoryEvent->sequence,
                        'type' => $latestHistoryEvent->getRawOriginal('event_type'),
                        'task_id' => $latestHistoryEvent->workflow_task_id,
                        'command_id' => $latestHistoryEvent->workflow_command_id,
                        'recorded_at' => $latestHistoryEvent->recorded_at,
                    ],
                ],
                'command' => $latestCommand === null ? null : [
                    'id' => (string) $latestCommand->getKey(),
                    'sequence' => $latestCommand->command_sequence,
                    'type' => $latestCommand->getRawOriginal('command_type'),
                    'status' => $latestCommand->getRawOriginal('status'),
                    'outcome' => $latestCommand->getRawOriginal('outcome'),
                    'target_name' => $latestCommand->target_name,
                    'rejection_reason' => $latestCommand->rejection_reason,
                ],
                'update' => $latestUpdate === null ? null : [
                    'id' => (string) $latestUpdate->getKey(),
                    'command_id' => $latestUpdate->workflow_command_id,
                    'sequence' => $latestUpdate->command_sequence,
                    'name' => $latestUpdate->update_name,
                    'status' => $latestUpdate->getRawOriginal('status'),
                    'outcome' => $latestUpdate->getRawOriginal('outcome'),
                    'rejection_reason' => $latestUpdate->rejection_reason,
                ],
            ];
        } catch (Throwable $exception) {
            return [
                'workflow' => [
                    'id' => $workflowId,
                ],
                'run' => [
                    'id' => $runId,
                ],
                'diagnostic_error' => $exception->getMessage(),
            ];
        }
    }

    private static function startWorkers(): void
    {
        if (self::$workers !== []) {
            return;
        }

        self::$workerResources = [];

        $environment = getenv();
        $workerEnv = array_merge(
            is_array($environment) ? array_filter($environment, 'is_string') : [],
            array_filter($_SERVER, 'is_string'),
            array_filter($_ENV, 'is_string'),
        );

        try {
            for ($i = 0; $i < self::NUMBER_OF_WORKERS; $i++) {
                self::$workerResources[$i] = self::createWorkerResources($i);
                $resources = self::$workerResources[$i];
                $isolatedWorkerEnv = array_merge($workerEnv, [
                    'APP_SERVICES_CACHE' => $resources['services_cache'],
                    'APP_PACKAGES_CACHE' => $resources['packages_cache'],
                ]);
                $command = sprintf(
                    'exec %s %s queue:work --quiet >> %s 2>> %s',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg(__DIR__ . '/../vendor/bin/testbench'),
                    escapeshellarg($resources['stdout']),
                    escapeshellarg($resources['stderr']),
                );
                self::$workers[$i] = new Process(['/bin/sh', '-c', $command], null, $isolatedWorkerEnv);
                self::$workers[$i]->start();
            }
        } catch (Throwable $exception) {
            self::stopWorkers();

            throw $exception;
        }
    }

    /**
     * @return array{
     *     stdout: string,
     *     stderr: string,
     *     cache_directory: string,
     *     services_cache: string,
     *     packages_cache: string
     * }
     */
    private static function createWorkerResources(int $worker): array
    {
        $directory = is_dir('/dev/shm') && is_writable('/dev/shm')
            ? '/dev/shm'
            : sys_get_temp_dir();
        $cacheDirectory = sprintf(
            '%s/workflow-worker-%d-cache-%s',
            rtrim($directory, DIRECTORY_SEPARATOR),
            $worker,
            bin2hex(random_bytes(12)),
        );

        if (! @mkdir($cacheDirectory, 0700)) {
            throw new RuntimeException('Unable to create an isolated queue worker cache directory.');
        }

        $stdout = tempnam($directory, sprintf('workflow-worker-%d-stdout-', $worker));
        $stderr = tempnam($directory, sprintf('workflow-worker-%d-stderr-', $worker));

        if ($stdout === false || $stderr === false) {
            if (is_string($stdout)) {
                @unlink($stdout);
            }

            if (is_string($stderr)) {
                @unlink($stderr);
            }

            (new Filesystem())->deleteDirectory($cacheDirectory);

            throw new RuntimeException('Unable to create queue worker output files.');
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'cache_directory' => $cacheDirectory,
            'services_cache' => $cacheDirectory . DIRECTORY_SEPARATOR . 'services.php',
            'packages_cache' => $cacheDirectory . DIRECTORY_SEPARATOR . 'packages.php',
        ];
    }

    /**
     * @param array{
     *     stdout: string,
     *     stderr: string,
     *     cache_directory: string,
     *     services_cache: string,
     *     packages_cache: string
     * } $resources
     */
    private static function removeWorkerResources(array $resources): void
    {
        @unlink($resources['stdout']);
        @unlink($resources['stderr']);

        (new Filesystem())->deleteDirectory($resources['cache_directory']);
    }

    private static function readWorkerOutput(string $path): string
    {
        if (! is_file($path)) {
            return '[worker output unavailable]';
        }

        clearstatcache(true, $path);
        $size = filesize($path);

        if ($size === false || $size <= self::WORKER_OUTPUT_LIMIT_BYTES) {
            $output = file_get_contents($path, false, null, 0, self::WORKER_OUTPUT_LIMIT_BYTES);

            return $output === false ? '[worker output unavailable]' : $output;
        }

        $marker = '[earlier worker output truncated]' . PHP_EOL;
        $tailLength = self::WORKER_OUTPUT_LIMIT_BYTES - strlen($marker);
        $output = file_get_contents($path, false, null, $size - $tailLength, $tailLength);

        return $output === false
            ? '[worker output unavailable]'
            : $marker . $output;
    }

    private static function flushRedis(): void
    {
        $redis = self::redisConnection();

        if ($redis instanceof \Redis) {
            try {
                foreach (self::redisDatabases() as $database) {
                    $redis->select($database);
                    $redis->flushDB();
                }
            } catch (\Throwable $e) {
                // Ignore if no redis
            } finally {
                $redis->close();
            }
        }
    }

    private static function primeWatchdogThrottle(): void
    {
        $redis = self::redisConnection();

        if ($redis instanceof \Redis) {
            try {
                $redis->select(self::redisCacheDatabase());

                foreach ([TaskWatchdog::LOOP_THROTTLE_KEY, self::V1_WATCHDOG_LOOP_THROTTLE_KEY] as $key) {
                    foreach (self::cacheKeyCandidates($key) as $candidate) {
                        $redis->setex($candidate, self::WATCHDOG_THROTTLE_TTL_SECONDS, serialize(true));
                    }
                }
            } catch (\Throwable $e) {
                // Ignore if no redis
            } finally {
                $redis->close();
            }
        }
    }

    private static function redisConnection(): ?\Redis
    {
        $redisHost = getenv('REDIS_HOST') ?: ($_ENV['REDIS_HOST'] ?? null);
        if (! $redisHost || ! class_exists(\Redis::class)) {
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect($redisHost, self::redisPort());

            return $redis;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return list<int>
     */
    private static function redisDatabases(): array
    {
        return array_values(array_unique([self::redisDatabase(), self::redisCacheDatabase()]));
    }

    /**
     * @return list<string>
     */
    private static function cacheKeyCandidates(string $key): array
    {
        $prefix = getenv('CACHE_PREFIX');

        if ($prefix === false) {
            $appName = getenv('APP_NAME') ?: ($_ENV['APP_NAME'] ?? 'Laravel');
            $prefix = Str::slug($appName, '_') . '_cache_';
        }

        return array_values(array_unique([$key, $prefix === '' ? $key : $prefix . ':' . $key]));
    }

    private static function redisPort(): int
    {
        return (int) (getenv('REDIS_PORT') ?: ($_ENV['REDIS_PORT'] ?? 6379));
    }

    private static function redisDatabase(): int
    {
        return (int) (getenv('REDIS_DB') ?: ($_ENV['REDIS_DB'] ?? 0));
    }

    private static function redisCacheDatabase(): int
    {
        return (int) (getenv('REDIS_CACHE_DB') ?: ($_ENV['REDIS_CACHE_DB'] ?? 1));
    }
}
