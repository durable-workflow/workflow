<?php

declare(strict_types=1);

namespace Workflow\V2;

use BadMethodCallException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use LogicException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\ActivityCancellation;
use Workflow\V2\Support\ChildRunHistory;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\CurrentRunResolver;
use Workflow\V2\Support\LifecycleEventDispatcher;
use Workflow\V2\Support\ParallelChildGroup;
use Workflow\V2\Support\ParentClosePolicyEnforcer;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\RoutingResolver;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\RunSummaryProjector;
use Workflow\V2\Support\SelectedRunLocator;
use Workflow\V2\Support\SignalWaits;
use Workflow\V2\Support\StructuralLimits;
use Workflow\V2\Support\TaskDispatcher;
use Workflow\V2\Support\TaskRepair;
use Workflow\V2\Support\TimerCancellation;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\UpdateCommandGate;
use Workflow\V2\Support\UpdateWaitPolicy;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowExecutionGate;
use Workflow\V2\Support\WorkflowInstanceId;
use Workflow\V2\Support\WorkflowTaskPayload;
use Workflow\WorkflowMetadata;

final class WorkflowStub
{
    public const DEFAULT_VERSION = -1;

    private const DISPATCHED_LIST = 'workflow.v2.dispatched';

    private const SIGNALS_SENT_LIST = 'workflow.v2.signals_sent';

    private const UPDATES_SENT_LIST = 'workflow.v2.updates_sent';

    private const MOCKS_LIST = 'workflow.v2.mocks';

    private ?WorkflowRun $run = null;

    private ?string $selectedRunId = null;

    private ?CommandContext $commandContext = null;

    private ?int $updateWaitTimeoutSeconds = null;

    private function __construct(
        private WorkflowInstance $instance,
        ?WorkflowRun $selectedRun = null,
        private readonly bool $runTargeted = false,
    ) {
        $this->run = $selectedRun ?? CurrentRunResolver::forInstance($this->instance);
        $this->selectedRunId = $this->run?->id;
    }

    public function __call(string $method, array $arguments): mixed
    {
        $this->refresh();

        $workflowClass = $this->run?->workflow_class ?? $this->instance->workflow_class;
        $workflowType = $this->run?->workflow_type ?? $this->instance->workflow_type;
        $resolvedClass = TypeRegistry::resolveWorkflowClass($workflowClass, $workflowType);

        if (WorkflowDefinition::hasQueryMethod($resolvedClass, $method)) {
            return $this->query($method, ...$arguments);
        }

        if (WorkflowDefinition::hasUpdateMethod($resolvedClass, $method)) {
            $target = WorkflowDefinition::resolveUpdateTarget($resolvedClass, $method);

            return $this->update($target['name'] ?? $method, ...$arguments);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method [%s::%s].', static::class, $method));
    }

    public static function faked(): bool
    {
        return App::bound(self::MOCKS_LIST) || App::bound(self::DISPATCHED_LIST);
    }

    public static function fake(): void
    {
        App::instance(self::MOCKS_LIST, []);
        App::instance(self::DISPATCHED_LIST, []);
        App::instance(self::SIGNALS_SENT_LIST, []);
        App::instance(self::UPDATES_SENT_LIST, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function mocks(): array
    {
        if (! App::bound(self::MOCKS_LIST)) {
            return [];
        }

        /** @var array<string, mixed> $mocks */
        $mocks = App::make(self::MOCKS_LIST);

        return $mocks;
    }

    public static function mock(string $activity, mixed $result): void
    {
        if (class_exists($activity) && is_subclass_of($activity, Workflow::class)) {
            throw new LogicException(sprintf(
                'WorkflowStub::mock() does not support mocking workflow classes. [%s] is a Workflow, not an Activity. Child workflows execute as real nested V2 runs under fake mode — test them through their observable output instead.',
                $activity,
            ));
        }

        if (! self::faked()) {
            self::fake();
        }

        $mocks = self::mocks();
        $mocks[$activity] = $result;

        App::instance(self::MOCKS_LIST, $mocks);
    }

    public static function hasMock(string $activity): bool
    {
        return array_key_exists($activity, self::mocks());
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array{mocked: bool, result: mixed, throwable: Throwable|null}
     */
    public static function mockedResult(string $activity, mixed $context, array $arguments): array
    {
        $mocks = self::mocks();

        if (! array_key_exists($activity, $mocks)) {
            return [
                'mocked' => false,
                'result' => null,
                'throwable' => null,
            ];
        }

        try {
            $result = is_callable($mocks[$activity])
                ? $mocks[$activity]($context, ...$arguments)
                : $mocks[$activity];

            return [
                'mocked' => true,
                'result' => $result,
                'throwable' => null,
            ];
        } catch (Throwable $throwable) {
            return [
                'mocked' => true,
                'result' => null,
                'throwable' => $throwable,
            ];
        }
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public static function recordDispatched(string $activity, array $arguments): void
    {
        if (! self::faked()) {
            return;
        }

        /** @var array<string, list<array<int, mixed>>> $dispatched */
        $dispatched = App::bound(self::DISPATCHED_LIST)
            ? App::make(self::DISPATCHED_LIST)
            : [];

        $dispatched[$activity] ??= [];
        $dispatched[$activity][] = $arguments;

        App::instance(self::DISPATCHED_LIST, $dispatched);
    }

    /**
     * @param callable(...mixed): bool|null $callback
     * @return Collection<int, array<int, mixed>>
     */
    public static function dispatched(string $activity, ?callable $callback = null): Collection
    {
        /** @var array<string, list<array<int, mixed>>> $dispatched */
        $dispatched = App::bound(self::DISPATCHED_LIST)
            ? App::make(self::DISPATCHED_LIST)
            : [];

        if (! array_key_exists($activity, $dispatched)) {
            return collect();
        }

        $callback ??= static fn (): bool => true;

        return collect($dispatched[$activity])
            ->filter(static fn (array $arguments): bool => $callback(...$arguments))
            ->values();
    }

    public static function assertDispatched(string $activity, callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            self::assertDispatchedTimes($activity, $callback);

            return;
        }

        \PHPUnit\Framework\Assert::assertTrue(
            self::dispatched($activity, $callback)->isNotEmpty(),
            sprintf('The expected [%s] activity was not dispatched.', $activity),
        );
    }

    public static function assertDispatchedTimes(string $activity, int $times = 1): void
    {
        $count = self::dispatched($activity)->count();

        \PHPUnit\Framework\Assert::assertSame(
            $times,
            $count,
            sprintf(
                'The expected [%s] activity was dispatched %d times instead of %d times.',
                $activity,
                $count,
                $times,
            ),
        );
    }

    public static function assertNotDispatched(string $activity, ?callable $callback = null): void
    {
        \PHPUnit\Framework\Assert::assertTrue(
            self::dispatched($activity, $callback)->isEmpty(),
            sprintf('The unexpected [%s] activity was dispatched.', $activity),
        );
    }

    public static function assertNothingDispatched(): void
    {
        /** @var array<string, list<array<int, mixed>>> $dispatched */
        $dispatched = App::bound(self::DISPATCHED_LIST)
            ? App::make(self::DISPATCHED_LIST)
            : [];

        \PHPUnit\Framework\Assert::assertSame(
            0,
            array_sum(array_map(static fn (array $entries): int => count($entries), $dispatched)),
            'An unexpected activity was dispatched.',
        );
    }

    /**
     * Record that a signal was sent in fake mode.
     *
     * @param array<int, mixed> $arguments
     */
    public static function recordSignalSent(string $instanceId, string $signal, array $arguments): void
    {
        if (! self::faked()) {
            return;
        }

        /** @var array<string, list<array{instance_id: string, signal: string, arguments: array<int, mixed>}>> $sent */
        $sent = App::bound(self::SIGNALS_SENT_LIST)
            ? App::make(self::SIGNALS_SENT_LIST)
            : [];

        $sent[$signal] ??= [];
        $sent[$signal][] = [
            'instance_id' => $instanceId,
            'signal' => $signal,
            'arguments' => $arguments,
        ];

        App::instance(self::SIGNALS_SENT_LIST, $sent);
    }

    /**
     * @param callable(string $instanceId, mixed ...$arguments): bool|null $callback
     * @return Collection<int, array{instance_id: string, signal: string, arguments: array<int, mixed>}>
     */
    public static function signalsSent(string $signal, ?callable $callback = null): Collection
    {
        /** @var array<string, list<array{instance_id: string, signal: string, arguments: array<int, mixed>}>> $sent */
        $sent = App::bound(self::SIGNALS_SENT_LIST)
            ? App::make(self::SIGNALS_SENT_LIST)
            : [];

        if (! array_key_exists($signal, $sent)) {
            return collect();
        }

        $callback ??= static fn (): bool => true;

        return collect($sent[$signal])
            ->filter(static fn (array $entry): bool => $callback($entry['instance_id'], ...$entry['arguments']))
            ->values();
    }

    public static function assertSignalSent(string $signal, callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            self::assertSignalSentTimes($signal, $callback);

            return;
        }

        \PHPUnit\Framework\Assert::assertTrue(
            self::signalsSent($signal, $callback)->isNotEmpty(),
            sprintf('The expected [%s] signal was not sent.', $signal),
        );
    }

    public static function assertSignalSentTimes(string $signal, int $times = 1): void
    {
        $count = self::signalsSent($signal)->count();

        \PHPUnit\Framework\Assert::assertSame(
            $times,
            $count,
            sprintf('The expected [%s] signal was sent %d times instead of %d times.', $signal, $count, $times),
        );
    }

    public static function assertSignalNotSent(string $signal, ?callable $callback = null): void
    {
        \PHPUnit\Framework\Assert::assertTrue(
            self::signalsSent($signal, $callback)->isEmpty(),
            sprintf('The unexpected [%s] signal was sent.', $signal),
        );
    }

    /**
     * Record that an update was sent in fake mode.
     *
     * @param array<int, mixed> $arguments
     */
    public static function recordUpdateSent(string $instanceId, string $update, array $arguments): void
    {
        if (! self::faked()) {
            return;
        }

        /** @var array<string, list<array{instance_id: string, update: string, arguments: array<int, mixed>}>> $sent */
        $sent = App::bound(self::UPDATES_SENT_LIST)
            ? App::make(self::UPDATES_SENT_LIST)
            : [];

        $sent[$update] ??= [];
        $sent[$update][] = [
            'instance_id' => $instanceId,
            'update' => $update,
            'arguments' => $arguments,
        ];

        App::instance(self::UPDATES_SENT_LIST, $sent);
    }

    /**
     * @param callable(string $instanceId, mixed ...$arguments): bool|null $callback
     * @return Collection<int, array{instance_id: string, update: string, arguments: array<int, mixed>}>
     */
    public static function updatesSent(string $update, ?callable $callback = null): Collection
    {
        /** @var array<string, list<array{instance_id: string, update: string, arguments: array<int, mixed>}>> $sent */
        $sent = App::bound(self::UPDATES_SENT_LIST)
            ? App::make(self::UPDATES_SENT_LIST)
            : [];

        if (! array_key_exists($update, $sent)) {
            return collect();
        }

        $callback ??= static fn (): bool => true;

        return collect($sent[$update])
            ->filter(static fn (array $entry): bool => $callback($entry['instance_id'], ...$entry['arguments']))
            ->values();
    }

    public static function assertUpdateSent(string $update, callable|int|null $callback = null): void
    {
        if (is_int($callback)) {
            self::assertUpdateSentTimes($update, $callback);

            return;
        }

        \PHPUnit\Framework\Assert::assertTrue(
            self::updatesSent($update, $callback)->isNotEmpty(),
            sprintf('The expected [%s] update was not sent.', $update),
        );
    }

    public static function assertUpdateSentTimes(string $update, int $times = 1): void
    {
        $count = self::updatesSent($update)->count();

        \PHPUnit\Framework\Assert::assertSame(
            $times,
            $count,
            sprintf('The expected [%s] update was sent %d times instead of %d times.', $update, $count, $times),
        );
    }

    public static function assertUpdateNotSent(string $update, ?callable $callback = null): void
    {
        \PHPUnit\Framework\Assert::assertTrue(
            self::updatesSent($update, $callback)->isEmpty(),
            sprintf('The unexpected [%s] update was sent.', $update),
        );
    }

    public static function runReadyTasks(int $limit = 100): int
    {
        if (! self::faked()) {
            throw new LogicException('WorkflowStub::runReadyTasks() requires WorkflowStub::fake().');
        }

        if ($limit < 1) {
            throw new LogicException('WorkflowStub::runReadyTasks() requires a positive task limit.');
        }

        $processed = 0;

        while ($processed < $limit) {
            /** @var WorkflowTask|null $task */
            $task = self::taskQuery()
                ->where('status', TaskStatus::Ready->value)
                ->where(static function ($query): void {
                    $query->whereNull('available_at')
                        ->orWhere('available_at', '<=', now());
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if (! $task instanceof WorkflowTask) {
                break;
            }

            TaskDispatcher::dispatch($task);
            $task->refresh();

            if (
                $task->status === TaskStatus::Ready
                && ($task->available_at === null || ! $task->available_at->isFuture())
            ) {
                throw new LogicException(sprintf(
                    'WorkflowStub::runReadyTasks() could not drain ready task [%s].',
                    $task->id,
                ));
            }

            $processed++;
        }

        return $processed;
    }

    /**
     * @param class-string<Workflow> $workflow
     */
    public static function make(string $workflow, ?string $instanceId = null): self
    {
        $workflowType = TypeRegistry::for($workflow);

        if ($instanceId === null) {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()->create([
                'workflow_class' => $workflow,
                'workflow_type' => $workflowType,
                'reserved_at' => now(),
                'run_count' => 0,
            ]);

            return new self($instance->fresh());
        }

        WorkflowInstanceId::assertValid($instanceId);

        $instance = self::reserveCallerSuppliedInstance(
            workflow: $workflow,
            workflowType: $workflowType,
            instanceId: $instanceId,
        );

        return new self($instance->fresh());
    }

    public static function load(string $instanceId, ?string $namespace = null): self
    {
        $query = self::instanceQuery();

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        /** @var WorkflowInstance $instance */
        $instance = $query->findOrFail($instanceId);

        return new self($instance);
    }

    public static function loadSelection(string $instanceId, ?string $runId = null, ?string $namespace = null): self
    {
        $query = self::instanceQuery();

        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        /** @var WorkflowInstance $instance */
        $instance = $query->findOrFail($instanceId);

        if ($runId === null) {
            return new self($instance);
        }

        $run = SelectedRunLocator::forInstanceOrFail($instance, $runId);

        return new self($instance, $run, true);
    }

    public static function loadRun(string $runId, ?string $namespace = null): self
    {
        $run = SelectedRunLocator::forRunIdOrFail($runId, ['instance'], $namespace);

        $instance = $run->instance;

        return new self($instance, $run, true);
    }

    public function id(): string
    {
        return $this->instance->id;
    }

    public function runId(): ?string
    {
        return $this->run?->id;
    }

    public function run(): ?WorkflowRun
    {
        return $this->refresh()
->run;
    }

    public function payloadCodec(): string
    {
        return $this->run?->payload_codec ?? CodecRegistry::defaultCodec();
    }

    public function currentRunId(): ?string
    {
        return $this->currentRunForInstance($this->instance)?->id;
    }

    public function currentRunIsSelected(): bool
    {
        return $this->selectedRunId !== null && $this->selectedRunId === $this->currentRunId();
    }

    public function businessKey(): ?string
    {
        return $this->run?->business_key ?? $this->instance->business_key;
    }

    /**
     * @return array<string, string>
     */
    public function visibilityLabels(): array
    {
        $labels = $this->run?->visibility_labels ?? $this->instance->visibility_labels ?? [];

        return is_array($labels) ? $labels : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function memo(): array
    {
        $memo = $this->run?->memo ?? $this->instance->memo ?? [];

        return is_array($memo) ? $memo : [];
    }

    /**
     * @return array<string, string>
     */
    public function searchAttributes(): array
    {
        $attributes = $this->run?->search_attributes ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    public function status(): string
    {
        return $this->run?->status->value ?? 'reserved';
    }

    public function running(): bool
    {
        return $this->run !== null && in_array($this->run->status, [
            RunStatus::Pending,
            RunStatus::Running,
            RunStatus::Waiting,
        ], true);
    }

    public function completed(): bool
    {
        return $this->run?->status === RunStatus::Completed;
    }

    public function failed(): bool
    {
        return $this->run?->status === RunStatus::Failed;
    }

    public function cancelled(): bool
    {
        return $this->run?->status === RunStatus::Cancelled;
    }

    public function terminated(): bool
    {
        return $this->run?->status === RunStatus::Terminated;
    }

    public function output(): mixed
    {
        return $this->run?->workflowOutput();
    }

    public function query(string $method, ...$arguments): mixed
    {
        return $this->queryWithArguments($method, $arguments);
    }

    /**
     * @return array{name: string, method: string}|null
     */
    public function resolveQueryTarget(string $target): ?array
    {
        $this->refresh();

        if (! $this->run instanceof WorkflowRun) {
            return null;
        }

        return $this->resolveQueryTargetForRun($this->run, $target);
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function queryWithArguments(string $method, array $arguments): mixed
    {
        $this->refresh();

        if ($this->run === null) {
            throw new LogicException(sprintf('Workflow instance [%s] has not started yet.', $this->instance->id));
        }

        $resolvedTarget = $this->resolveQueryTargetForRun($this->run, $method);

        if ($resolvedTarget === null) {
            throw new LogicException(sprintf(
                'Workflow query [%s] is not declared on run [%s].',
                $method,
                $this->run->id,
            ));
        }

        $validatedArguments = $this->validatedQueryArgumentsForRun($this->run, $resolvedTarget['name'], $arguments);

        if ($validatedArguments['validation_errors'] !== []) {
            throw new InvalidQueryArgumentsException($resolvedTarget['name'], $validatedArguments['validation_errors']);
        }

        $queryBlockedReason = WorkflowExecutionGate::blockedReason($this->run);

        if ($queryBlockedReason !== null) {
            throw new WorkflowExecutionUnavailableException(
                'query',
                $resolvedTarget['name'],
                $queryBlockedReason,
                WorkflowExecutionGate::blockedMessage($this->run, 'query', $resolvedTarget['name'])
                    ?? sprintf(
                        'Workflow query [%s] cannot execute for run [%s].',
                        $resolvedTarget['name'],
                        $this->run->id,
                    ),
            );
        }

        return (new QueryStateReplayer())->query(
            $this->run,
            $resolvedTarget['method'],
            $validatedArguments['arguments']
        );
    }

    public function summary(): ?WorkflowRunSummary
    {
        if ($this->run === null) {
            return null;
        }

        return WorkflowRunSummary::query()->find($this->run->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function historyExport(HistoryExportRedactor|callable|null $redactor = null): array
    {
        $this->refresh();

        if ($this->run === null) {
            throw new LogicException(sprintf('Workflow instance [%s] has not started yet.', $this->instance->id));
        }

        /** @var OperatorObservabilityRepository $repository */
        $repository = app(OperatorObservabilityRepository::class);

        return $repository->runHistoryExport($this->run, redactor: $redactor);
    }

    public function refresh(): self
    {
        $this->instance = self::instanceQuery()
            ->findOrFail($this->instance->id);

        if ($this->runTargeted && $this->selectedRunId !== null) {
            /** @var WorkflowRun $selectedRun */
            $selectedRun = self::runQuery()->findOrFail($this->selectedRunId);
            $this->run = $selectedRun;
        } else {
            $this->run = $this->currentRunForInstance($this->instance);
            $this->selectedRunId = $this->run?->id;
        }

        return $this;
    }

    public function withCommandContext(CommandContext $commandContext): self
    {
        $clone = clone $this;
        $clone->commandContext = $commandContext;

        return $clone;
    }

    public function withUpdateWaitTimeout(?int $seconds): self
    {
        if ($seconds !== null && $seconds < 1) {
            throw new LogicException('Workflow v2 update wait timeout must be a positive integer.');
        }

        $clone = clone $this;
        $clone->updateWaitTimeoutSeconds = $seconds;

        return $clone;
    }

    public function start(...$arguments): StartResult
    {
        $result = $this->attemptStart(...$arguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf('Workflow instance [%s] has already started.', $this->instance->id));
        }

        return $result;
    }

    public function attemptStart(...$arguments): StartResult
    {
        /** @var WorkflowCommand|null $command */
        $command = null;

        [$arguments, $startOptions] = $this->extractStartArguments($arguments);
        $metadata = WorkflowMetadata::fromStartArguments($arguments);
        $task = null;

        DB::transaction(function () use ($metadata, $startOptions, &$task, &$command): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $run = $this->currentRunForInstance($instance, true);

            if ($run instanceof WorkflowRun) {
                $canReturnExisting = $startOptions->duplicateStartPolicy === DuplicateStartPolicy::ReturnExistingActive
                    && in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);

                /** @var WorkflowCommand $command */
                $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => $canReturnExisting
                        ? CommandStatus::Accepted->value
                        : CommandStatus::Rejected->value,
                    'outcome' => $canReturnExisting
                        ? CommandOutcome::ReturnedExistingActive->value
                        : CommandOutcome::RejectedDuplicate->value,
                    'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
                    'payload' => Serializer::serializeWithCodec(
                        $run->payload_codec ?? CodecRegistry::defaultCodec(),
                        $metadata->arguments
                    ),
                    'rejection_reason' => $canReturnExisting
                        ? null
                        : 'instance_already_started',
                    'accepted_at' => $canReturnExisting
                        ? now()
                        : null,
                    'applied_at' => $canReturnExisting
                        ? now()
                        : null,
                    'rejected_at' => $canReturnExisting
                        ? null
                        : now(),
                ]));

                WorkflowHistoryEvent::record($run, $canReturnExisting
                    ? HistoryEventType::StartAccepted
                    : HistoryEventType::StartRejected, [
                        'workflow_command_id' => $command->id,
                        'workflow_instance_id' => $instance->id,
                        'workflow_run_id' => $run->id,
                        'workflow_class' => $run->workflow_class,
                        'workflow_type' => $run->workflow_type,
                        'business_key' => $run->business_key,
                        'visibility_labels' => $run->visibility_labels,
                        'memo' => $run->memo,
                        'search_attributes' => $run->search_attributes,
                        'outcome' => $command->outcome?->value,
                        'rejection_reason' => $command->rejection_reason,
                    ], null, $command);

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return;
            }

            $workflowClass = TypeRegistry::resolveWorkflowClass($instance->workflow_class, $instance->workflow_type);
            $commandContract = RunCommandContract::snapshot($workflowClass);
            $businessKey = $startOptions->businessKey ?? $instance->business_key;
            $visibilityLabels = $startOptions->labels !== []
                ? $startOptions->labels
                : (is_array($instance->visibility_labels) ? $instance->visibility_labels : null);
            $memo = $startOptions->memo !== []
                ? $startOptions->memo
                : (is_array($instance->memo) ? $instance->memo : null);
            $searchAttributes = $startOptions->searchAttributes !== []
                ? $startOptions->searchAttributes
                : null;
            $executionTimeoutSeconds = $startOptions->executionTimeoutSeconds;
            $runTimeoutSeconds = $startOptions->runTimeoutSeconds;

            if ($instance->workflow_class !== $workflowClass) {
                $instance->forceFill([
                    'workflow_class' => $workflowClass,
                ])->save();
            }

            $startedAt = now();
            $executionDeadlineAt = $executionTimeoutSeconds !== null
                ? $startedAt->copy()
                    ->addSeconds($executionTimeoutSeconds)
                : null;
            $runDeadlineAt = $runTimeoutSeconds !== null
                ? $startedAt->copy()
                    ->addSeconds($runTimeoutSeconds)
                : null;

            /** @var WorkflowRun $run */
            $run = self::runQuery()->create([
                'workflow_instance_id' => $instance->id,
                'run_number' => $instance->run_count + 1,
                'workflow_class' => $workflowClass,
                'workflow_type' => $instance->workflow_type,
                'business_key' => $businessKey,
                'visibility_labels' => $visibilityLabels,
                'memo' => $memo,
                'search_attributes' => $searchAttributes,
                'run_timeout_seconds' => $runTimeoutSeconds,
                'execution_deadline_at' => $executionDeadlineAt,
                'run_deadline_at' => $runDeadlineAt,
                'status' => RunStatus::Pending->value,
                'compatibility' => WorkerCompatibility::current(),
                'payload_codec' => CodecRegistry::defaultCodec(),
                'arguments' => \Workflow\Serializers\Serializer::serializeWithCodec(
                    CodecRegistry::defaultCodec(),
                    $metadata->arguments
                ),
                'connection' => RoutingResolver::workflowConnection($workflowClass, $metadata),
                'queue' => RoutingResolver::workflowQueue($workflowClass, $metadata),
                'started_at' => $startedAt,
                'last_progress_at' => $startedAt,
                'last_history_sequence' => 0,
            ]);

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Start->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::StartedNew->value,
                'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
                'payload' => Serializer::serializeWithCodec(
                    $run->payload_codec ?? CodecRegistry::defaultCodec(),
                    $metadata->arguments
                ),
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            $instance->forceFill([
                'current_run_id' => $run->id,
                'business_key' => $businessKey,
                'visibility_labels' => $visibilityLabels,
                'memo' => $memo,
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'started_at' => $startedAt,
                'run_count' => $run->run_number,
            ])->save();

            WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'search_attributes' => $run->search_attributes,
                'outcome' => $command->outcome?->value,
            ], null, $command);

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_command_id' => $command->id,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'search_attributes' => $run->search_attributes,
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'run_timeout_seconds' => $runTimeoutSeconds,
                'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
                'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
                'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint($workflowClass),
                'declared_queries' => $commandContract['queries'],
                'declared_query_contracts' => $commandContract['query_contracts'],
                'declared_signals' => $commandContract['signals'],
                'declared_signal_contracts' => $commandContract['signal_contracts'],
                'declared_updates' => $commandContract['updates'],
                'declared_update_contracts' => $commandContract['update_contracts'],
                'declared_entry_method' => $commandContract['entry_method'],
                'declared_entry_mode' => $commandContract['entry_mode'],
                'declared_entry_declaring_class' => $commandContract['entry_declaring_class'],
            ], null, $command);

            /** @var WorkflowTask $task */
            $task = self::taskQuery()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            LifecycleEventDispatcher::workflowStarted($run);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a start command.',
                $this->instance->id
            ));
        }

        return StartResult::fromCommand($command);
    }

    public function signalWithStart(
        string $name,
        array $signalArguments = [],
        ...$startArguments
    ): SignalWithStartResult {
        $result = $this->attemptSignalWithStart($name, $signalArguments, ...$startArguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot receive signal-with-start [%s]: %s.',
                $this->instance->id,
                $name,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function attemptSignalWithStart(
        string $name,
        array $signalArguments = [],
        ...$startArguments
    ): SignalWithStartResult {
        if ($this->runTargeted) {
            throw new LogicException('Workflow v2 signalWithStart only supports instance-targeted workflow stubs.');
        }

        [$startArguments, $startOptions] = $this->extractStartArguments(
            $startArguments,
            DuplicateStartPolicy::ReturnExistingActive,
        );

        if ($startOptions->duplicateStartPolicy !== DuplicateStartPolicy::ReturnExistingActive) {
            throw new LogicException(
                'Workflow v2 signalWithStart requires StartOptions::returnExistingActive() semantics.'
            );
        }

        $signalArguments = array_is_list($signalArguments)
            ? array_values($signalArguments)
            : $signalArguments;

        return $this->attemptSignalWithStartInternal($name, $signalArguments, $startArguments, $startOptions);
    }

    public function cancel(?string $reason = null): CommandResult
    {
        $result = $this->attemptCancel($reason);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be cancelled: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function signal(string $name, ...$arguments): CommandResult
    {
        $result = $this->attemptSignalWithArguments($name, $arguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot receive signal [%s]: %s.',
                $this->instance->id,
                $name,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function update(string $method, ...$arguments): mixed
    {
        $result = $this->attemptUpdate($method, ...$arguments);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot apply update [%s]: %s.',
                $this->instance->id,
                $method,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        if ($result->failed()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] update [%s] failed: %s.',
                $this->instance->id,
                $method,
                $result->failureMessage() ?? 'unknown',
            ));
        }

        if (! $result->completed()) {
            $timedOutSuffix = $result->waitTimedOut()
                ? sprintf(
                    ' after waiting %d second%s',
                    $result->waitTimeoutSeconds(),
                    $result->waitTimeoutSeconds() === 1 ? '' : 's',
                )
                : '';

            throw new LogicException(sprintf(
                'Workflow instance [%s] update [%s] was accepted but did not finish applying%s. Use inspectUpdate(%s) to read the durable update lifecycle or submitUpdate() to return immediately after acceptance.',
                $this->instance->id,
                $method,
                $timedOutSuffix,
                var_export($result->updateId(), true),
            ));
        }

        return $result->result();
    }

    public function attemptUpdate(string $method, ...$arguments): UpdateResult
    {
        return $this->attemptUpdateWithArguments($method, $arguments);
    }

    public function submitUpdate(string $method, ...$arguments): UpdateResult
    {
        return $this->submitUpdateWithArguments($method, $arguments);
    }

    public function inspectUpdate(string $updateId): UpdateResult
    {
        $this->refresh();

        if ($updateId === '') {
            throw new LogicException('Update id cannot be empty.');
        }

        /** @var WorkflowUpdate|null $update */
        $update = $this->scopedUpdateQuery($updateId)
            ->first();

        if (! $update instanceof WorkflowUpdate) {
            $scopeLabel = $this->runTargeted && $this->selectedRunId !== null
                ? sprintf('run [%s]', $this->selectedRunId)
                : sprintf('workflow instance [%s]', $this->instance->id);

            throw new LogicException(sprintf('Update [%s] was not found for %s.', $updateId, $scopeLabel));
        }

        return $this->updateResultFor($update, 'status');
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function submitUpdateWithArguments(string $method, array $arguments): UpdateResult
    {
        [$command, $update, $resumeTask] = $this->recordAcceptedUpdateWithArguments($method, $arguments);

        $this->refresh();

        if ($resumeTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($resumeTask);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record an update command.',
                $this->instance->id,
            ));
        }

        if ($command->status !== CommandStatus::Rejected) {
            self::recordUpdateSent($this->instance->id, $method, $arguments);
        }

        return UpdateResult::fromCommand($command, null, null, $update, 'accepted');
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function attemptUpdateWithArguments(string $method, array $arguments): UpdateResult
    {
        [$command, $update, $resumeTask] = $this->recordAcceptedUpdateWithArguments($method, $arguments);

        $this->refresh();

        if ($resumeTask instanceof WorkflowTask) {
            TaskDispatcher::dispatch($resumeTask);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record an update command.',
                $this->instance->id,
            ));
        }

        if ($command->status !== CommandStatus::Rejected) {
            self::recordUpdateSent($this->instance->id, $method, $arguments);
        }

        if (! $update instanceof WorkflowUpdate || $command->status === CommandStatus::Rejected) {
            return UpdateResult::fromCommand(
                $command,
                null,
                null,
                $update,
                'completed',
                false,
                $this->updateWaitTimeoutSeconds(),
            );
        }

        if ($this->shouldInlineAcceptedUpdateCompletion()) {
            $this->processAcceptedUpdateInline($command->workflow_run_id, $update->id);
        }

        $waitTimedOut = $this->waitForAcceptedUpdateToClose($update->id, $this->updateWaitTimeoutSeconds());

        return $this->freshUpdateResult(
            $command->id,
            $update->id,
            'completed',
            $waitTimedOut,
            $this->updateWaitTimeoutSeconds(),
        );
    }

    public function repair(): CommandResult
    {
        $result = $this->attemptRepair();

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be repaired: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function attemptSignal(string $name, ...$arguments): CommandResult
    {
        return $this->attemptSignalWithArguments($name, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    public function attemptSignalWithArguments(
        string $name,
        array $arguments,
        ?string $payloadCodec = null,
        ?string $payloadBlob = null,
    ): CommandResult {
        $arguments = array_is_list($arguments)
            ? array_values($arguments)
            : $arguments;

        if ($name === '') {
            throw new LogicException('Signal name cannot be empty.');
        }

        return $this->attemptSignalInternal($name, $arguments, $payloadCodec, $payloadBlob);
    }

    public function attemptRepair(): CommandResult
    {
        /** @var WorkflowCommand|null $command */
        $command = null;
        $task = null;

        DB::transaction(function () use (&$command, &$task): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Repair,
                    'instance_not_started',
                    $this->commandTargetScope(),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = self::runQuery()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $currentRun->id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        CommandType::Repair,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                        [
                            'resolved_workflow_run_id' => $currentRun->id,
                        ],
                    );

                    return;
                }
            } else {
                $run = $currentRun;
            }

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Repair,
                    'run_not_active',
                    $this->commandTargetScope(),
                );

                return;
            }

            $run->setRelation('instance', $instance);
            $run->setRelation(
                'tasks',
                self::taskQuery()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('available_at')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'activityExecutions',
                ActivityExecution::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'timers',
                WorkflowTimer::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'failures',
                WorkflowFailure::query()
                    ->where('workflow_run_id', $run->id)
                    ->latest('created_at')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'historyEvents',
                WorkflowHistoryEvent::query()
                    ->where('workflow_run_id', $run->id)
                    ->orderBy('sequence')
                    ->lockForUpdate()
                    ->get()
            );
            $run->setRelation(
                'childLinks',
                \Workflow\V2\Models\WorkflowLink::query()
                    ->where('parent_workflow_run_id', $run->id)
                    ->oldest('created_at')
                    ->lockForUpdate()
                    ->get()
            );

            $summary = RunSummaryProjector::project($run);

            if (in_array($summary->liveness_state, ['repair_needed', 'workflow_replay_blocked'], true)) {
                $task = TaskRepair::repairRun($run, $summary);
            }

            $run->forceFill([
                'last_progress_at' => now(),
            ])->save();

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Repair->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => $task instanceof WorkflowTask
                    ? CommandOutcome::RepairDispatched->value
                    : CommandOutcome::RepairNotNeeded->value,
                'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
                'payload' => Serializer::serializeWithCodec($run->payload_codec ?? CodecRegistry::defaultCodec(), [
                    'liveness_state' => $summary->liveness_state,
                    'wait_kind' => $summary->wait_kind,
                    'task_id' => $task?->id,
                    'task_type' => $task?->task_type?->value,
                ]),
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            WorkflowHistoryEvent::record($run, HistoryEventType::RepairRequested, [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => CommandType::Repair->value,
                'outcome' => $command->outcome?->value,
                'liveness_state' => $summary->liveness_state,
                'wait_kind' => $summary->wait_kind,
                'task_id' => $task?->id,
                'task_type' => $task?->task_type?->value,
            ], $task, $command);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a repair command.',
                $this->instance->id,
            ));
        }

        return new CommandResult($command);
    }

    public function attemptCancel(?string $reason = null): CommandResult
    {
        return $this->attemptTerminalCommand(
            CommandType::Cancel,
            RunStatus::Cancelled,
            HistoryEventType::CancelRequested,
            HistoryEventType::WorkflowCancelled,
            'cancelled',
            $reason,
        );
    }

    public function terminate(?string $reason = null): CommandResult
    {
        $result = $this->attemptTerminate($reason);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be terminated: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function attemptTerminate(?string $reason = null): CommandResult
    {
        return $this->attemptTerminalCommand(
            CommandType::Terminate,
            RunStatus::Terminated,
            HistoryEventType::TerminateRequested,
            HistoryEventType::WorkflowTerminated,
            'terminated',
            $reason,
        );
    }

    public function archive(?string $reason = null): CommandResult
    {
        $result = $this->attemptArchive($reason);

        if ($result->rejected()) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] cannot be archived: %s.',
                $this->instance->id,
                $result->rejectionReason() ?? 'unknown',
            ));
        }

        return $result;
    }

    public function attemptArchive(?string $reason = null): CommandResult
    {
        /** @var WorkflowCommand|null $command */
        $command = null;

        DB::transaction(function () use (&$command, $reason): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Archive,
                    'instance_not_started',
                    $this->commandTargetScope(),
                    $this->archiveCommandPayloadAttributes($reason),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = self::runQuery()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);
            } else {
                $run = $currentRun;
            }

            if (! $run->status->isTerminal()) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Archive,
                    'run_not_closed',
                    $this->commandTargetScope(),
                    $this->archiveCommandPayloadAttributes($reason, [
                        'resolved_workflow_run_id' => $currentRun->id,
                    ]),
                );

                return;
            }

            $alreadyArchived = $run->archived_at !== null;

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Archive->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => $alreadyArchived
                    ? CommandOutcome::ArchiveNotNeeded->value
                    : CommandOutcome::Archived->value,
                ...$this->archiveCommandPayloadAttributes($reason),
                'accepted_at' => now(),
                'applied_at' => now(),
            ]));

            WorkflowHistoryEvent::record($run, HistoryEventType::ArchiveRequested, array_filter([
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => CommandType::Archive->value,
                'outcome' => $command->outcome?->value,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null), null, $command);

            if (! $alreadyArchived) {
                $run->forceFill([
                    'archived_at' => now(),
                    'archive_command_id' => $command->id,
                    'archive_reason' => $reason,
                    'last_progress_at' => now(),
                ])->save();

                WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowArchived, array_filter([
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'archive_command_id' => $command->id,
                    'reason' => $reason,
                ], static fn (mixed $value): bool => $value !== null), null, $command);
            }

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record an archive command.',
                $this->instance->id,
            ));
        }

        return new CommandResult($command);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{0: WorkflowCommand|null, 1: WorkflowUpdate|null, 2: WorkflowTask|null}
     */
    private function recordAcceptedUpdateWithArguments(string $method, array $arguments): array
    {
        $this->refresh();

        /** @var WorkflowCommand|null $command */
        $command = null;
        /** @var WorkflowUpdate|null $update */
        $update = null;
        /** @var WorkflowTask|null $resumeTask */
        $resumeTask = null;

        DB::transaction(function () use ($method, $arguments, &$command, &$update, &$resumeTask): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Update,
                    'instance_not_started',
                    $this->commandTargetScope(),
                    $this->updateCommandPayloadAttributes($method, $arguments),
                );

                $update = $this->recordRejectedUpdate($command, $method, $arguments);

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = self::runQuery()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);
                $updateName = $method;
                $updateCommandAttributes = $this->updateCommandPayloadAttributes($updateName, $arguments);

                if ($run->id !== $currentRun->id) {
                    [$command, $update] = $this->rejectUpdateCommand(
                        $instance,
                        $run,
                        $updateName,
                        $arguments,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                        array_merge($updateCommandAttributes, [
                            'resolved_workflow_run_id' => $currentRun->id,
                        ]),
                    );

                    return;
                }
            } else {
                $run = $currentRun;
                $updateName = $method;
                $updateCommandAttributes = $this->updateCommandPayloadAttributes($updateName, $arguments);
            }

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    $arguments,
                    'run_not_active',
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                );

                return;
            }

            $this->loadLockedRunRelations($run, $instance);

            if (! RunCommandContract::hasUpdateMethod($run, $updateName)) {
                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    $arguments,
                    'unknown_update',
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                );

                return;
            }

            $validatedArguments = $this->validatedUpdateArgumentsForRun($run, $updateName, $arguments);

            if ($validatedArguments['validation_errors'] !== []) {
                $updateCommandAttributes = $this->updateCommandPayloadAttributes(
                    $updateName,
                    $arguments,
                    $validatedArguments['validation_errors'],
                );

                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    $arguments,
                    'invalid_update_arguments',
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                    $validatedArguments['validation_errors'],
                );

                return;
            }

            if (UpdateCommandGate::blockedReason($run) !== null) {
                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    $arguments,
                    UpdateCommandGate::BLOCKED_BY_PENDING_SIGNAL,
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                );

                return;
            }

            $arguments = $validatedArguments['arguments'];
            $workflowExecutionBlockedReason = WorkflowExecutionGate::blockedReason($run);

            if ($workflowExecutionBlockedReason !== null) {
                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    $arguments,
                    $workflowExecutionBlockedReason,
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                );

                return;
            }

            try {
                StructuralLimits::guardPendingUpdates($run);
            } catch (StructuralLimitExceededException $e) {
                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    $arguments,
                    'structural_limit_exceeded',
                    $this->commandTargetScope(),
                    $updateCommandAttributes,
                );

                return;
            }

            $updateCodec = $run->payload_codec ?? CodecRegistry::defaultCodec();
            $serializedUpdateArguments = Serializer::serializeWithCodec($updateCodec, $arguments);

            StructuralLimits::logWarning(
                StructuralLimits::warnApproachingPayloadSize($serializedUpdateArguments),
                [
                    'workflow_run_id' => $run->id,
                    'workflow_type' => $run->workflow_type,
                    'payload_site' => 'update_input',
                    'update_name' => $updateName,
                ],
            );

            try {
                StructuralLimits::guardPayloadSize($serializedUpdateArguments);
            } catch (StructuralLimitExceededException $e) {
                [$command, $update] = $this->rejectUpdateCommand(
                    $instance,
                    $run,
                    $updateName,
                    [],
                    'structural_limit_exceeded',
                    $this->commandTargetScope(),
                    $this->payloadLimitExceededCommandPayloadAttributes($updateName, $updateCodec, $e),
                    ['arguments' => [$e->getMessage()]],
                );

                return;
            }

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes(array_merge([
                'command_type' => CommandType::Update->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'accepted_at' => now(),
            ], $updateCommandAttributes)));

            /** @var WorkflowUpdate $update */
            $update = WorkflowUpdate::query()->create([
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'target_scope' => $command->target_scope,
                'requested_workflow_run_id' => $command->requestedRunId(),
                'resolved_workflow_run_id' => $command->resolvedRunId(),
                'update_name' => $updateName,
                'status' => UpdateStatus::Accepted->value,
                'command_sequence' => $command->command_sequence,
                'payload_codec' => $updateCodec,
                'arguments' => $serializedUpdateArguments,
                'accepted_at' => $command->accepted_at,
            ]);

            WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
                'workflow_command_id' => $command->id,
                'update_id' => $update->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'update_name' => $updateName,
                'arguments' => $serializedUpdateArguments,
            ], null, $command);

            $resumeTask = $this->readyWorkflowTaskForDispatch($run->id);

            if ($resumeTask instanceof WorkflowTask) {
                $resumeTask = $this->mergeWorkflowTaskPayload(
                    $resumeTask,
                    WorkflowTaskPayload::forUpdate($update),
                );
            }

            if (! $resumeTask instanceof WorkflowTask && ! $this->hasOpenWorkflowTask($run->id)) {
                /** @var WorkflowTask $resumeTask */
                $resumeTask = self::taskQuery()->create([
                    'workflow_run_id' => $run->id,
                    'namespace' => $run->namespace,
                    'task_type' => TaskType::Workflow->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => now(),
                    'payload' => WorkflowTaskPayload::forUpdate($update),
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                ]);
            }

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        return [$command, $update, $resumeTask];
    }

    private function shouldInlineAcceptedUpdateCompletion(): bool
    {
        return Queue::getFacadeRoot() instanceof QueueFake;
    }

    private function processAcceptedUpdateInline(?string $runId, string $updateId): void
    {
        if (! is_string($runId) || $runId === '') {
            return;
        }

        $attempts = 0;

        while ($attempts < 25) {
            /** @var WorkflowUpdate|null $update */
            $update = WorkflowUpdate::query()->find($updateId);

            if (! $update instanceof WorkflowUpdate || $update->status !== UpdateStatus::Accepted) {
                return;
            }

            /** @var WorkflowTask|null $task */
            $task = self::taskQuery()
                ->where('workflow_run_id', $runId)
                ->where('task_type', TaskType::Workflow->value)
                ->where('status', TaskStatus::Ready->value)
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->first();

            if (! $task instanceof WorkflowTask) {
                return;
            }

            app()
                ->call([new RunWorkflowTask($task->id), 'handle']);

            $attempts++;
        }
    }

    private function waitForAcceptedUpdateToClose(string $updateId, int $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $pollIntervalSeconds = UpdateWaitPolicy::pollIntervalMilliseconds() / 1000;

        while (true) {
            /** @var WorkflowUpdate|null $update */
            $update = WorkflowUpdate::query()->find($updateId);

            if (! $update instanceof WorkflowUpdate || $update->status !== UpdateStatus::Accepted) {
                return false;
            }

            $remainingSeconds = $deadline - microtime(true);

            if ($remainingSeconds <= 0) {
                return true;
            }

            usleep((int) (min($pollIntervalSeconds, $remainingSeconds) * 1000000));
        }
    }

    private function freshUpdateResult(
        string $commandId,
        string $updateId,
        string $waitFor = 'completed',
        bool $waitTimedOut = false,
        ?int $waitTimeoutSeconds = null,
    ): UpdateResult {
        /** @var WorkflowUpdate|null $update */
        $update = WorkflowUpdate::query()->find($updateId);

        if (! $update instanceof WorkflowUpdate) {
            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::query()->findOrFail($commandId);

            return UpdateResult::fromCommand($command, null, null, null, $waitFor, $waitTimedOut, $waitTimeoutSeconds);
        }

        return $this->updateResultFor($update, $waitFor, $waitTimedOut, $waitTimeoutSeconds);
    }

    private function updateResultFor(
        WorkflowUpdate $update,
        string $waitFor = 'completed',
        bool $waitTimedOut = false,
        ?int $waitTimeoutSeconds = null,
    ): UpdateResult {
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($update->workflow_command_id);
        /** @var WorkflowFailure|null $failure */
        $failure = is_string($update->failure_id)
            ? WorkflowFailure::query()->find($update->failure_id)
            : null;

        return UpdateResult::fromCommand(
            $command,
            $update->status === UpdateStatus::Completed ? $update->updateResult() : null,
            $failure,
            $update,
            $waitFor,
            $waitTimedOut,
            $waitTimeoutSeconds,
        );
    }

    private function scopedUpdateQuery(string $updateId)
    {
        $query = WorkflowUpdate::query()
            ->whereKey($updateId)
            ->where('workflow_instance_id', $this->instance->id);

        if ($this->runTargeted && $this->selectedRunId !== null) {
            $query->where('workflow_run_id', $this->selectedRunId);
        }

        return $query;
    }

    private function updateWaitTimeoutSeconds(): int
    {
        return $this->updateWaitTimeoutSeconds ?? UpdateWaitPolicy::completionTimeoutSeconds();
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function attemptSignalInternal(
        string $name,
        array $arguments,
        ?string $payloadCodec = null,
        ?string $payloadBlob = null,
    ): CommandResult {
        if ($name === '') {
            throw new LogicException('Signal name cannot be empty.');
        }

        /** @var WorkflowCommand|null $command */
        $command = null;
        $task = null;

        DB::transaction(function () use ($name, $arguments, $payloadCodec, $payloadBlob, &$command, &$task): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    CommandType::Signal,
                    'instance_not_started',
                    $this->commandTargetScope(),
                    $this->signalCommandPayloadAttributes($name, $arguments, [], $payloadCodec),
                );
                $this->recordRejectedSignal($command, $name, $arguments);

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = self::runQuery()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $currentRun->id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        CommandType::Signal,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                        array_merge($this->signalCommandPayloadAttributes($name, $arguments, [], $payloadCodec), [
                            'resolved_workflow_run_id' => $currentRun->id,
                        ]),
                    );
                    $this->recordRejectedSignal($command, $name, $arguments);

                    return;
                }
            } else {
                $run = $currentRun;
            }

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'run_not_active',
                    $this->commandTargetScope(),
                    $this->signalCommandPayloadAttributes($name, $arguments, [], $payloadCodec),
                );
                $this->recordRejectedSignal($command, $name, $arguments);

                return;
            }

            $this->loadLockedRunRelations($run, $instance);

            if (! RunCommandContract::hasSignal($run, $name)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'unknown_signal',
                    $this->commandTargetScope(),
                    $this->signalCommandPayloadAttributes($name, $arguments, [], $payloadCodec),
                );
                $this->recordRejectedSignal($command, $name, $arguments);

                return;
            }

            $validatedArguments = $this->validatedSignalArgumentsForRun($run, $name, $arguments);

            if ($validatedArguments['validation_errors'] !== []) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'invalid_signal_arguments',
                    $this->commandTargetScope(),
                    $this->signalCommandPayloadAttributes(
                        $name,
                        $arguments,
                        $validatedArguments['validation_errors'],
                        $payloadCodec,
                    ),
                );
                $this->recordRejectedSignal($command, $name, $arguments, $validatedArguments['validation_errors']);

                return;
            }

            $arguments = $validatedArguments['arguments'];

            try {
                StructuralLimits::guardPendingSignals($run);
            } catch (StructuralLimitExceededException $e) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'structural_limit_exceeded',
                    $this->commandTargetScope(),
                    $this->signalCommandPayloadAttributes($name, $arguments, [], $payloadCodec),
                );
                $this->recordRejectedSignal($command, $name, $arguments);

                return;
            }

            $signalCodec = $payloadCodec ?? $run->payload_codec ?? CodecRegistry::defaultCodec();
            $serializedSignalArguments = $payloadBlob ?? Serializer::serializeWithCodec($signalCodec, $arguments);

            StructuralLimits::logWarning(
                StructuralLimits::warnApproachingPayloadSize($serializedSignalArguments),
                [
                    'workflow_run_id' => $run->id,
                    'workflow_type' => $run->workflow_type,
                    'payload_site' => 'signal_input',
                    'signal_name' => $name,
                ],
            );

            try {
                StructuralLimits::guardPayloadSize($serializedSignalArguments);
            } catch (StructuralLimitExceededException $e) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    CommandType::Signal,
                    'structural_limit_exceeded',
                    $this->commandTargetScope(),
                    $this->payloadLimitExceededCommandPayloadAttributes($name, $signalCodec, $e),
                );
                $this->recordRejectedSignal($command, $name, [], ['arguments' => [$e->getMessage()]]);

                return;
            }

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => CommandType::Signal->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => CommandOutcome::SignalReceived->value,
                ...$this->signalCommandPayloadAttributes($name, $arguments, [], $payloadCodec),
                'accepted_at' => now(),
            ]));

            $signalWaitId = $this->signalWaitIdForAcceptedCommand($run, $name, $command->id);
            $signal = $this->recordAcceptedSignal(
                $instance,
                $run,
                $command,
                $name,
                $arguments,
                $signalWaitId,
                $payloadCodec,
                $serializedSignalArguments,
            );

            WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, array_filter([
                'workflow_command_id' => $command->id,
                'signal_id' => $signal->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'signal_name' => $name,
                'signal_wait_id' => $signalWaitId,
            ], static fn (mixed $value): bool => $value !== null), null, $command);

            if (! $this->hasOpenWorkflowTask($run->id)) {
                /** @var WorkflowTask $task */
                $task = self::taskQuery()->create([
                    'workflow_run_id' => $run->id,
                    'namespace' => $run->namespace,
                    'task_type' => TaskType::Workflow->value,
                    'status' => TaskStatus::Ready->value,
                    'available_at' => now(),
                    'payload' => WorkflowTaskPayload::forSignal($signal),
                    'connection' => $run->connection,
                    'queue' => $run->queue,
                    'compatibility' => $run->compatibility,
                ]);
            }

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a signal command.',
                $this->instance->id
            ));
        }

        $result = new CommandResult($command);

        if ($result->accepted()) {
            self::recordSignalSent($this->instance->id, $name, $arguments);
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $signalArguments
     * @param list<mixed> $startArguments
     */
    private function attemptSignalWithStartInternal(
        string $name,
        array $signalArguments,
        array $startArguments,
        StartOptions $startOptions,
    ): SignalWithStartResult {
        if ($name === '') {
            throw new LogicException('Signal name cannot be empty.');
        }

        $this->refresh();

        /** @var WorkflowCommand|null $startCommand */
        $startCommand = null;
        /** @var WorkflowCommand|null $signalCommand */
        $signalCommand = null;
        $task = null;
        $intakeGroupId = (string) Str::ulid();

        DB::transaction(function () use (
            $name,
            $signalArguments,
            $startArguments,
            $startOptions,
            $intakeGroupId,
            &$startCommand,
            &$signalCommand,
            &$task,
        ): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $commandContext = $this->signalWithStartCommandContext($intakeGroupId);
            $currentRun = $this->currentRunForInstance($instance, true);

            if ($currentRun instanceof WorkflowRun && $this->runIsActive($currentRun)) {
                $run = $currentRun;

                $this->loadLockedRunRelations($run, $instance);

                if (! RunCommandContract::hasSignal($run, $name)) {
                    $signalCommand = $this->rejectSignalCommandForContext(
                        $commandContext,
                        $instance,
                        $run,
                        $name,
                        $signalArguments,
                        'unknown_signal',
                    );

                    return;
                }

                $validatedSignalArguments = $this->validatedSignalArgumentsForRun($run, $name, $signalArguments);

                if ($validatedSignalArguments['validation_errors'] !== []) {
                    $signalCommand = $this->rejectSignalCommandForContext(
                        $commandContext,
                        $instance,
                        $run,
                        $name,
                        $signalArguments,
                        'invalid_signal_arguments',
                        $validatedSignalArguments['validation_errors'],
                    );

                    return;
                }

                $signalArguments = $validatedSignalArguments['arguments'];

                try {
                    StructuralLimits::guardPendingSignals($run);
                } catch (StructuralLimitExceededException $e) {
                    $signalCommand = $this->rejectSignalCommandForContext(
                        $commandContext,
                        $instance,
                        $run,
                        $name,
                        $signalArguments,
                        'structural_limit_exceeded',
                    );

                    return;
                }

                $metadata = WorkflowMetadata::fromStartArguments($startArguments);

                /** @var WorkflowCommand $startCommand */
                $startCommand = WorkflowCommand::record($instance, $run, $this->commandAttributesForContext(
                    $commandContext,
                    [
                        'command_type' => CommandType::Start->value,
                        'target_scope' => 'instance',
                        'status' => CommandStatus::Accepted->value,
                        'outcome' => CommandOutcome::ReturnedExistingActive->value,
                        'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
                        'payload' => Serializer::serializeWithCodec(
                            $run->payload_codec ?? CodecRegistry::defaultCodec(),
                            $metadata->arguments
                        ),
                        'accepted_at' => now(),
                        'applied_at' => now(),
                    ],
                ));

                WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                    'workflow_command_id' => $startCommand->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'workflow_class' => $run->workflow_class,
                    'workflow_type' => $run->workflow_type,
                    'business_key' => $run->business_key,
                    'visibility_labels' => $run->visibility_labels,
                    'memo' => $run->memo,
                    'outcome' => $startCommand->outcome?->value,
                ], null, $startCommand);

                /** @var WorkflowCommand $signalCommand */
                $signalCommand = WorkflowCommand::record($instance, $run, $this->commandAttributesForContext(
                    $commandContext,
                    [
                        'command_type' => CommandType::Signal->value,
                        'target_scope' => 'instance',
                        'status' => CommandStatus::Accepted->value,
                        'outcome' => CommandOutcome::SignalReceived->value,
                        ...$this->signalCommandPayloadAttributes($name, $signalArguments),
                        'accepted_at' => now(),
                    ],
                ));

                $signalWaitId = $this->signalWaitIdForAcceptedCommand($run, $name, $signalCommand->id);
                $signal = $this->recordAcceptedSignal(
                    $instance,
                    $run,
                    $signalCommand,
                    $name,
                    $signalArguments,
                    $signalWaitId,
                );

                WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, array_filter([
                    'workflow_command_id' => $signalCommand->id,
                    'signal_id' => $signal->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'signal_name' => $name,
                    'signal_wait_id' => $signalWaitId,
                ], static fn (mixed $value): bool => $value !== null), null, $signalCommand);

                if (! $this->hasOpenWorkflowTask($run->id)) {
                    /** @var WorkflowTask $task */
                    $task = self::taskQuery()->create([
                        'workflow_run_id' => $run->id,
                        'namespace' => $run->namespace,
                        'task_type' => TaskType::Workflow->value,
                        'status' => TaskStatus::Ready->value,
                        'available_at' => now(),
                        'payload' => WorkflowTaskPayload::forSignal($signal),
                        'connection' => $run->connection,
                        'queue' => $run->queue,
                        'compatibility' => $run->compatibility,
                    ]);
                }

                RunSummaryProjector::project(
                    $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                );

                return;
            }

            $workflowClass = TypeRegistry::resolveWorkflowClass($instance->workflow_class, $instance->workflow_type);

            if (! WorkflowDefinition::hasSignal($workflowClass, $name)) {
                $signalCommand = $this->rejectSignalCommandForContext(
                    $commandContext,
                    $instance,
                    null,
                    $name,
                    $signalArguments,
                    'unknown_signal',
                );

                return;
            }

            $validatedSignalArguments = $this->validatedSignalArgumentsForWorkflow(
                $workflowClass,
                $name,
                $signalArguments
            );

            if ($validatedSignalArguments['validation_errors'] !== []) {
                $signalCommand = $this->rejectSignalCommandForContext(
                    $commandContext,
                    $instance,
                    null,
                    $name,
                    $signalArguments,
                    'invalid_signal_arguments',
                    $validatedSignalArguments['validation_errors'],
                );

                return;
            }

            $signalArguments = $validatedSignalArguments['arguments'];
            $metadata = WorkflowMetadata::fromStartArguments($startArguments);
            $commandContract = RunCommandContract::snapshot($workflowClass);
            $businessKey = $startOptions->businessKey ?? $instance->business_key;
            $visibilityLabels = $startOptions->labels !== []
                ? $startOptions->labels
                : (is_array($instance->visibility_labels) ? $instance->visibility_labels : null);
            $memo = $startOptions->memo !== []
                ? $startOptions->memo
                : (is_array($instance->memo) ? $instance->memo : null);
            $searchAttributes = $startOptions->searchAttributes !== []
                ? $startOptions->searchAttributes
                : null;
            $executionTimeoutSeconds = $startOptions->executionTimeoutSeconds;
            $runTimeoutSeconds = $startOptions->runTimeoutSeconds;

            if ($instance->workflow_class !== $workflowClass) {
                $instance->forceFill([
                    'workflow_class' => $workflowClass,
                ])->save();
            }

            $startedAt = now();
            $executionDeadlineAt = $executionTimeoutSeconds !== null
                ? $startedAt->copy()
                    ->addSeconds($executionTimeoutSeconds)
                : null;
            $runDeadlineAt = $runTimeoutSeconds !== null
                ? $startedAt->copy()
                    ->addSeconds($runTimeoutSeconds)
                : null;

            /** @var WorkflowRun $run */
            $run = self::runQuery()->create([
                'workflow_instance_id' => $instance->id,
                'run_number' => $instance->run_count + 1,
                'workflow_class' => $workflowClass,
                'workflow_type' => $instance->workflow_type,
                'business_key' => $businessKey,
                'visibility_labels' => $visibilityLabels,
                'memo' => $memo,
                'search_attributes' => $searchAttributes,
                'run_timeout_seconds' => $runTimeoutSeconds,
                'execution_deadline_at' => $executionDeadlineAt,
                'run_deadline_at' => $runDeadlineAt,
                'status' => RunStatus::Pending->value,
                'compatibility' => WorkerCompatibility::current(),
                'payload_codec' => CodecRegistry::defaultCodec(),
                'arguments' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), $metadata->arguments),
                'connection' => RoutingResolver::workflowConnection($workflowClass, $metadata),
                'queue' => RoutingResolver::workflowQueue($workflowClass, $metadata),
                'started_at' => $startedAt,
                'last_progress_at' => $startedAt,
                'last_history_sequence' => 0,
            ]);

            /** @var WorkflowCommand $startCommand */
            $startCommand = WorkflowCommand::record($instance, $run, $this->commandAttributesForContext(
                $commandContext,
                [
                    'command_type' => CommandType::Start->value,
                    'target_scope' => 'instance',
                    'status' => CommandStatus::Accepted->value,
                    'outcome' => CommandOutcome::StartedNew->value,
                    'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
                    'payload' => Serializer::serializeWithCodec(
                        $run->payload_codec ?? CodecRegistry::defaultCodec(),
                        $metadata->arguments
                    ),
                    'accepted_at' => now(),
                    'applied_at' => now(),
                ],
            ));

            $instance->forceFill([
                'current_run_id' => $run->id,
                'business_key' => $businessKey,
                'visibility_labels' => $visibilityLabels,
                'memo' => $memo,
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'started_at' => $startedAt,
                'run_count' => $run->run_number,
            ])->save();

            WorkflowHistoryEvent::record($run, HistoryEventType::StartAccepted, [
                'workflow_command_id' => $startCommand->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'search_attributes' => $run->search_attributes,
                'outcome' => $startCommand->outcome?->value,
            ], null, $startCommand);

            WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
                'workflow_class' => $run->workflow_class,
                'workflow_type' => $run->workflow_type,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'workflow_command_id' => $startCommand->id,
                'business_key' => $run->business_key,
                'visibility_labels' => $run->visibility_labels,
                'memo' => $run->memo,
                'search_attributes' => $run->search_attributes,
                'execution_timeout_seconds' => $executionTimeoutSeconds,
                'run_timeout_seconds' => $runTimeoutSeconds,
                'execution_deadline_at' => $executionDeadlineAt?->toIso8601String(),
                'run_deadline_at' => $runDeadlineAt?->toIso8601String(),
                'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint($workflowClass),
                'declared_queries' => $commandContract['queries'],
                'declared_query_contracts' => $commandContract['query_contracts'],
                'declared_signals' => $commandContract['signals'],
                'declared_signal_contracts' => $commandContract['signal_contracts'],
                'declared_updates' => $commandContract['updates'],
                'declared_update_contracts' => $commandContract['update_contracts'],
                'declared_entry_method' => $commandContract['entry_method'],
                'declared_entry_mode' => $commandContract['entry_mode'],
                'declared_entry_declaring_class' => $commandContract['entry_declaring_class'],
            ], null, $startCommand);

            /** @var WorkflowTask $task */
            $task = self::taskQuery()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $run->connection,
                'queue' => $run->queue,
                'compatibility' => $run->compatibility,
            ]);

            /** @var WorkflowCommand $signalCommand */
            $signalCommand = WorkflowCommand::record($instance, $run, $this->commandAttributesForContext(
                $commandContext,
                [
                    'command_type' => CommandType::Signal->value,
                    'target_scope' => 'instance',
                    'status' => CommandStatus::Accepted->value,
                    'outcome' => CommandOutcome::SignalReceived->value,
                    ...$this->signalCommandPayloadAttributes($name, $signalArguments),
                    'accepted_at' => now(),
                ],
            ));

            $signalWaitId = $this->signalWaitIdForAcceptedCommand($run, $name, $signalCommand->id);
            $signal = $this->recordAcceptedSignal(
                $instance,
                $run,
                $signalCommand,
                $name,
                $signalArguments,
                $signalWaitId,
            );

            WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived, array_filter([
                'workflow_command_id' => $signalCommand->id,
                'signal_id' => $signal->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'signal_name' => $name,
                'signal_wait_id' => $signalWaitId,
            ], static fn (mixed $value): bool => $value !== null), null, $signalCommand);

            LifecycleEventDispatcher::workflowStarted($run);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        if ($task instanceof WorkflowTask) {
            TaskDispatcher::dispatch($task);
        }

        if (! $signalCommand instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a signal-with-start command.',
                $this->instance->id,
            ));
        }

        $signalWithStartResult = SignalWithStartResult::fromCommands($signalCommand, $startCommand, $intakeGroupId);

        if ($signalWithStartResult->accepted()) {
            self::recordSignalSent($this->instance->id, $name, $signalArguments);
        }

        return $signalWithStartResult;
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array{0: array<int, mixed>, 1: StartOptions}
     */
    private function extractStartArguments(
        array $arguments,
        DuplicateStartPolicy $defaultDuplicateStartPolicy = DuplicateStartPolicy::RejectDuplicate,
    ): array {
        $startOptions = null;

        foreach ($arguments as $index => $argument) {
            if (! $argument instanceof StartOptions) {
                continue;
            }

            $startOptions = $argument;
            unset($arguments[$index]);
        }

        return [array_values($arguments), $startOptions ?? new StartOptions($defaultDuplicateStartPolicy)];
    }

    private function attemptTerminalCommand(
        CommandType $commandType,
        RunStatus $terminalStatus,
        HistoryEventType $requestedEventType,
        HistoryEventType $terminalEventType,
        string $closedReason,
        ?string $reason = null,
    ): CommandResult {
        /** @var WorkflowCommand|null $command */
        $command = null;
        $parentTasks = [];

        DB::transaction(function () use (
            &$command,
            &$parentTasks,
            $commandType,
            $terminalStatus,
            $requestedEventType,
            $terminalEventType,
            $closedReason,
            $reason,
        ): void {
            /** @var WorkflowInstance $instance */
            $instance = self::instanceQuery()
                ->lockForUpdate()
                ->findOrFail($this->instance->id);

            $currentRun = $this->currentRunForInstance($instance, true);

            if (! $currentRun instanceof WorkflowRun) {
                $command = $this->rejectCommand(
                    $instance,
                    null,
                    $commandType,
                    'instance_not_started',
                    $this->commandTargetScope(),
                );

                return;
            }

            if ($this->runTargeted) {
                /** @var WorkflowRun $run */
                $run = self::runQuery()
                    ->lockForUpdate()
                    ->findOrFail($this->selectedRunId);

                if ($run->id !== $currentRun->id) {
                    $command = $this->rejectCommand(
                        $instance,
                        $run,
                        $commandType,
                        'selected_run_not_current',
                        $this->commandTargetScope(),
                        [
                            'resolved_workflow_run_id' => $currentRun->id,
                        ],
                    );

                    return;
                }
            } else {
                $run = $currentRun;
            }

            $openTasks = self::taskQuery()
                ->where('workflow_run_id', $run->id)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openActivityExecutions = ActivityExecution::query()
                ->where('workflow_run_id', $run->id)
                ->whereIn('status', [ActivityStatus::Pending->value, ActivityStatus::Running->value])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $openTimers = WorkflowTimer::query()
                ->where('workflow_run_id', $run->id)
                ->where('status', TimerStatus::Pending->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (in_array($run->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                $command = $this->rejectCommand(
                    $instance,
                    $run,
                    $commandType,
                    'run_not_active',
                    $this->commandTargetScope(),
                );

                return;
            }

            $commandPayload = $reason !== null && $reason !== ''
                ? Serializer::serializeWithCodec($run->payload_codec ?? CodecRegistry::defaultCodec(), [
                    'reason' => $reason,
                ])
                : null;

            /** @var WorkflowCommand $command */
            $command = WorkflowCommand::record($instance, $run, $this->commandAttributes([
                'command_type' => $commandType->value,
                'target_scope' => $this->commandTargetScope(),
                'status' => CommandStatus::Accepted->value,
                'outcome' => match ($commandType) {
                    CommandType::Cancel => CommandOutcome::Cancelled->value,
                    CommandType::Terminate => CommandOutcome::Terminated->value,
                    default => null,
                },
                'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
                'payload' => $commandPayload,
                'accepted_at' => now(),
            ]));

            $requestedHistoryPayload = [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'command_type' => $commandType->value,
            ];

            if ($reason !== null && $reason !== '') {
                $requestedHistoryPayload['reason'] = $reason;
            }

            WorkflowHistoryEvent::record($run, $requestedEventType, $requestedHistoryPayload, null, $command);

            foreach ($openTasks as $task) {
                $task->forceFill([
                    'status' => TaskStatus::Cancelled,
                    'lease_expires_at' => null,
                    'last_error' => null,
                ])->save();
            }

            $tasksByActivityExecutionId = $openTasks
                ->filter(
                    static fn (WorkflowTask $task): bool => is_string($task->payload['activity_execution_id'] ?? null)
                )
                ->keyBy(static fn (WorkflowTask $task): string => $task->payload['activity_execution_id']);

            foreach ($openActivityExecutions as $execution) {
                $execution->forceFill([
                    'status' => ActivityStatus::Cancelled,
                    'closed_at' => $execution->closed_at ?? now(),
                ])->save();

                /** @var WorkflowTask|null $activityTask */
                $activityTask = $tasksByActivityExecutionId->get($execution->id);

                ActivityCancellation::record($run, $execution, $activityTask, $command);
            }

            foreach ($openTimers as $timer) {
                $timer->forceFill([
                    'status' => TimerStatus::Cancelled,
                ])->save();

                TimerCancellation::record($run, $timer, null, $command);
            }

            $run->forceFill([
                'status' => $terminalStatus,
                'closed_reason' => $closedReason,
                'closed_at' => now(),
                'last_progress_at' => now(),
            ])->save();

            $propagationKind = $commandType === CommandType::Cancel ? 'cancelled' : 'terminated';
            $failureCategory = $commandType === CommandType::Cancel
                ? FailureCategory::Cancelled
                : FailureCategory::Terminated;
            $failureExceptionClass = $commandType === CommandType::Cancel
                ? 'Workflow\\V2\\Exceptions\\WorkflowCancelledException'
                : 'Workflow\\V2\\Exceptions\\WorkflowTerminatedException';
            $failureMessage = $reason !== null && $reason !== ''
                ? sprintf('Workflow %s: %s', $closedReason, $reason)
                : sprintf('Workflow %s.', $closedReason);

            /** @var WorkflowFailure $failure */
            $failure = WorkflowFailure::query()->create([
                'workflow_run_id' => $run->id,
                'source_kind' => 'workflow_run',
                'source_id' => $run->id,
                'propagation_kind' => $propagationKind,
                'failure_category' => $failureCategory->value,
                'handled' => false,
                'exception_class' => $failureExceptionClass,
                'message' => $failureMessage,
                'file' => '',
                'line' => 0,
                'trace_preview' => '',
            ]);

            $terminalHistoryPayload = [
                'workflow_command_id' => $command->id,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'failure_id' => $failure->id,
                'failure_category' => $failureCategory->value,
                'closed_reason' => $closedReason,
                'exception_class' => $failureExceptionClass,
                'message' => $failureMessage,
            ];

            if ($reason !== null && $reason !== '') {
                $terminalHistoryPayload['reason'] = $reason;
            }

            WorkflowHistoryEvent::record($run, $terminalEventType, $terminalHistoryPayload, null, $command);

            $command->forceFill([
                'applied_at' => now(),
            ])->save();

            ParentClosePolicyEnforcer::enforce($run);

            $parentTasks = $this->createParentResumeTasks($run);

            RunSummaryProjector::project(
                $run->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
            );
        });

        $this->refresh();

        foreach ($parentTasks as $task) {
            if ($task instanceof WorkflowTask) {
                TaskDispatcher::dispatch($task);
            }
        }

        if (! $command instanceof WorkflowCommand) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] failed to record a %s command.',
                $this->instance->id,
                $commandType->value,
            ));
        }

        return new CommandResult($command);
    }

    private function rejectCommand(
        WorkflowInstance $instance,
        ?WorkflowRun $run,
        CommandType $commandType,
        string $reason,
        string $targetScope = 'instance',
        array $attributes = [],
        ?HistoryEventType $historyEventType = null,
        array $historyPayload = [],
    ): WorkflowCommand {
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::record($instance, $run, $this->commandAttributes(array_merge([
            'command_type' => $commandType->value,
            'target_scope' => $targetScope,
            'status' => CommandStatus::Rejected->value,
            'outcome' => $this->rejectionOutcome($reason),
            'payload_codec' => $run?->payload_codec ?? CodecRegistry::defaultCodec(),
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ], $attributes)));

        if ($run instanceof WorkflowRun && $historyEventType instanceof HistoryEventType) {
            WorkflowHistoryEvent::record(
                $run,
                $historyEventType,
                array_filter([
                    'workflow_command_id' => $command->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                ] + $historyPayload, static fn (mixed $value): bool => $value !== null),
                null,
                $command,
            );
        }

        return $command;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, mixed> $commandAttributes
     * @param array<string, list<string>> $validationErrors
     * @return array{0: WorkflowCommand, 1: WorkflowUpdate}
     */
    private function rejectUpdateCommand(
        WorkflowInstance $instance,
        ?WorkflowRun $run,
        string $updateName,
        array $arguments,
        string $reason,
        string $targetScope = 'instance',
        array $commandAttributes = [],
        array $validationErrors = [],
    ): array {
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::record($instance, $run, $this->commandAttributes(array_merge([
            'command_type' => CommandType::Update->value,
            'target_scope' => $targetScope,
            'status' => CommandStatus::Rejected->value,
            'outcome' => $this->rejectionOutcome($reason),
            'payload_codec' => $run?->payload_codec ?? CodecRegistry::defaultCodec(),
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ], $commandAttributes)));

        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run?->id,
            'target_scope' => $command->target_scope,
            'requested_workflow_run_id' => $command->requestedRunId(),
            'resolved_workflow_run_id' => $command->resolvedRunId(),
            'update_name' => $updateName,
            'status' => UpdateStatus::Rejected->value,
            'outcome' => $command->outcome?->value,
            'command_sequence' => $command->command_sequence,
            'payload_codec' => $run?->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => Serializer::serializeWithCodec(
                $run?->payload_codec ?? CodecRegistry::defaultCodec(),
                $arguments
            ),
            'validation_errors' => $validationErrors,
            'rejection_reason' => $reason,
            'rejected_at' => $command->rejected_at,
            'closed_at' => $command->rejected_at,
        ]);

        if ($run instanceof WorkflowRun) {
            WorkflowHistoryEvent::record(
                $run,
                HistoryEventType::UpdateRejected,
                array_filter([
                    'workflow_command_id' => $command->id,
                    'update_id' => $update->id,
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'update_name' => $updateName,
                    'arguments' => Serializer::serializeWithCodec(
                        $run->payload_codec ?? CodecRegistry::defaultCodec(),
                        $arguments
                    ),
                    'validation_errors' => $validationErrors,
                ], static fn (mixed $value): bool => $value !== null && $value !== []),
                null,
                $command,
            );
        }

        return [$command, $update];
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function recordRejectedUpdate(
        WorkflowCommand $command,
        string $updateName,
        array $arguments,
    ): WorkflowUpdate {
        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $command->workflow_instance_id,
            'workflow_run_id' => $command->workflow_run_id,
            'target_scope' => $command->target_scope,
            'requested_workflow_run_id' => $command->requestedRunId(),
            'resolved_workflow_run_id' => $command->resolvedRunId(),
            'update_name' => $updateName,
            'status' => UpdateStatus::Rejected->value,
            'outcome' => $command->outcome?->value,
            'command_sequence' => $command->command_sequence,
            'payload_codec' => $command->payload_codec,
            'arguments' => Serializer::serializeWithCodec($command->payload_codec, $arguments),
            'validation_errors' => $command->validationErrors(),
            'rejection_reason' => $command->rejection_reason,
            'rejected_at' => $command->rejected_at,
            'closed_at' => $command->rejected_at,
        ]);

        return $update;
    }

    /**
     * @param array<int|string, mixed> $arguments
     */
    private function recordAcceptedSignal(
        WorkflowInstance $instance,
        WorkflowRun $run,
        WorkflowCommand $command,
        string $name,
        array $arguments,
        string $signalWaitId,
        ?string $payloadCodec = null,
        ?string $payloadBlob = null,
    ): WorkflowSignal {
        $codec = $payloadCodec ?? $run->payload_codec ?? CodecRegistry::defaultCodec();
        $serializedArguments = $payloadBlob ?? Serializer::serializeWithCodec($codec, $arguments);
        StructuralLimits::guardPayloadSize($serializedArguments);

        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => $command->target_scope,
            'requested_workflow_run_id' => $command->requestedRunId(),
            'resolved_workflow_run_id' => $command->resolvedRunId(),
            'signal_name' => $name,
            'signal_wait_id' => $signalWaitId,
            'status' => SignalStatus::Received->value,
            'outcome' => $command->outcome?->value,
            'command_sequence' => $command->command_sequence,
            'payload_codec' => $codec,
            'arguments' => $serializedArguments,
            'received_at' => $command->accepted_at,
        ]);

        return $signal;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     */
    private function recordRejectedSignal(
        WorkflowCommand $command,
        string $name,
        array $arguments,
        array $validationErrors = [],
    ): WorkflowSignal {
        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $command->workflow_instance_id,
            'workflow_run_id' => $command->workflow_run_id,
            'target_scope' => $command->target_scope,
            'requested_workflow_run_id' => $command->requestedRunId(),
            'resolved_workflow_run_id' => $command->resolvedRunId(),
            'signal_name' => $name,
            'status' => SignalStatus::Rejected->value,
            'outcome' => $command->outcome?->value,
            'command_sequence' => $command->command_sequence,
            'payload_codec' => $command->payload_codec,
            'arguments' => Serializer::serializeWithCodec($command->payload_codec, $arguments),
            'validation_errors' => $validationErrors === [] ? $command->validationErrors() : $validationErrors,
            'rejection_reason' => $command->rejection_reason,
            'rejected_at' => $command->rejected_at,
            'closed_at' => $command->rejected_at,
        ]);

        return $signal;
    }

    private function rejectionOutcome(string $reason): ?string
    {
        return match ($reason) {
            'instance_not_started' => CommandOutcome::RejectedNotStarted->value,
            'run_not_active' => CommandOutcome::RejectedNotActive->value,
            'selected_run_not_current' => CommandOutcome::RejectedNotCurrent->value,
            'run_not_closed' => CommandOutcome::RejectedRunNotClosed->value,
            'unknown_signal' => CommandOutcome::RejectedUnknownSignal->value,
            'unknown_update' => CommandOutcome::RejectedUnknownUpdate->value,
            'invalid_signal_arguments' => CommandOutcome::RejectedInvalidArguments->value,
            'invalid_update_arguments' => CommandOutcome::RejectedInvalidArguments->value,
            UpdateCommandGate::BLOCKED_BY_PENDING_SIGNAL => CommandOutcome::RejectedPendingSignal->value,
            WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE => CommandOutcome::RejectedWorkflowDefinitionUnavailable->value,
            default => null,
        };
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     */
    private function rejectSignalCommandForContext(
        CommandContext $commandContext,
        WorkflowInstance $instance,
        ?WorkflowRun $run,
        string $name,
        array $arguments,
        string $reason,
        array $validationErrors = [],
    ): WorkflowCommand {
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::record($instance, $run, $this->commandAttributesForContext(
            $commandContext,
            [
                'command_type' => CommandType::Signal->value,
                'target_scope' => 'instance',
                'status' => CommandStatus::Rejected->value,
                'outcome' => $this->rejectionOutcome($reason),
                'payload_codec' => $run?->payload_codec ?? CodecRegistry::defaultCodec(),
                'rejection_reason' => $reason,
                'rejected_at' => now(),
                ...$this->signalCommandPayloadAttributes($name, $arguments, $validationErrors),
            ],
        ));

        $this->recordRejectedSignal($command, $name, $arguments, $validationErrors);

        return $command;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     * @return array<string, mixed>
     */
    private function signalCommandPayloadAttributes(
        string $name,
        array $arguments,
        array $validationErrors = [],
        ?string $payloadCodec = null,
    ): array {
        $codec = $payloadCodec ?? CodecRegistry::defaultCodec();

        return [
            'payload_codec' => $codec,
            'payload' => Serializer::serializeWithCodec($codec, [
                'name' => $name,
                'arguments' => $arguments,
                'validation_errors' => $validationErrors,
            ]),
        ];
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, list<string>> $validationErrors
     * @return array<string, mixed>
     */
    private function updateCommandPayloadAttributes(
        string $method,
        array $arguments,
        array $validationErrors = [],
    ): array {
        return [
            'payload_codec' => CodecRegistry::defaultCodec(),
            'payload' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), [
                'name' => $method,
                'arguments' => $arguments,
                'validation_errors' => $validationErrors,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadLimitExceededCommandPayloadAttributes(
        string $name,
        string $codec,
        StructuralLimitExceededException $exception,
    ): array {
        return [
            'payload_codec' => $codec,
            'payload' => Serializer::serializeWithCodec($codec, [
                'name' => $name,
                'arguments' => [],
                'validation_errors' => [
                    'arguments' => [$exception->getMessage()],
                ],
                'structural_limit_kind' => $exception->limitKind->value,
                'structural_limit_value' => $exception->currentValue,
                'structural_limit_configured' => $exception->configuredLimit,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function archiveCommandPayloadAttributes(?string $reason, array $attributes = []): array
    {
        return array_merge([
            'payload_codec' => CodecRegistry::defaultCodec(),
            'payload' => Serializer::serializeWithCodec(CodecRegistry::defaultCodec(), [
                'reason' => $reason,
            ]),
        ], $attributes);
    }

    /**
     * @return array{name: string, method: string}
     */
    private function resolveUpdateTargetForRun(WorkflowRun $run, string $target): array
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return [
                'name' => $target,
                'method' => $target,
            ];
        }

        return WorkflowDefinition::resolveUpdateTarget($workflowClass, $target) ?? [
            'name' => $target,
            'method' => $target,
        ];
    }

    /**
     * @return array{name: string, method: string}|null
     */
    private function resolveQueryTargetForRun(WorkflowRun $run, string $target): ?array
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return RunCommandContract::hasQueryMethod($run, $target)
                ? [
                    'name' => $target,
                    'method' => $target,
                ]
                : null;
        }

        return WorkflowDefinition::resolveQueryTarget($workflowClass, $target);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedSignalArgumentsForRun(WorkflowRun $run, string $signalName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
            $contract = RunCommandContract::signalContract($run, $signalName);

            if ($contract === null) {
                try {
                    $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
                } catch (LogicException) {
                    return [
                        'arguments' => $normalized,
                        'validation_errors' => [],
                    ];
                }

                $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);
            }

            return $contract === null
                ? [
                    'arguments' => $normalized,
                    'validation_errors' => [],
                ]
                : $this->normalizePositionalCommandArguments($contract, $arguments, 'signal');
        }

        $contract = RunCommandContract::signalContract($run, $signalName);

        if ($contract !== null) {
            return $this->normalizeNamedCommandArguments($contract, $arguments);
        }

        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return [
                'arguments' => [],
                'validation_errors' => [
                    'arguments' => ['Named arguments require a durable or loadable workflow signal contract.'],
                ],
            ];
        }

        $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);

        return $contract === null
            ? [
                'arguments' => [$arguments],
                'validation_errors' => [],
            ]
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedSignalArgumentsForWorkflow(
        string $workflowClass,
        string $signalName,
        array $arguments,
    ): array {
        $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);

        if ($contract === null) {
            return array_is_list($arguments)
                ? [
                    'arguments' => array_values($arguments),
                    'validation_errors' => [],
                ]
                : [
                    'arguments' => [$arguments],
                    'validation_errors' => [],
                ];
        }

        return array_is_list($arguments)
            ? $this->normalizePositionalCommandArguments($contract, $arguments, 'signal')
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedQueryArgumentsForRun(WorkflowRun $run, string $queryName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
        } else {
            $normalized = [];
        }

        $contract = RunCommandContract::queryContract($run, $queryName);

        if ($contract === null) {
            try {
                $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
            } catch (LogicException) {
                return array_is_list($arguments)
                    ? [
                        'arguments' => $normalized,
                        'validation_errors' => [],
                    ]
                    : [
                        'arguments' => [],
                        'validation_errors' => [
                            'arguments' => ['Named arguments require a durable or loadable workflow query contract.'],
                        ],
                    ];
            }

            $contract = WorkflowDefinition::queryContract($workflowClass, $queryName);
        }

        if ($contract === null) {
            return array_is_list($arguments)
                ? [
                    'arguments' => $normalized,
                    'validation_errors' => [],
                ]
                : [
                    'arguments' => [],
                    'validation_errors' => [
                        'arguments' => ['Named arguments require a durable or loadable workflow query contract.'],
                    ],
                ];
        }

        return array_is_list($arguments)
            ? $this->normalizePositionalCommandArguments($contract, $arguments, 'query')
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedUpdateArgumentsForRun(WorkflowRun $run, string $updateName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
        } else {
            $normalized = [];
        }

        $contract = RunCommandContract::updateContract($run, $updateName);

        if ($contract === null) {
            try {
                $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
            } catch (LogicException) {
                return array_is_list($arguments)
                    ? [
                        'arguments' => $normalized,
                        'validation_errors' => [],
                    ]
                    : [
                        'arguments' => [],
                        'validation_errors' => [
                            'arguments' => ['Named arguments require a durable or loadable workflow update contract.'],
                        ],
                    ];
            }

            $contract = WorkflowDefinition::updateContract($workflowClass, $updateName);
        }

        if ($contract === null) {
            return array_is_list($arguments)
                ? [
                    'arguments' => $normalized,
                    'validation_errors' => [],
                ]
                : [
                    'arguments' => [],
                    'validation_errors' => [
                        'arguments' => ['Named arguments require a durable or loadable workflow update contract.'],
                    ],
                ];
        }

        return array_is_list($arguments)
            ? $this->normalizePositionalCommandArguments($contract, $arguments, 'update')
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @param array<int, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizePositionalCommandArguments(array $contract, array $arguments, string $commandType): array
    {
        $normalized = [];
        $errors = [];
        $providedCount = count($arguments);
        $consumed = 0;

        foreach ($contract['parameters'] as $parameter) {
            if (($parameter['variadic'] ?? false) === true) {
                while ($consumed < $providedCount) {
                    $normalized[] = $arguments[$consumed];
                    $this->appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                    $consumed++;
                }

                continue;
            }

            if ($consumed < $providedCount) {
                $normalized[] = $arguments[$consumed];
                $this->appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                $consumed++;

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$parameter['name']][] = sprintf('The %s argument is required.', $parameter['name']);
            }
        }

        if ($consumed < $providedCount) {
            $errors['arguments'][] = sprintf(
                'Too many arguments were provided for %s [%s].',
                $commandType,
                $contract['name'],
            );
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @param array<string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizeNamedCommandArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $knownParameters = [];

        foreach ($contract['parameters'] as $parameter) {
            $name = $parameter['name'];
            $knownParameters[] = $name;

            if (($parameter['variadic'] ?? false) === true) {
                if (! array_key_exists($name, $arguments)) {
                    continue;
                }

                $values = $arguments[$name];

                if (is_array($values)) {
                    foreach (array_values($values) as $value) {
                        $normalized[] = $value;
                        $this->appendParameterValidationErrors($errors, $parameter, $value);
                    }
                } else {
                    $normalized[] = $values;
                    $this->appendParameterValidationErrors($errors, $parameter, $values);
                }

                continue;
            }

            if (array_key_exists($name, $arguments)) {
                $normalized[] = $arguments[$name];
                $this->appendParameterValidationErrors($errors, $parameter, $arguments[$name]);

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$name][] = sprintf('The %s argument is required.', $name);
            }
        }

        foreach (array_keys($arguments) as $name) {
            if (in_array($name, $knownParameters, true)) {
                continue;
            }

            $errors[(string) $name][] = sprintf('Unknown argument [%s].', (string) $name);
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $parameter
     */
    private function appendParameterValidationErrors(array &$errors, array $parameter, mixed $value): void
    {
        $name = is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : 'argument';

        foreach ($this->validationErrorsForParameterValue($parameter, $value) as $message) {
            $errors[$name][] = $message;
        }
    }

    /**
     * @param array<string, mixed> $parameter
     * @return list<string>
     */
    private function validationErrorsForParameterValue(array $parameter, mixed $value): array
    {
        $name = is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : 'argument';

        if ($value === null) {
            return $this->parameterAllowsNull($parameter)
                ? []
                : [sprintf('The %s argument cannot be null.', $name)];
        }

        $type = is_string($parameter['type'] ?? null)
            ? trim($parameter['type'])
            : null;

        if ($type === null || $type === '' || $type === 'mixed') {
            return [];
        }

        if ($this->valueMatchesDeclaredType($value, $type)) {
            return [];
        }

        return [sprintf('The %s argument must be of type %s.', $name, $type)];
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private function parameterAllowsNull(array $parameter): bool
    {
        if (is_bool($parameter['allows_null'] ?? null)) {
            return $parameter['allows_null'];
        }

        $type = is_string($parameter['type'] ?? null)
            ? trim($parameter['type'])
            : null;

        if ($type === null || $type === '') {
            return true;
        }

        return str_starts_with($type, '?')
            || in_array('null', $this->splitDeclaredType($type, '|'), true);
    }

    private function valueMatchesDeclaredType(mixed $value, string $type): bool
    {
        $type = trim($type);

        if ($type === '' || $type === 'mixed') {
            return true;
        }

        if (str_starts_with($type, '?')) {
            return $value === null || $this->valueMatchesDeclaredType($value, substr($type, 1));
        }

        $unionTypes = $this->splitDeclaredType($type, '|');

        if (count($unionTypes) > 1) {
            foreach ($unionTypes as $unionType) {
                if ($this->valueMatchesDeclaredType($value, $unionType)) {
                    return true;
                }
            }

            return false;
        }

        $intersectionTypes = $this->splitDeclaredType($type, '&');

        if (count($intersectionTypes) > 1) {
            foreach ($intersectionTypes as $intersectionType) {
                if (! $this->valueMatchesDeclaredType($value, $intersectionType)) {
                    return false;
                }
            }

            return true;
        }

        $type = trim($type, "() \t\n\r\0\x0B");

        return match ($type) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            'scalar' => is_scalar($value),
            'true' => $value === true,
            'false' => $value === false,
            'null' => $value === null,
            'mixed' => true,
            'never', 'void' => false,
            'self', 'static', 'parent' => is_object($value),
            default => is_object($value)
                && (
                    ! class_exists($type)
                    && ! interface_exists($type)
                    && ! enum_exists($type)
                    || $value instanceof $type
                ),
        };
    }

    /**
     * @return list<string>
     */
    private function splitDeclaredType(string $type, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($index = 0, $length = strlen($type); $index < $length; $index++) {
            $character = $type[$index];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
            }

            if ($character === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function commandAttributesForContext(CommandContext $commandContext, array $attributes): array
    {
        return array_merge($commandContext->attributes(), $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function commandAttributes(array $attributes): array
    {
        return array_merge($this->resolvedCommandContext()->attributes(), $attributes);
    }

    private function signalWithStartCommandContext(string $intakeGroupId): CommandContext
    {
        return $this->resolvedCommandContext()
            ->withIntake('signal_with_start', $intakeGroupId);
    }

    private function currentRunForInstance(
        WorkflowInstance $instance,
        bool $lockForUpdate = false,
    ): ?WorkflowRun {
        $run = CurrentRunResolver::forInstance($instance, lockForUpdate: $lockForUpdate);

        if ($lockForUpdate) {
            CurrentRunResolver::syncPointer($instance, $run);
        }

        return $run;
    }

    private function resolvedCommandContext(): CommandContext
    {
        return $this->commandContext ?? CommandContext::phpApi();
    }

    private function hasOpenWorkflowTask(string $runId): bool
    {
        return self::taskQuery()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();
    }

    private function runIsActive(WorkflowRun $run): bool
    {
        return in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);
    }

    private function readyWorkflowTaskForDispatch(string $runId): ?WorkflowTask
    {
        /** @var WorkflowTask|null $task */
        $task = self::taskQuery()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('available_at')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        return $task;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mergeWorkflowTaskPayload(WorkflowTask $task, array $payload): WorkflowTask
    {
        $existing = is_array($task->payload) ? $task->payload : [];
        $existingWaitKind = is_string($existing['workflow_wait_kind'] ?? null)
            ? $existing['workflow_wait_kind']
            : null;
        $newWaitKind = is_string($payload['workflow_wait_kind'] ?? null)
            ? $payload['workflow_wait_kind']
            : null;

        if (
            $existingWaitKind !== null
            && $newWaitKind !== null
            && $existingWaitKind !== $newWaitKind
        ) {
            return $task;
        }

        $task->forceFill([
            'payload' => array_filter(
                array_merge($existing, $payload),
                static fn (mixed $value): bool => $value !== null,
            ),
        ])->save();

        return $task->fresh() ?? $task;
    }

    private function loadLockedRunRelations(WorkflowRun $run, WorkflowInstance $instance): void
    {
        $run->setRelation('instance', $instance);
        $run->setRelation(
            'tasks',
            self::taskQuery()
                ->where('workflow_run_id', $run->id)
                ->orderBy('available_at')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'activityExecutions',
            ActivityExecution::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'timers',
            WorkflowTimer::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'failures',
            WorkflowFailure::query()
                ->where('workflow_run_id', $run->id)
                ->latest('created_at')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'commands',
            WorkflowCommand::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('command_sequence')
                ->oldest('created_at')
                ->oldest('id')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'historyEvents',
            WorkflowHistoryEvent::query()
                ->where('workflow_run_id', $run->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get()
        );
        $run->setRelation(
            'childLinks',
            \Workflow\V2\Models\WorkflowLink::query()
                ->where('parent_workflow_run_id', $run->id)
                ->oldest('created_at')
                ->lockForUpdate()
                ->get()
        );
    }

    private function signalWaitIdForAcceptedCommand(WorkflowRun $run, string $name, string $commandId): string
    {
        $hasEarlierPendingSignal = $run->commands->contains(
            static fn (WorkflowCommand $command): bool => $command->command_type === CommandType::Signal
                && $command->status === CommandStatus::Accepted
                && $command->applied_at === null
                && $command->targetName() === $name
        );

        if ($hasEarlierPendingSignal) {
            return SignalWaits::bufferedWaitIdForCommandId($commandId);
        }

        return SignalWaits::openWaitIdForName($run, $name)
            ?? SignalWaits::bufferedWaitIdForCommandId($commandId);
    }

    private function commandTargetScope(): string
    {
        return $this->runTargeted ? 'run' : 'instance';
    }

    /**
     * @return list<WorkflowTask>
     */
    private function createParentResumeTasks(WorkflowRun $childRun): array
    {
        $tasks = [];

        $parentLinks = \Workflow\V2\Models\WorkflowLink::query()
            ->where('child_workflow_run_id', $childRun->id)
            ->where('link_type', 'child_workflow')
            ->lockForUpdate()
            ->get();

        /** @var array<string, array{parent_workflow_run_id: string, parent_sequence: int|null}> $parentReferences */
        $parentReferences = [];

        foreach ($parentLinks as $parentLink) {
            if (! is_string($parentLink->parent_workflow_run_id) || $parentLink->parent_workflow_run_id === '') {
                continue;
            }

            $parentReferences[$parentLink->parent_workflow_run_id] = [
                'parent_workflow_run_id' => $parentLink->parent_workflow_run_id,
                'parent_sequence' => is_int($parentLink->sequence)
                    ? $parentLink->sequence
                    : ($parentReferences[$parentLink->parent_workflow_run_id]['parent_sequence'] ?? null),
            ];
        }

        if ($parentReferences === []) {
            $parentReference = ChildRunHistory::parentReferenceForRun($childRun);

            if ($parentReference !== null) {
                $parentReferences[$parentReference['parent_workflow_run_id']] = $parentReference;
            }
        }

        foreach ($parentReferences as $parentReference) {
            /** @var WorkflowRun|null $parentRun */
            $parentRun = self::runQuery()
                ->lockForUpdate()
                ->find($parentReference['parent_workflow_run_id']);

            if ($parentRun === null || in_array($parentRun->status, [
                RunStatus::Completed,
                RunStatus::Failed,
                RunStatus::Cancelled,
                RunStatus::Terminated,
            ], true)) {
                continue;
            }

            /** @var WorkflowInstance|null $parentInstance */
            $parentInstance = self::instanceQuery()
                ->lockForUpdate()
                ->find($parentRun->workflow_instance_id);

            if ($parentInstance instanceof WorkflowInstance) {
                $this->loadLockedRunRelations($parentRun, $parentInstance);
            }

            if (is_int($parentReference['parent_sequence'])) {
                $parallelMetadataPath = ParallelChildGroup::metadataPathForSequence(
                    $parentRun,
                    $parentReference['parent_sequence']
                );
                $childStatus = ChildRunHistory::resolvedStatus(
                    ChildRunHistory::resolutionEventForSequence($parentRun, $parentReference['parent_sequence']),
                    $childRun,
                );

                if (
                    $parallelMetadataPath !== []
                    && $childStatus instanceof RunStatus
                    && ! ParallelChildGroup::shouldWakeParentOnChildClosure(
                        $parentRun,
                        $parallelMetadataPath,
                        $childStatus
                    )
                ) {
                    RunSummaryProjector::project(
                        $parentRun->fresh([
                            'instance',
                            'tasks',
                            'activityExecutions',
                            'timers',
                            'failures',
                            'historyEvents',
                            'childLinks.childRun.instance.currentRun',
                            'childLinks.childRun.failures',
                        ])
                    );

                    continue;
                }
            }

            $hasOpenWorkflowTask = self::taskQuery()
                ->where('workflow_run_id', $parentRun->id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->exists();

            if ($hasOpenWorkflowTask) {
                continue;
            }

            /** @var WorkflowTask $task */
            $task = self::taskQuery()->create([
                'workflow_run_id' => $parentRun->id,
                'namespace' => $parentRun->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'available_at' => now(),
                'payload' => [],
                'connection' => $parentRun->connection,
                'queue' => $parentRun->queue,
                'compatibility' => $parentRun->compatibility,
            ]);

            RunSummaryProjector::project(
                $parentRun->fresh([
                    'instance',
                    'tasks',
                    'activityExecutions',
                    'timers',
                    'failures',
                    'historyEvents',
                    'childLinks.childRun.instance.currentRun',
                    'childLinks.childRun.failures',
                ])
            );

            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * @param class-string<Workflow> $workflow
     */
    private static function reserveCallerSuppliedInstance(
        string $workflow,
        string $workflowType,
        string $instanceId,
    ): WorkflowInstance {
        $now = now();

        self::instanceQuery()->insertOrIgnore([
            'id' => $instanceId,
            'workflow_class' => $workflow,
            'workflow_type' => $workflowType,
            'reserved_at' => $now,
            'run_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var WorkflowInstance $instance */
        $instance = self::instanceQuery()
            ->with('currentRun')
            ->findOrFail($instanceId);

        if ($instance->workflow_type !== $workflowType) {
            throw new LogicException(sprintf(
                'Workflow instance [%s] is reserved for durable type [%s] and cannot be reused for [%s].',
                $instanceId,
                $instance->workflow_type,
                $workflowType,
            ));
        }

        if ($instance->workflow_class !== $workflow) {
            $instance->forceFill([
                'workflow_class' => $workflow,
            ])->save();
        }

        return $instance;
    }

    private static function instanceQuery()
    {
        return ConfiguredV2Models::query('instance_model', WorkflowInstance::class);
    }

    private static function runQuery()
    {
        return ConfiguredV2Models::query('run_model', WorkflowRun::class);
    }

    private static function taskQuery()
    {
        return ConfiguredV2Models::query('task_model', WorkflowTask::class);
    }
}
