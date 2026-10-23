<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Activity;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ActivityEntryArgumentsMismatchException;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2ActivityEntryArgumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        ActivityEntryDto::$entered = false;
        ActivityEntryWorkflow::$local = false;
    }

    public function testEntrySafetyChecksDoNotClaimAnUnrelatedRunsReadyTask(): void
    {
        $foreign = WorkflowStub::make(TestGreetingWorkflow::class, 'unrelated-entry-control');
        $foreign->start('Other');
        $foreignTask = WorkflowTask::query()->where('workflow_run_id', $foreign->runId())->sole();
        $foreignBefore = $foreignTask->getAttributes();
        $foreignHistoryCount = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $foreign->runId())
            ->count();

        [$stub, $execution, $task, $arguments] = $this->scheduled(ActivityEntryDto::class, [7]);
        $this->app->call([new RunActivityTask($task->id), 'handle']);

        self::assertNotSame($foreign->runId(), $stub->runId());
        self::assertFalse(ActivityEntryDto::$entered);
        self::assertSame($arguments, $execution->fresh()->getRawOriginal('arguments'));
        self::assertSame(ActivityStatus::Failed, $execution->fresh()->status);
        self::assertSame(
            ActivityEntryArgumentsMismatchException::class,
            $this->history($stub->runId(), 'ActivityFailed')
->payload['exception_class'],
        );
        $this->runWorkflowTask($stub->runId());
        self::assertTrue($stub->refresh()->failed());
        self::assertSame($foreignBefore, $foreignTask->fresh()->getAttributes());
        self::assertSame($foreignHistoryCount, WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $foreign->runId())
            ->count());
    }

    public function testInjectedDtoCannotReplaceTheRecordedBusinessArgument(): void
    {
        [$stub, $execution, $task, $arguments] = $this->scheduled(ActivityEntryDto::class, [7]);
        $this->app->call([new RunActivityTask($task->id), 'handle']);

        self::assertFalse(ActivityEntryDto::$entered, 'The incompatible activity body ran.');
        self::assertSame($arguments, $execution->fresh()->getRawOriginal('arguments'));
        self::assertSame([7], $execution->fresh()->activityArguments());
        self::assertSame(ActivityStatus::Failed, $execution->fresh()->status);
        self::assertSame(1, $execution->fresh()->attempt_count);
        $event = $this->history($stub->runId(), 'ActivityFailed');
        self::assertSame(ActivityEntryArgumentsMismatchException::class, $event->payload['exception_class']);
        self::assertStringContainsString('recorded_argument_would_be_discarded', $event->payload['message']);

        $this->runWorkflowTask($stub->runId());
        self::assertTrue($stub->refresh()->failed());
        self::assertNull($stub->output());
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $stub->runId())->sum('repair_count'));
    }

    public function testAuthoredRetryUsesTheSameOriginalInputAfterCompatibleCodeIsRestored(): void
    {
        [$stub, $execution, $task, $arguments] = $this->scheduled(ActivityEntryRetryDto::class, [7]);
        $originalRunId = $stub->runId();
        $originalExecutionId = $execution->id;
        $this->app->call([new RunActivityTask($task->id), 'handle']);

        self::assertFalse(ActivityEntryDto::$entered);
        self::assertFalse($stub->refresh()->run()->status->isTerminal());
        self::assertSame(ActivityStatus::Pending, $execution->fresh()->status);
        self::assertSame(1, $execution->fresh()->attempt_count);
        $retry = $this->history($originalRunId, 'ActivityRetryScheduled');
        self::assertSame(ActivityEntryArgumentsMismatchException::class, $retry->payload['exception_class']);

        // A new deployment can supply a compatible implementation through the
        // normal Laravel binding without rewriting durable class/input fields.
        $this->app->bind(ActivityEntryRetryDto::class, static fn (): Activity => new ActivityEntryScalar());
        $retryTask = WorkflowTask::query()->where('workflow_run_id', $stub->runId())->where(
            'task_type',
            TaskType::Activity->value
        )
            ->where('status', TaskStatus::Ready->value)->sole();
        $this->app->call([new RunActivityTask($retryTask->id), 'handle']);
        $this->runWorkflowTask($stub->runId());

        self::assertTrue($stub->refresh()->completed());
        self::assertSame(7, $stub->output());
        self::assertSame($originalRunId, $stub->runId());
        self::assertSame($originalExecutionId, $execution->fresh()->id);
        self::assertSame($arguments, $execution->fresh()->getRawOriginal('arguments'));
        self::assertSame(2, $execution->fresh()->attempt_count);
        self::assertSame(
            1,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->where(
                'event_type',
                'ActivityCompleted'
            )->count()
        );
        self::assertSame(
            0,
            WorkflowHistoryEvent::query()->where('workflow_run_id', $stub->runId())->where(
                'event_type',
                'ActivityFailed'
            )->count()
        );
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $stub->runId())->sum('repair_count'));
    }

    #[DataProvider('compatibleEntries')]
    public function testLegitimateDependencyInjectionAndDefaultsKeepRecordedValues(
        string $activity,
        array $arguments,
        mixed $expected,
    ): void {
        [$stub, $execution, $task, $storedArguments] = $this->scheduled($activity, $arguments);
        $this->app->call([new RunActivityTask($task->id), 'handle']);
        $this->runWorkflowTask($stub->runId());

        self::assertTrue($stub->refresh()->completed());
        self::assertSame($expected, $stub->output());
        self::assertSame($storedArguments, $execution->fresh()->getRawOriginal('arguments'));
        self::assertSame(1, $execution->fresh()->attempt_count);
        self::assertSame(0, WorkflowTask::query()->where('workflow_run_id', $stub->runId())->sum('repair_count'));
    }

    public static function compatibleEntries(): iterable
    {
        yield 'service before scalar and default' => [ActivityEntryInjected::class, [7], [7, 'USD']];
        yield 'service and variadic values' => [ActivityEntryVariadic::class, [7, 8], 15];
        yield 'scalar without injection' => [ActivityEntryScalar::class, [7], 7];
    }

    public function testLocalActivityCannotDiscardRecordedInputDuringDependencyInjection(): void
    {
        ActivityEntryWorkflow::$local = true;
        ActivityEntryWorkflow::$activity = ActivityEntryDto::class;
        ActivityEntryWorkflow::$arguments = [7];
        $stub = WorkflowStub::make(ActivityEntryWorkflow::class);
        $stub->start();
        $this->runWorkflowTask($stub->runId());

        self::assertFalse(ActivityEntryDto::$entered, 'The incompatible local activity body ran.');
        $execution = ActivityExecution::query()->where('workflow_run_id', $stub->runId())->sole();
        self::assertSame([7], $execution->activityArguments());
        self::assertSame(ActivityStatus::Failed, $execution->status);
        self::assertSame(1, $execution->attempt_count);
        $event = $this->history($stub->runId(), 'ActivityFailed');
        self::assertSame(ActivityEntryArgumentsMismatchException::class, $event->payload['exception_class']);
        self::assertSame(
            0,
            WorkflowTask::query()->where('workflow_run_id', $stub->runId())->where(
                'task_type',
                TaskType::Activity->value
            )->count()
        );
        self::assertTrue($stub->refresh()->failed());
    }

    #[DataProvider('executionModes')]
    public function testDependencyResolutionFailureHasAnActivityOutcome(bool $local): void
    {
        ActivityEntryWorkflow::$local = $local;
        ActivityEntryWorkflow::$activity = ActivityEntryRequiredDto::class;
        ActivityEntryWorkflow::$arguments = [7];
        $stub = WorkflowStub::make(ActivityEntryWorkflow::class);
        $stub->start();
        $this->runWorkflowTask($stub->runId());
        if (! $local) {
            $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())->where(
                'task_type',
                TaskType::Activity->value
            )->sole();
            $this->app->call([new RunActivityTask($task->id), 'handle']);
            $this->runWorkflowTask($stub->runId());
        }

        self::assertFalse(ActivityEntryDto::$entered);
        $execution = ActivityExecution::query()->where('workflow_run_id', $stub->runId())->sole();
        self::assertSame(ActivityStatus::Failed, $execution->status);
        self::assertSame([7], $execution->activityArguments());
        self::assertSame(1, $execution->attempt_count);
        $event = $this->history($stub->runId(), 'ActivityFailed');
        self::assertSame(
            \Illuminate\Contracts\Container\BindingResolutionException::class,
            $event->payload['exception_class']
        );
        self::assertTrue($stub->refresh()->failed());
        self::assertSame(
            0,
            WorkflowTask::query()->where('workflow_run_id', $stub->runId())->where(
                'status',
                TaskStatus::Leased->value
            )->count()
        );
    }

    public static function executionModes(): iterable
    {
        yield 'queued' => [false];
        yield 'local' => [true];
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

    private function scheduled(string $activity, array $arguments): array
    {
        ActivityEntryWorkflow::$activity = $activity;
        ActivityEntryWorkflow::$arguments = $arguments;
        $stub = WorkflowStub::make(ActivityEntryWorkflow::class);
        $stub->start();
        $this->runWorkflowTask($stub->runId());
        $execution = ActivityExecution::query()->where('workflow_run_id', $stub->runId())->sole();
        $task = WorkflowTask::query()->where('workflow_run_id', $stub->runId())->where(
            'task_type',
            TaskType::Activity->value
        )->sole();

        return [$stub, $execution, $task, $execution->getRawOriginal('arguments')];
    }

    private function runWorkflowTask(string $runId): void
    {
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->sole();
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function history(string $runId, string $type): WorkflowHistoryEvent
    {
        return WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->where('event_type', $type)->sole();
    }
}

class ActivityEntryWorkflow extends Workflow
{
    public static bool $local = false;

    public static string $activity;

    public static array $arguments;

    public function handle(): mixed
    {
        if (self::$local) {
            return \Workflow\V2\localActivity(self::$activity, ...self::$arguments);
        }

        return \Workflow\V2\activity(self::$activity, ...self::$arguments);
    }
}

class ActivityEntryData
{
    public function __construct(
        public int $amount = 0
    ) {
    }
}

class ActivityEntryDto extends Activity
{
    public static bool $entered = false;

    public function handle(ActivityEntryData $data): int
    {
        self::$entered = true;

        return $data->amount;
    }
}

class ActivityEntryRequiredData
{
    public function __construct(
        public int $amount
    ) {
    }
}

class ActivityEntryRequiredDto extends Activity
{
    public function handle(ActivityEntryRequiredData $data): int
    {
        ActivityEntryDto::$entered = true;

        return $data->amount;
    }
}

class ActivityEntryRetryDto extends ActivityEntryDto
{
    public int $tries = 2;

    public function backoff(): int
    {
        return 0;
    }
}

class ActivityEntryScalar extends Activity
{
    public function handle(int $amount): int
    {
        return $amount;
    }
}

class ActivityEntryService
{
}

class ActivityEntryInjected extends Activity
{
    public function handle(ActivityEntryService $service, int $amount, string $currency = 'USD'): array
    {
        return [$amount, $currency];
    }
}

class ActivityEntryVariadic extends Activity
{
    public function handle(ActivityEntryService $service, int ...$amounts): int
    {
        return array_sum($amounts);
    }
}
