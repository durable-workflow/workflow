<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use Throwable;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\QueryMethod;
use Workflow\V2\Activity;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Interceptors\ActivityInterceptorContext;
use Workflow\V2\Interceptors\PassthroughWorkerInterceptor;
use Workflow\V2\Interceptors\QueryTaskInterceptorContext;
use Workflow\V2\Interceptors\UpdateValidationInterceptorContext;
use Workflow\V2\Interceptors\UpdateValidationOutcome;
use Workflow\V2\Interceptors\WorkerInterceptor;
use Workflow\V2\Interceptors\WorkerInterceptors;
use Workflow\V2\Interceptors\WorkflowTaskInterceptorContext;
use Workflow\V2\Interceptors\WorkflowTaskInterceptorFailed;
use Workflow\V2\Interceptors\WorkflowTaskOutcome;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\EmbeddedLocalActivityScope;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2WorkerInterceptorTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        InterceptorAudit::reset();
        InterceptedWorkflow::$local = false;
        InterceptedActivity::$fail = false;
    }

    public function testInterceptorsRunInOrderAroundEveryTaskKind(): void
    {
        $this->configure([new AuditingInterceptor('outer'), new AuditingInterceptor('inner')]);

        $stub = $this->start('Ada');
        $this->runWorkflowTask($stub->runId());
        $this->runActivityTask($stub->runId());
        $this->runWorkflowTask($stub->runId());

        self::assertTrue($stub->refresh()->completed());
        self::assertSame('Hello, Ada!', $stub->output());
        self::assertSame([
            'outer:workflow:before', 'inner:workflow:before', 'inner:workflow:after', 'outer:workflow:after',
            'outer:activity:before', 'inner:activity:before', 'activity', 'inner:activity:after', 'outer:activity:after',
            'outer:workflow:before', 'inner:workflow:before', 'inner:workflow:after', 'outer:workflow:after',
        ], InterceptorAudit::$events);

        // Contexts are recorded by outer and inner for each of the two workflow tasks.
        $first = InterceptorAudit::$workflowContexts[0][1];
        $second = InterceptorAudit::$workflowContexts[2][1];
        self::assertFalse($first->replaying);
        self::assertTrue($second->replaying);
        self::assertSame($stub->runId(), $first->runId);
        self::assertSame($stub->id(), $first->workflowId);
        self::assertSame('intercepted-workflow', $first->workflowType);
        self::assertSame(1, $first->workflowTaskAttempt);
        self::assertSame($first->taskId, $first->task['id']);
        self::assertGreaterThan($first->historyLength, $second->historyLength);
        self::assertContains('ActivityScheduled', InterceptorAudit::$outcomes[0]->historyEventTypes);
        self::assertContains('WorkflowCompleted', InterceptorAudit::$outcomes[2]->historyEventTypes);
        self::assertSame('completed', InterceptorAudit::$outcomes[2]->runStatus);

        $activity = InterceptorAudit::$activityContexts[0];
        self::assertSame('intercepted-activity', $activity->activityType);
        self::assertSame(InterceptedActivity::class, $activity->activityClass);
        self::assertSame(['Ada'], array_values($activity->arguments));
        self::assertSame(1, $activity->attemptNumber);
        self::assertSame($stub->runId(), $activity->runId);
        self::assertFalse($activity->local);
        self::assertNotNull($activity->activityAttemptId);

        self::assertSame('greeting:Hello, Ada!', $stub->query('greeting', 'greeting'));
        self::assertSame(
            ['outer:query:before', 'inner:query:before', 'inner:query:after', 'outer:query:after'],
            array_slice(InterceptorAudit::$events, -4)
        );
        $query = InterceptorAudit::$queryContexts[0];
        self::assertSame('greeting', $query->queryName);
        self::assertSame($stub->runId(), $query->runId);
        self::assertNull($query->workerId);
    }

    public function testActivityInterceptorsWrapLocalActivitiesAndMayTransformArguments(): void
    {
        $this->configure([new ArgumentInterceptor(), new AuditingInterceptor('audit')]);
        InterceptedWorkflow::$local = true;

        $stub = $this->start('Grace');
        $this->runWorkflowTask($stub->runId());

        self::assertTrue($stub->refresh()->completed());
        self::assertSame('HELLO, DEAR GRACE!', $stub->output());
        self::assertTrue(InterceptorAudit::$activityContexts[0]->local);
        self::assertSame(['dear Grace'], array_values(InterceptorAudit::$activityContexts[0]->arguments));
    }

    public function testActivityFailurePassesThroughInterceptors(): void
    {
        $this->configure([new AuditingInterceptor('outer'), new AuditingInterceptor('inner')]);
        InterceptedActivity::$fail = true;

        $stub = $this->start('Ada');
        $this->runWorkflowTask($stub->runId());
        $this->runActivityTask($stub->runId());

        self::assertContains('inner:activity:error:card declined', InterceptorAudit::$events);
        self::assertContains('outer:activity:error:card declined', InterceptorAudit::$events);
        $failed = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $stub->runId())
            ->whereIn('event_type', ['ActivityFailed', 'ActivityRetryScheduled'])
            ->first();
        self::assertNotNull($failed);
        self::assertStringContainsString('card declined', json_encode($failed->payload, JSON_THROW_ON_ERROR));
    }

    public function testWorkflowTaskInterceptorCannotChangeRecordedHistory(): void
    {
        $plain = $this->start('Ada');
        $this->runWorkflowTask($plain->runId());
        $expected = $this->historyTypes($plain->runId());

        $this->configure([new TamperingInterceptor()]);
        $intercepted = $this->start('Ada');
        $this->runWorkflowTask($intercepted->runId());

        self::assertSame($expected, $this->historyTypes($intercepted->runId()));
        self::assertSame(1, InterceptorAudit::$workflowRuns);
    }

    public function testWorkflowCoreFailurePassesThroughAnInterceptorThatSwallowsIt(): void
    {
        $this->configure([new SwallowingInterceptor()]);
        $stub = $this->start('Ada');
        $task = $this->readyWorkflowTask($stub->runId());
        $this->app->bind(InterceptedWorkflow::class, static function (): never {
            throw new RuntimeException('workflow class unavailable');
        });

        try {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            self::fail('A swallowed workflow-task failure was reported as success.');
        } catch (Throwable $failure) {
            self::assertStringContainsString('workflow class unavailable', $failure->getMessage());
        }
        self::assertSame(['caught:workflow class unavailable'], InterceptorAudit::$events);
        self::assertSame(TaskStatus::Failed, $task->fresh()->status);
    }

    public function testWorkflowInterceptorFailureRollsBackAndReleasesTheTaskForRetry(): void
    {
        foreach ([
            new ThrowingInterceptor(),
            new NextCallingInterceptor(0),
            new NextCallingInterceptor(2),
        ] as $interceptor) {
            InterceptorAudit::reset();
            InterceptedWorkflow::$runs = 0;
            $this->configure([$interceptor]);
            $stub = $this->start('Ada');
            $task = $this->readyWorkflowTask($stub->runId());
            $before = $this->historyTypes($stub->runId());

            $this->app->call([new RunWorkflowTask($task->id), 'handle']);

            $task->refresh();
            self::assertSame($before, $this->historyTypes($stub->runId()), $interceptor::class);
            self::assertSame(TaskStatus::Ready, $task->status, $interceptor::class);
            self::assertSame(1, $task->repair_count, $interceptor::class);
            self::assertStringContainsString('Workflow task interceptor', (string) $task->last_error);
            self::assertSame(
                $interceptor instanceof NextCallingInterceptor && $interceptor->calls === 0 ? 0 : 1,
                InterceptedWorkflow::$runs,
                $interceptor::class
            );

            // With a well-behaved chain, the retried task records the same history as an uninterrupted run.
            $this->configure([]);
            $task->forceFill([
                'available_at' => null,
            ])->save();
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            self::assertContains('ActivityScheduled', $this->historyTypes($stub->runId()));
        }
    }

    public function testSwallowedViolationAndNestedRollbackStillReleaseTheTask(): void
    {
        foreach ([
            'swallowed skip' => [new SwallowingInterceptor(), new NextCallingInterceptor(0)],
            'swallowed repeat' => [new SwallowingInterceptor(), new NextCallingInterceptor(2)],
            'nested rollback' => [new NestedRollbackInterceptor()],
            'unresolvable entry' => [\stdClass::class],
        ] as $case => $interceptors) {
            InterceptorAudit::reset();
            $this->configure([]);
            $stub = $this->start('Ada');
            $task = $this->readyWorkflowTask($stub->runId());
            $before = $this->historyTypes($stub->runId());
            config()
                ->set('workflows.v2.interceptors', $interceptors);

            $this->app->call([new RunWorkflowTask($task->id), 'handle']);

            $task->refresh();
            self::assertSame($before, $this->historyTypes($stub->runId()), $case);
            self::assertSame(TaskStatus::Ready, $task->status, $case);
            self::assertSame(1, $task->repair_count, $case);
            self::assertStringContainsString('Workflow task interceptor', (string) $task->last_error, $case);
        }
    }

    public function testOnlyTheOriginalClaimReleasesAFailedTask(): void
    {
        $stub = $this->start('Ada');
        $task = $this->readyWorkflowTask($stub->runId());
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => $task->id,
            'lease_expires_at' => now()
                ->addMinute(),
            'attempt_count' => 2,
        ])->save();

        self::assertNull(WorkerInterceptors::releaseFailedTask(
            $task->id,
            WorkflowTaskInterceptorFailed::threw(new RuntimeException('late failure')),
        ));
        $task->refresh();
        self::assertSame(TaskStatus::Leased, $task->status);
        self::assertSame(0, $task->repair_count);

        // A registered claim for an earlier attempt is stale.
        $failure = WorkflowTaskInterceptorFailed::threw(new RuntimeException('late failure'));
        self::assertNull(EmbeddedLocalActivityScope::forClaim(
            $task->id,
            1,
            static fn (): ?WorkflowTask => WorkerInterceptors::releaseFailedTask($task->id, $failure),
        ));
        self::assertSame(TaskStatus::Leased, $task->fresh()->status);

        // Positive control: the claim for the task's current attempt does release it.
        $released = EmbeddedLocalActivityScope::forClaim(
            $task->id,
            2,
            static fn (): ?WorkflowTask => WorkerInterceptors::releaseFailedTask($task->id, $failure),
        );
        self::assertSame($task->id, $released?->id);
        $task->refresh();
        self::assertSame(TaskStatus::Ready, $task->status);
        self::assertSame(1, $task->repair_count);
    }

    public function testEngineReadFailureAfterTheCoreTakesTheTransactionFailurePath(): void
    {
        $this->configure([new SwallowingInterceptor()]);
        $stub = $this->start('Ada');
        $task = $this->readyWorkflowTask($stub->runId());
        $before = $this->historyTypes($stub->runId());
        $injected = false;
        // Fail the integrity read that runs after the core, and only that read.
        DB::listen(static function ($query) use (&$injected): void {
            $integrityRead = '/^select\s+["`]?last_history_sequence["`]?,\s*["`]?status["`]?\s+from/i';
            if (! $injected && preg_match($integrityRead, $query->sql) === 1) {
                $injected = true;

                throw new RuntimeException('injected engine read failure');
            }
        });

        try {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            self::fail('An engine read failure was reported as success.');
        } catch (RuntimeException $failure) {
            self::assertSame('injected engine read failure', $failure->getMessage());
        }

        self::assertTrue($injected);
        $task->refresh();
        self::assertSame(TaskStatus::Failed, $task->status, 'Engine read failures are not interceptor failures.');
        self::assertSame(0, $task->repair_count);
        self::assertSame('injected engine read failure', $task->last_error);
        self::assertSame($before, $this->historyTypes($stub->runId()));
    }

    public function testQueryFailuresPassThroughAndRewrittenArgumentsAreRevalidated(): void
    {
        $this->configure([new AuditingInterceptor('audit')]);
        $stub = $this->start('Ada');
        $this->runWorkflowTask($stub->runId());

        try {
            $stub->query('explode');
            self::fail('A throwing query method succeeded.');
        } catch (RuntimeException $failure) {
            self::assertSame('query method failed', $failure->getMessage());
        }
        self::assertSame(
            ['audit:query:before', 'audit:query:error:query method failed'],
            array_slice(InterceptorAudit::$events, -2)
        );

        $this->configure([new QueryArgumentInterceptor()]);
        try {
            $stub->query('greeting', 'greeting');
            self::fail('A rewritten query argument bypassed validation.');
        } catch (InvalidQueryArgumentsException $failure) {
            self::assertStringContainsString(
                'must be of type string',
                json_encode($failure->validationErrors(), JSON_THROW_ON_ERROR)
            );
        }
    }

    public function testInterceptorClassNamesResolveFromTheContainer(): void
    {
        $sink = new InterceptorSink();
        $this->app->instance(InterceptorSink::class, $sink);
        config()
            ->set('workflows.v2.interceptors', [ContainerInterceptor::class]);

        $stub = $this->start('Ada');
        $this->runWorkflowTask($stub->runId());

        self::assertSame([$stub->runId()], $sink->runs);

        config()
            ->set('workflows.v2.interceptors', [\stdClass::class]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(WorkerInterceptor::class);
        WorkerInterceptors::resolve();
    }

    protected function getPackageProviders($app): array
    {
        return [WorkflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';
        $app['config']->set('database.default', $driver);
        if ($driver === 'sqlite') {
            $app['config']->set('database.connections.sqlite.database', ':memory:');
        }
        $app['config']->set([
            'app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'workflows.v1.enabled' => false,
            'workflows.serializer' => 'avro',
            'workflows.v2.execution_continuation.enabled' => false,
        ]);
    }

    /**
     * @param list<WorkerInterceptor> $interceptors
     */
    private function configure(array $interceptors): void
    {
        config()->set('workflows.v2.interceptors', $interceptors);
    }

    private function start(string $name): WorkflowStub
    {
        $stub = WorkflowStub::make(InterceptedWorkflow::class);
        $stub->start($name);

        return $stub;
    }

    private function readyWorkflowTask(string $runId): WorkflowTask
    {
        return WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
    }

    private function runWorkflowTask(string $runId): void
    {
        $this->app->call([new RunWorkflowTask($this->readyWorkflowTask($runId)->id), 'handle']);
    }

    private function runActivityTask(string $runId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Ready->value)
            ->sole();
        $this->app->call([new RunActivityTask($task->id), 'handle']);
    }

    /**
     * @return list<string>
     */
    private function historyTypes(string $runId): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $runId)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): string => $event->event_type->value)
            ->all();
    }
}

#[\Workflow\V2\Attributes\Type('intercepted-workflow')]
class InterceptedWorkflow extends Workflow
{
    public static bool $local = false;

    public static int $runs = 0;

    private ?string $greeting = null;

    public function handle(string $name): string
    {
        ++self::$runs;
        $this->greeting = self::$local
            ? \Workflow\V2\localActivity(InterceptedActivity::class, $name)
            : \Workflow\V2\activity(InterceptedActivity::class, $name);

        return $this->greeting;
    }

    #[QueryMethod]
    public function explode(): string
    {
        throw new RuntimeException('query method failed');
    }

    #[QueryMethod]
    public function greeting(string $prefix): string
    {
        return $prefix . ':' . $this->greeting;
    }
}

#[\Workflow\V2\Attributes\Type('intercepted-activity')]
class InterceptedActivity extends Activity
{
    public static bool $fail = false;

    public function handle(string $name): string
    {
        InterceptorAudit::$events[] = 'activity';
        if (self::$fail) {
            throw new RuntimeException('card declined');
        }

        return "Hello, {$name}!";
    }
}

final class InterceptorAudit
{
    /**
     * @var list<string>
     */
    public static array $events = [];

    /**
     * @var list<array{0: string, 1: WorkflowTaskInterceptorContext}>
     */
    public static array $workflowContexts = [];

    /**
     * @var list<WorkflowTaskOutcome>
     */
    public static array $outcomes = [];

    /**
     * @var list<ActivityInterceptorContext>
     */
    public static array $activityContexts = [];

    /**
     * @var list<QueryTaskInterceptorContext>
     */
    public static array $queryContexts = [];

    public static int $workflowRuns = 0;

    public static function reset(): void
    {
        self::$events = [];
        self::$workflowContexts = [];
        self::$outcomes = [];
        self::$activityContexts = [];
        self::$queryContexts = [];
        self::$workflowRuns = 0;
    }
}

final class AuditingInterceptor implements WorkerInterceptor
{
    public function __construct(
        private readonly string $name
    ) {
    }

    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        InterceptorAudit::$workflowContexts[] = [$this->name, $context];

        return $this->around('workflow', static function () use ($context, $next): WorkflowTaskOutcome {
            $outcome = $next($context);
            InterceptorAudit::$outcomes[] = $outcome;

            return $outcome;
        });
    }

    public function executeActivity(ActivityInterceptorContext $context, callable $next): mixed
    {
        InterceptorAudit::$activityContexts[] = $context;

        return $this->around('activity', static fn (): mixed => $next($context));
    }

    public function executeQueryTask(QueryTaskInterceptorContext $context, callable $next): mixed
    {
        InterceptorAudit::$queryContexts[] = $context;

        return $this->around('query', static fn (): mixed => $next($context));
    }

    public function executeUpdateValidation(
        UpdateValidationInterceptorContext $context,
        callable $next,
    ): UpdateValidationOutcome {
        return $this->around('update-validation', static fn (): UpdateValidationOutcome => $next($context));
    }

    private function around(string $kind, \Closure $next): mixed
    {
        InterceptorAudit::$events[] = "{$this->name}:{$kind}:before";
        try {
            $result = $next();
        } catch (Throwable $failure) {
            InterceptorAudit::$events[] = "{$this->name}:{$kind}:error:{$failure->getMessage()}";

            throw $failure;
        }
        InterceptorAudit::$events[] = "{$this->name}:{$kind}:after";

        return $result;
    }
}

final class ArgumentInterceptor extends PassthroughWorkerInterceptor
{
    public function executeActivity(ActivityInterceptorContext $context, callable $next): mixed
    {
        $arguments = array_map(static fn (mixed $value): string => 'dear ' . $value, array_values($context->arguments));

        return strtoupper((string) $next($context->withArguments(...$arguments)));
    }
}

final class QueryArgumentInterceptor extends PassthroughWorkerInterceptor
{
    public function executeQueryTask(QueryTaskInterceptorContext $context, callable $next): mixed
    {
        return $next($context->withArguments(123));
    }
}

final class TamperingInterceptor extends PassthroughWorkerInterceptor
{
    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        ++InterceptorAudit::$workflowRuns;
        $swapped = new WorkflowTaskInterceptorContext(
            $context->workerId,
            $context->connection,
            $context->taskQueue,
            $context->namespace,
            [],
            'other-task',
            'other-type',
            'other-workflow',
            'other-run',
            9,
            0,
            false,
        );
        $next($swapped);

        return new WorkflowTaskOutcome(['WorkflowCompleted'], 'completed', null);
    }
}

final class SwallowingInterceptor extends PassthroughWorkerInterceptor
{
    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        try {
            return $next($context);
        } catch (Throwable $failure) {
            InterceptorAudit::$events[] = 'caught:' . $failure->getMessage();

            return new WorkflowTaskOutcome([], 'completed', null);
        }
    }
}

final class NestedRollbackInterceptor extends PassthroughWorkerInterceptor
{
    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        $outcome = null;
        try {
            DB::transaction(static function () use ($context, $next, &$outcome): void {
                $outcome = $next($context);

                throw new RuntimeException('undo the task inside a savepoint');
            });
        } catch (RuntimeException) {
            // Swallow the rollback and pretend the task succeeded.
        }

        return $outcome ?? new WorkflowTaskOutcome([], null, null);
    }
}

final class ThrowingInterceptor extends PassthroughWorkerInterceptor
{
    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        ++InterceptorAudit::$workflowRuns;
        $next($context);

        throw new RuntimeException('audit sink unavailable');
    }
}

final class NextCallingInterceptor extends PassthroughWorkerInterceptor
{
    public function __construct(
        public readonly int $calls
    ) {
    }

    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        $outcome = new WorkflowTaskOutcome([], null, null);
        for ($call = 0; $call < $this->calls; ++$call) {
            ++InterceptorAudit::$workflowRuns;
            $outcome = $next($context);
        }

        return $outcome;
    }
}

final class InterceptorSink
{
    /**
     * @var list<string>
     */
    public array $runs = [];
}

final class ContainerInterceptor extends PassthroughWorkerInterceptor
{
    public function __construct(
        private readonly InterceptorSink $sink
    ) {
    }

    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        $this->sink->runs[] = $context->runId;

        return $next($context);
    }
}
