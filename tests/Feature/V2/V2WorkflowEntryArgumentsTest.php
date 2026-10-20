<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\WorkflowEntryArgumentsMismatchException;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class V2WorkflowEntryArgumentsTest extends TestCase
{
    #[DataProvider('incompatibleEntries')]
    public function testChangedEntryBlocksReplayWithoutClosingOrReplacingTheOriginal(
        string $replacement,
        array $arguments
    ): void {
        [$stub, $run, $task, $prefix, $original] = $this->waitingRun($arguments);
        // Replace only the container's loaded implementation. This models an
        // entry signature changing while the recorded class/type stay fixed.
        $this->app->bind($run->workflow_class, static fn (): Workflow => new $replacement());
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);

        self::assertFalse($run->fresh()->status->isTerminal(), json_encode([
            'status' => $run->fresh()
->status->value,
            'output' => $stub->refresh()
                ->output(),
        ], JSON_THROW_ON_ERROR));
        self::assertNull($run->fresh()->closed_at);
        self::assertSame(
            0,
            $run->historyEvents()
                ->whereIn('event_type', ['WorkflowFailed', 'WorkflowCompleted'])->count()
        );
        self::assertSame($original, $this->originalState($run->fresh()));
        self::assertSame(
            $prefix,
            array_slice($run->historyEvents()->orderBy('sequence')->get()->toArray(), 0, count($prefix))
        );
        self::assertSame(TaskStatus::Failed, $task->fresh()->status);
        self::assertTrue($task->fresh()->payload['replay_blocked'] ?? false);
        self::assertSame('workflow_entry_arguments_mismatch', $task->fresh()->payload['replay_blocked_reason'] ?? null);
        self::assertStringContainsString($run->workflow_type, $task->fresh()->last_error);
        self::assertSame('workflow_replay_blocked', RunDetailView::forRun($run->fresh())['liveness_state']);

        $this->app->forgetInstance($run->workflow_class);
        $this->app->bind($run->workflow_class, $run->workflow_class);
        self::assertTrue($stub->refresh()->attemptRepair()->accepted());
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        self::assertTrue($stub->refresh()->completed());
        self::assertSame($arguments[0], $stub->output()['amount']);
        self::assertSame($original, $this->originalState($run->fresh()));
        self::assertSame(1, $run->historyEvents()->where('event_type', 'WorkflowCompleted')->count());
        self::assertSame(0, $run->historyEvents()->where('event_type', 'WorkflowFailed')->count());
    }

    public static function incompatibleEntries(): iterable
    {
        yield 'required argument added' => [EntryRequiredAdded::class, [7, 'retained']];
        yield 'leading argument removed' => [EntryLeadingRemoved::class, [7, 'retained']];
        yield 'scalar type changed' => [EntryScalarChanged::class, [7]];
        yield 'scalar replaced by defaulted DTO' => [EntryDefaultedDto::class, [7]];
        yield 'scalar replaced by required DTO' => [EntryRequiredDto::class, [7]];
    }

    #[DataProvider('incompatibleEntries')]
    public function testReadOnlyQueryAndColdFiberRefuseIncompatibleEntryWithoutMutatingTheOriginal(
        string $replacement,
        array $arguments,
    ): void {
        [, $run, $task, $prefix, $original] = $this->waitingRun($arguments);
        $taskState = $task->getRawOriginal();
        $this->app->bind($run->workflow_class, static fn (): Workflow => new $replacement());

        try {
            (new QueryStateReplayer())->replayState($run->fresh());
            self::fail('Query replay entered incompatible workflow code.');
        } catch (WorkflowEntryArgumentsMismatchException $exception) {
            self::assertSame($run->workflow_type, $exception->workflowType);
        }

        $runner = WorkflowFiberRunner::forClass(
            $run->workflow_class,
            $run->workflow_instance_id,
            $run->id,
            $arguments,
            $run->payload_codec,
            HistoryExport::forRun($run->fresh())['history_events'],
        );
        try {
            $runner->step();
            self::fail('Cold Fiber entered incompatible workflow code.');
        } catch (WorkflowEntryArgumentsMismatchException $exception) {
            self::assertSame($run->workflow_type, $exception->workflowType);
        }

        self::assertSame($original, $this->originalState($run->fresh()));
        self::assertSame($taskState, $task->fresh()->getRawOriginal());
        self::assertSame($prefix, $run->historyEvents()->orderBy('sequence')->get()->toArray());
        self::assertFalse($run->fresh()->status->isTerminal());
    }

    #[DataProvider('compatibleEntries')]
    public function testCompatibleEntryEvolutionStillCompletes(string $replacement, array $arguments): void
    {
        [$stub, $run, $task] = $this->waitingRun($arguments);
        $this->app->bind($run->workflow_class, static fn (): Workflow => new $replacement());
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        self::assertTrue($stub->refresh()->completed());
        self::assertSame(7, $stub->output()['amount']);
    }

    public static function compatibleEntries(): iterable
    {
        yield 'optional argument added' => [EntryOptionalAdded::class, [7]];
        yield 'trailing argument removed' => [EntryOne::class, [7, 'retained']];
    }

    public function testApplicationBodyTypeErrorRemainsAnApplicationFailure(): void
    {
        [$stub, $run, $task] = $this->waitingRun([7]);
        $this->app->bind($run->workflow_class, static fn (): Workflow => new EntryBodyFailure());
        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
        self::assertTrue($stub->refresh()->failed());
        self::assertFalse($task->fresh()->payload['replay_blocked'] ?? false);
        self::assertSame(1, $run->historyEvents()->where('event_type', 'WorkflowFailed')->count());
    }

    private function waitingRun(array $arguments): array
    {
        self::stopWorkers();
        Queue::fake();
        $class = count($arguments) === 1 ? EntryOne::class : EntryTwo::class;
        $stub = WorkflowStub::make($class, 'entry-' . bin2hex(random_bytes(6)));
        $stub->start(new StartOptions(executionTimeoutSeconds: 60, runTimeoutSeconds: 30), ...$arguments);
        $run = WorkflowRun::query()->findOrFail($stub->runId());
        self::assertNotNull($run->execution_deadline_at);
        self::assertNotNull($run->run_deadline_at);
        self::assertTrue($run->execution_deadline_at->equalTo($run->started_at->copy()->addSeconds(60)));
        self::assertTrue($run->run_deadline_at->equalTo($run->started_at->copy()->addSeconds(30)));
        $first = WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'task_type',
            TaskType::Workflow->value
        )->sole();
        $this->app->call([new RunWorkflowTask($first->id), 'handle']);
        self::assertFalse($run->fresh()->status->isTerminal());
        Carbon::setTestNow(now()->addSeconds(2));
        $timer = WorkflowTask::query()->where('workflow_run_id', $run->id)->where(
            'task_type',
            TaskType::Timer->value
        )->sole();
        $this->app->call([new RunTimerTask($timer->id), 'handle']);
        $task = WorkflowTask::query()->where('workflow_run_id', $run->id)->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)->sole();

        return [
            $stub,
            $run->fresh(),
            $task,
            $run->historyEvents()
                ->orderBy('sequence')
                ->get()
                ->toArray(),
            $this->originalState($run->fresh()),
        ];
    }

    private function originalState(WorkflowRun $run): array
    {
        return array_map(static fn (string $key): mixed => $run->getRawOriginal($key), [
            'id', 'workflow_instance_id', 'workflow_class', 'workflow_type', 'arguments', 'payload_codec',
            'started_at', 'execution_deadline_at', 'run_deadline_at',
        ]);
    }
}

class EntryOne extends Workflow
{
    public function handle(int $amount): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $amount,
        ];
    }
}

class EntryTwo extends Workflow
{
    public function handle(int $amount, string $label): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $amount,
            'label' => $label,
        ];
    }
}

class EntryRequiredAdded extends Workflow
{
    public function handle(int $amount, string $label, int $required): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $amount,
            'label' => $label,
            'required' => $required,
        ];
    }
}

class EntryLeadingRemoved extends Workflow
{
    public function handle(string $label): array
    {
        Workflow::timer('1 second');

        return [
            'label' => $label,
        ];
    }
}

class EntryScalarChanged extends Workflow
{
    public function handle(string $amount): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $amount,
        ];
    }
}

class DefaultEntryData
{
    public function __construct(
        public int $amount = 99
    ) {
    }
}

class RequiredEntryData
{
    public function __construct(
        public int $amount
    ) {
    }
}

class EntryDefaultedDto extends Workflow
{
    public function handle(DefaultEntryData $data): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $data->amount,
        ];
    }
}

class EntryRequiredDto extends Workflow
{
    public function handle(RequiredEntryData $data): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $data->amount,
        ];
    }
}

class EntryOptionalAdded extends Workflow
{
    public function handle(int $amount, string $label = 'compatible'): array
    {
        Workflow::timer('1 second');

        return [
            'amount' => $amount,
            'label' => $label,
        ];
    }
}

class EntryBodyFailure extends Workflow
{
    public function handle(int $amount): array
    {
        Workflow::timer('1 second');
        $value = [];
        strlen($value);

        return [
            'amount' => $amount,
        ];
    }
}
