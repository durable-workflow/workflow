<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\Exceptions\DurableOperationCancelledException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

/**
 * Internal cold-replay executor for the embedded engine task bridge.
 *
 * Pass bridge `history_events` when constructing a cold runner; recorded
 * activity, timer, child-workflow, durable group/selection, side-effect,
 * version marker, memo, and search-attribute outcomes are replayed into the
 * Fiber before new commands are emitted.
 *
 * @internal
 */
final class WorkflowFiberRunner
{
    private ?WorkflowExecution $execution = null;

    private int $sequence = 1;

    /**
     * @var array<int, int>
     */
    private array $historySequencesByPosition = [];

    private ?YieldedCommand $pendingYielded = null;

    private bool $waitingForHistory = false;

    private ?CarbonInterface $startedAt = null;

    /**
     * @var array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private array $recordedActivityOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openActivityWaits = [];

    /**
     * @var array<int, true>
     */
    private array $localActivitySequences = [];

    /**
     * @var array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private array $recordedTimerOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openTimerWaits = [];

    /**
     * @var array<int, array{result: bool, recorded_at: CarbonInterface|null}>
     */
    private array $recordedConditionOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openConditionWaits = [];

    /**
     * @var array<int, array{signal_name: string, result: mixed, recorded_at: CarbonInterface|null}>
     */
    private array $recordedSignalOutcomes = [];

    /**
     * @var array<int, array{signal_name: string, signal_wait_id: string|null}>
     */
    private array $openSignalWaits = [];

    /**
     * @var array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private array $recordedChildOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openChildWaits = [];

    /**
     * @var array<int, array{status: string, result?: ServiceOperationResult, exception?: Throwable, recorded_at: CarbonInterface|null, admission_visible?: bool}>
     */
    private array $recordedServiceOperationOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openServiceOperationWaits = [];

    /**
     * @var array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private array $recordedSideEffects = [];

    /**
     * @var array<int, WorkflowHistoryEvent>
     */
    private array $recordedVersionMarkers = [];

    /**
     * @var array<int, array{entries: mixed, recorded_at: CarbonInterface|null}>
     */
    private array $recordedMemoUpserts = [];

    /**
     * @var array<int, array{payload: array<string, mixed>, recorded_at: CarbonInterface|null}>
     */
    private array $recordedSearchAttributeUpserts = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $historyEvents = [];

    /**
     * @var array<string, true>
     */
    private array $appliedUpdateEvents = [];

    private bool $hasReplayHistory = false;

    private ?string $namespace = null;

    /**
     * @param array<int, mixed> $arguments
     * @param list<array<string, mixed>> $historyEvents
     */
    public function __construct(
        private readonly Workflow $workflow,
        private readonly array $arguments,
        private readonly string $payloadCodec = 'avro',
        array $historyEvents = [],
        ?string $namespace = null,
    ) {
        $this->namespace = self::stringValue($namespace)
            ?? self::stringValue($this->workflow->run->namespace ?? null);
        $this->loadHistoryEvents($historyEvents);
    }

    /**
     * Build a runner for an engine-hosted workflow class.
     *
     * The bridge owns the durable run row, so this helper passes a transient
     * in-memory WorkflowRun with the engine-assigned identity.
     *
     * @param class-string<Workflow> $workflowClass
     * @param array<int, mixed> $arguments
     * @param list<array<string, mixed>> $historyEvents
     */
    public static function forClass(
        string $workflowClass,
        string $workflowId,
        string $runId,
        array $arguments,
        string $payloadCodec = 'avro',
        array $historyEvents = [],
        ?string $namespace = null,
    ): self {
        $run = new WorkflowRun();
        $run->id = $runId;
        $run->workflow_instance_id = $workflowId;
        $run->workflow_class = $workflowClass;
        $run->workflow_type = $workflowClass;
        $run->payload_codec = $payloadCodec;
        $run->namespace = $namespace;

        $workflow = RuntimeObjectFactory::workflow($workflowClass, $run);

        if (! $workflow instanceof Workflow) {
            throw new RuntimeException(sprintf(
                'Workflow engine runner can only host Workflow\\V2\\Workflow subclasses; got %s.',
                $workflowClass,
            ));
        }

        return new self($workflow, $arguments, $payloadCodec, $historyEvents, $namespace);
    }

    /**
     * Advance the workflow until the next suspension or completion.
     */
    public function step(mixed $resumeWith = null): WorkflowStep
    {
        $hasExplicitResume = func_num_args() > 0;

        if ($this->execution === null) {
            $this->execution = WorkflowExecution::start($this->workflow, $this->arguments, $this->startedAt);
        } else {
            if ($this->waitingForHistory) {
                $this->waitingForHistory = false;
            } elseif (! $hasExplicitResume && $this->pendingYielded instanceof YieldedCommand) {
                if ($this->historyAvailableForPendingYielded()) {
                    $this->pendingYielded = null;
                } else {
                    return WorkflowStep::waiting($this->pendingYielded);
                }
            } else {
                if ($this->pendingYielded instanceof YieldedCommand) {
                    $this->sequence += $this->pendingYielded instanceof AllCall
                        ? $this->pendingYielded->leafCount()
                        : 1;
                    $this->pendingYielded = null;
                }

                $this->execution->send($resumeWith);
            }
        }

        return $this->nextObservableStep();
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     */
    public function withHistoryEvents(array $historyEvents): self
    {
        $this->loadHistoryEvents($historyEvents);

        return $this;
    }

    public function applyUpdate(string $updateId, ?string $updateName = null): WorkflowStep
    {
        if ($updateId === '') {
            throw new LogicException('Workflow update task field [workflow_update_id] must be non-empty.');
        }

        $this->step();

        try {
            $result = $this->invokeUpdateHandler($updateId, $updateName);
        } catch (Throwable $throwable) {
            return WorkflowStep::failUpdate(
                $updateId,
                $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Workflow update execution failed.',
                $throwable::class,
                $throwable::class,
            );
        }

        return WorkflowStep::completeUpdate($updateId, $result, $this->payloadCodec);
    }

    private function nextObservableStep(): WorkflowStep
    {
        $immediateCommands = [];

        while (true) {
            if (! $this->execution instanceof WorkflowExecution) {
                throw new RuntimeException('Workflow execution has not been started.');
            }

            if (! $this->execution->valid()) {
                return WorkflowStep::completed($this->execution->getReturn(), $this->payloadCodec)
                    ->withPrependedCommands($immediateCommands);
            }

            $current = $this->execution->current();

            if (! $current instanceof YieldedCommand) {
                throw new UnsupportedWorkflowYieldException(sprintf(
                    'Worker protocol runner received an unsupported workflow suspension of type %s.',
                    get_debug_type($current),
                ));
            }

            $this->applyRecordedUpdatesForCurrentPosition();

            if ($current instanceof CancelDurableOperationCall) {
                $handle = $current->handle;
                $cancelled = ParallelChildGroup::cancellationForHandle($this->workflow->run, $handle);

                if (! $cancelled instanceof WorkflowHistoryEvent && ! ParallelChildGroup::selectionMemberIsTerminal(
                    $this->workflow->run,
                    $handle->baseSequence,
                    $handle->size,
                    $handle->kind,
                )) {
                    $immediateCommands[] = self::singleCommand(
                        WorkflowStep::yielded($current, $this->payloadCodec)
                    );
                }

                $this->execution->send(null, $cancelled?->recorded_at);

                continue;
            }

            if ($current instanceof DurableOperationHandle) {
                $resolution = $this->durableOperationResolution($current);

                if (! $resolution['resolved']) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }

                if ($resolution['failure'] instanceof Throwable) {
                    $this->execution->throw($resolution['failure'], $resolution['recorded_at']);
                } else {
                    $this->execution->send($resolution['value'], $resolution['recorded_at']);
                }

                continue;
            }

            if ($current instanceof AllCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $baseSequence = $historySequence
                    ?? ($this->hasReplayHistory ? $this->nextDurableCommandSequence() : $this->sequence);
                $leafDescriptors = $current->leafDescriptors($baseSequence);
                $groupSize = count($leafDescriptors);

                if ($groupSize === 0) {
                    $this->execution->send($current->nestedResults([]));

                    continue;
                }

                if (! $this->hasReplayHistory || $historySequence === null) {
                    $this->pendingYielded = $current;

                    return WorkflowStep::group($current, $baseSequence, $this->payloadCodec)
                        ->withPrependedCommands($immediateCommands);
                }

                $this->assertDurableGroupHistory($baseSequence, $leafDescriptors);

                $results = [];
                $failures = [];
                $pending = false;
                $latest = null;

                foreach ($leafDescriptors as $descriptor) {
                    $call = $descriptor['call'];
                    $offset = $descriptor['offset'];
                    $itemSequence = $baseSequence + $offset;
                    $resolution = $this->durableLeafResolution($call, $itemSequence, '');

                    if ($resolution['failure'] instanceof Throwable) {
                        $failures[$offset] = $resolution['failure'];

                        continue;
                    }

                    if (! $resolution['resolved']) {
                        $pending = true;

                        continue;
                    }

                    $results[$offset] = $resolution['value'];
                    $latest = self::latestTime($latest, $resolution['recorded_at']);
                }

                if ($current instanceof SelectCall) {
                    $groupId = $leafDescriptors[0]['group_path'][0]['parallel_group_id'];
                    $selection = ParallelChildGroup::selectionResolution($this->workflow->run, $groupId);

                    if ($selection instanceof WorkflowHistoryEvent) {
                        $winner = ParallelChildGroup::validatedSelectionResolution(
                            $this->workflow->run,
                            $current,
                            $baseSequence,
                            $selection,
                        );
                        $this->sequence += $groupSize;
                        $this->execution->send(
                            $current->resolved(
                                $baseSequence,
                                $winner,
                                $results,
                                $failures,
                                ParallelChildGroup::durableOperationIdentities($this->workflow->run),
                            ),
                            $selection->recorded_at,
                        );

                        continue;
                    }

                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
                $failure = $this->durableGroupFailureResolution($current, $baseSequence, $groupSize);

                if ($failure !== null) {
                    $this->sequence += $groupSize;
                    $this->execution->throw($failure['failure'], $failure['recorded_at']);

                    continue;
                }

                if ($pending || $failures !== []) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }

                ksort($results);
                $this->sequence += $groupSize;
                $this->execution->send($current->nestedResults(array_values($results)), $latest);

                continue;
            }

            if ($current instanceof SideEffectCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedSideEffects[$historySequence] ?? null);

                if ($recorded !== null) {
                    ++$this->sequence;
                    $this->execution->send($recorded['result'], $recorded['recorded_at']);

                    continue;
                }

                $result = ($current->callback)();
                $step = WorkflowStep::recordSideEffect($current, $result, $this->payloadCodec);
                $immediateCommands[] = self::singleCommand($step);

                ++$this->sequence;
                $this->execution->send($result);

                continue;
            }

            if ($current instanceof VersionCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $versionMarkerEvent = $historySequence === null
                    ? null
                    : ($this->recordedVersionMarkers[$historySequence] ?? null);
                $resolution = $this->resolveVersion(
                    $current,
                    $versionMarkerEvent,
                    $historySequence ?? $this->sequence,
                );

                if ($resolution->shouldRecordMarker) {
                    $step = WorkflowStep::yielded($current, $this->payloadCodec);
                    $immediateCommands[] = self::singleCommand($step);
                }

                if ($resolution->advancesSequence) {
                    ++$this->sequence;
                }

                $this->execution->send(
                    $current->resolveValue($resolution->version),
                    $versionMarkerEvent?->recorded_at,
                );

                continue;
            }

            if ($current instanceof UpsertMemoCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedMemoUpserts[$historySequence] ?? null);

                if ($recorded !== null) {
                    MemoReplayIdentity::assertCompatible(
                        $historySequence,
                        $recorded['entries'],
                        $current->entries,
                    );

                    ++$this->sequence;
                    $this->execution->send(null, $recorded['recorded_at']);

                    continue;
                }

                $step = WorkflowStep::yielded($current, $this->payloadCodec);
                $immediateCommands[] = self::singleCommand($step);

                ++$this->sequence;
                $this->execution->send(null);

                continue;
            }

            if ($current instanceof UpsertSearchAttributesCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedSearchAttributeUpserts[$historySequence] ?? null);

                if ($recorded !== null) {
                    SearchAttributeReplayIdentity::assertCompatible(
                        $historySequence,
                        $recorded['payload'],
                        $current,
                    );

                    ++$this->sequence;
                    $this->execution->send(null, $recorded['recorded_at']);

                    continue;
                }

                $step = WorkflowStep::yielded($current, $this->payloadCodec);
                $immediateCommands[] = self::singleCommand($step);

                ++$this->sequence;
                $this->execution->send(null);

                continue;
            }

            if ($current instanceof LocalActivityCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                if ($historySequence !== null) {
                    $this->assertActivityExecutionMode($historySequence, true);
                }
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedActivityOutcomes[$historySequence] ?? null);

                if ($recorded !== null) {
                    ++$this->sequence;
                    if ($recorded['status'] === 'completed') {
                        $this->execution->send($recorded['result'] ?? null, $recorded['recorded_at']);
                    } else {
                        $this->execution->throw(
                            $recorded['exception'] ?? new RuntimeException('Local activity failed during replay.'),
                            $recorded['recorded_at'],
                        );
                    }

                    continue;
                }

                if ($historySequence !== null && isset($this->openActivityWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof ActivityCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                if ($historySequence !== null) {
                    $this->assertActivityExecutionMode($historySequence, false);
                }
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedActivityOutcomes[$historySequence] ?? null);

                if ($recorded !== null) {
                    ++$this->sequence;
                    if ($recorded['status'] === 'completed') {
                        $this->execution->send($recorded['result'] ?? null, $recorded['recorded_at']);
                    } else {
                        $this->execution->throw(
                            $recorded['exception'] ?? new RuntimeException('Activity failed during replay.'),
                            $recorded['recorded_at'],
                        );
                    }

                    continue;
                }

                if ($historySequence !== null && isset($this->openActivityWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof TimerCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedTimerOutcomes[$historySequence] ?? null);

                if ($recorded !== null) {
                    ++$this->sequence;
                    $this->execution->send($recorded['result'], $recorded['recorded_at']);

                    continue;
                }

                if ($historySequence !== null && isset($this->openTimerWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof AwaitCall || $current instanceof AwaitWithTimeoutCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedConditionOutcomes[$historySequence] ?? null);

                if ($recorded !== null) {
                    ++$this->sequence;
                    $this->execution->send($recorded['result'], $recorded['recorded_at']);

                    continue;
                }

                if ($historySequence !== null && isset($this->openConditionWaits[$historySequence])) {
                    if (self::conditionSatisfied($current)) {
                        ++$this->sequence;
                        $this->execution->send(true);

                        continue;
                    }

                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }

                if (self::conditionSatisfied($current)) {
                    $this->execution->send(true);

                    continue;
                }

                if ($current instanceof AwaitWithTimeoutCall && $current->seconds === 0) {
                    $this->execution->send(false);

                    continue;
                }
            }

            if ($current instanceof SignalCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedSignalOutcomes[$historySequence] ?? null);

                if ($recorded !== null && $recorded['signal_name'] === $current->name) {
                    ++$this->sequence;
                    $this->execution->send($recorded['result'], $recorded['recorded_at']);

                    continue;
                }

                $open = $historySequence === null ? null : ($this->openSignalWaits[$historySequence] ?? null);

                if ($open !== null && $open['signal_name'] === $current->name) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof ServiceOperationCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedServiceOperationOutcomes[$historySequence] ?? null);

                if ($recorded !== null) {
                    if (
                        $recorded['status'] === 'started'
                        && ! self::serviceOperationStartedOutcomeIsVisible($recorded, $current)
                    ) {
                        $this->waitingForHistory = true;

                        return WorkflowStep::waiting($current)
                            ->withPrependedCommands($immediateCommands);
                    }

                    ++$this->sequence;
                    if (isset($recorded['exception'])) {
                        $this->execution->throw($recorded['exception'], $recorded['recorded_at']);
                    } else {
                        $this->execution->send($recorded['result'] ?? null, $recorded['recorded_at']);
                    }

                    continue;
                }

                if ($historySequence !== null && isset($this->openServiceOperationWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof ChildWorkflowCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $historySequence === null
                    ? null
                    : ($this->recordedChildOutcomes[$historySequence] ?? null);

                if ($recorded !== null) {
                    ++$this->sequence;
                    if ($recorded['status'] === 'completed') {
                        $this->execution->send($recorded['result'] ?? null, $recorded['recorded_at']);
                    } else {
                        $this->execution->throw(
                            $recorded['exception'] ?? new RuntimeException('Child workflow failed during replay.'),
                            $recorded['recorded_at'],
                        );
                    }

                    continue;
                }

                if ($historySequence !== null && isset($this->openChildWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            $this->pendingYielded = $current;

            return WorkflowStep::yielded($current, $this->payloadCodec)
                ->withPrependedCommands($immediateCommands);
        }
    }

    /**
     * @param list<array{
     *     call: ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall,
     *     offset: int,
     *     result_path: list<int>,
     *     group_path: list<array<string, mixed>>
     * }> $leafDescriptors
     */
    private function assertDurableGroupHistory(int $baseSequence, array $leafDescriptors): void
    {
        WorkflowStepHistory::assertParallelGroupCompatible($this->workflow->run, $baseSequence, $leafDescriptors);

        $authoredLeaves = 0;

        foreach ($leafDescriptors as $descriptor) {
            $call = $descriptor['call'];
            $sequence = $baseSequence + $descriptor['offset'];

            if (ParallelChildGroup::metadataPathForSequence($this->workflow->run, $sequence) !== []) {
                ++$authoredLeaves;
            }

            if ($call instanceof LocalActivityCall) {
                $this->assertActivityExecutionMode($sequence, true);
            } elseif ($call instanceof ActivityCall) {
                $this->assertActivityExecutionMode($sequence, false);
            }

            WorkflowStepHistory::assertCompatible(
                $this->workflow->run,
                $sequence,
                self::shapeForDurableLeaf($call),
                $call instanceof ActivityCall
                    ? [
                        'activity_type' => $call->activity,
                    ]
                    : ($call instanceof ChildWorkflowCall
                        ? [
                            'child_workflow_type' => $call->workflow,
                        ]
                        : []),
            );
        }

        if ($authoredLeaves !== 0 && $authoredLeaves !== count($leafDescriptors)) {
            $sequence = $baseSequence;
            foreach ($leafDescriptors as $descriptor) {
                if (ParallelChildGroup::metadataPathForSequence(
                    $this->workflow->run,
                    $baseSequence + $descriptor['offset'],
                ) === []) {
                    $sequence += $descriptor['offset'];

                    break;
                }
            }

            throw new HistoryEventShapeMismatchException(
                $sequence,
                WorkflowStepHistory::PARALLEL_GROUP,
                ['ParallelGroupTopology'],
                'Persisted durable group history contains only part of the authored leaf set.',
            );
        }
    }

    private static function shapeForDurableLeaf(
        ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall $call,
    ): string {
        return match (true) {
            $call instanceof LocalActivityCall => WorkflowStepHistory::LOCAL_ACTIVITY,
            $call instanceof ActivityCall => WorkflowStepHistory::ACTIVITY,
            $call instanceof TimerCall => WorkflowStepHistory::TIMER,
            $call instanceof SignalCall => WorkflowStepHistory::SIGNAL_WAIT,
            $call instanceof AwaitCall, $call instanceof AwaitWithTimeoutCall => WorkflowStepHistory::CONDITION_WAIT,
            default => WorkflowStepHistory::CHILD_WORKFLOW,
        };
    }

    /**
     * @return array{resolved: bool, value: mixed, failure: Throwable|null, recorded_at: CarbonInterface|null}
     */
    private function durableOperationResolution(DurableOperationHandle $handle): array
    {
        $cancelled = ParallelChildGroup::cancellationForHandle($this->workflow->run, $handle);
        if ($cancelled instanceof WorkflowHistoryEvent) {
            return [
                'resolved' => true,
                'value' => null,
                'failure' => DurableOperationCancelledException::forHandle($handle),
                'recorded_at' => $cancelled->recorded_at,
            ];
        }

        if (! $handle->call instanceof AllCall) {
            return $this->durableLeafResolution($handle->call, $handle->baseSequence, $handle->identity);
        }

        $failure = $this->durableGroupFailureResolution(
            $handle->call,
            $handle->baseSequence,
            $handle->size,
            $handle->identity,
        );
        if ($failure !== null) {
            return $failure;
        }

        $results = [];
        $latest = null;

        foreach ($handle->call->leafDescriptors($handle->baseSequence) as $descriptor) {
            $resolution = $this->durableLeafResolution(
                $descriptor['call'],
                $handle->baseSequence + $descriptor['offset'],
                $handle->identity,
            );
            if ($resolution['failure'] instanceof Throwable || ! $resolution['resolved']) {
                return $resolution;
            }

            $results[$descriptor['offset']] = $resolution['value'];
            $latest = self::latestTime($latest, $resolution['recorded_at']);
        }

        ksort($results);

        return [
            'resolved' => true,
            'value' => $handle->call->nestedResults(array_values($results)),
            'failure' => null,
            'recorded_at' => $latest,
        ];
    }

    /**
     * @return array{resolved: bool, value: mixed, failure: Throwable, recorded_at: CarbonInterface|null}|null
     */
    private function durableGroupFailureResolution(
        AllCall $call,
        int $baseSequence,
        int $size,
        string $identity = '',
    ): ?array {
        $event = ParallelChildGroup::memberFailureResolution($this->workflow->run, $baseSequence, $size);
        if (! $event instanceof WorkflowHistoryEvent) {
            return null;
        }

        $failureSequence = $event->payload['sequence'] ?? null;
        foreach ($call->leafDescriptors($baseSequence) as $descriptor) {
            $sequence = $baseSequence + $descriptor['offset'];
            if ($failureSequence !== $sequence) {
                continue;
            }

            $resolution = $this->durableLeafResolution($descriptor['call'], $sequence, $identity);
            if ($resolution['failure'] instanceof Throwable) {
                return [
                    'resolved' => true,
                    'value' => null,
                    'failure' => $resolution['failure'],
                    'recorded_at' => $resolution['recorded_at'],
                ];
            }

            break;
        }

        throw new HistoryEventShapeMismatchException(
            $baseSequence,
            'the first durable failure for the authored selection member',
            [$event->event_type->value],
            'The terminal failure event does not match a failed durable leaf in the authored member.',
        );
    }

    /**
     * @return array{resolved: bool, value: mixed, failure: Throwable|null, recorded_at: CarbonInterface|null}
     */
    private function durableLeafResolution(
        ActivityCall|ChildWorkflowCall|TimerCall|SignalCall|AwaitCall|AwaitWithTimeoutCall $call,
        int $sequence,
        string $identity,
    ): array {
        if ($call instanceof ActivityCall) {
            $recorded = $this->recordedActivityOutcomes[$sequence] ?? null;
            if ($recorded !== null) {
                return [
                    'resolved' => true,
                    'value' => $recorded['result'] ?? null,
                    'failure' => $recorded['status'] === 'completed'
                        ? null
                        : ($recorded['exception'] ?? new RuntimeException('Activity failed during replay.')),
                    'recorded_at' => $recorded['recorded_at'],
                ];
            }
        } elseif ($call instanceof TimerCall) {
            $recorded = $this->recordedTimerOutcomes[$sequence] ?? null;
            if ($recorded !== null) {
                return [
                    'resolved' => true,
                    'value' => $recorded['result'],
                    'failure' => null,
                    'recorded_at' => $recorded['recorded_at'],
                ];
            }
        } elseif ($call instanceof SignalCall) {
            $recorded = $this->recordedSignalOutcomes[$sequence] ?? null;
            if ($recorded !== null && $recorded['signal_name'] === $call->name) {
                return [
                    'resolved' => true,
                    'value' => $recorded['result'],
                    'failure' => null,
                    'recorded_at' => $recorded['recorded_at'],
                ];
            }
        } elseif ($call instanceof AwaitCall || $call instanceof AwaitWithTimeoutCall) {
            $recorded = $this->recordedConditionOutcomes[$sequence] ?? null;
            if ($recorded !== null) {
                return [
                    'resolved' => true,
                    'value' => $recorded['result'],
                    'failure' => null,
                    'recorded_at' => $recorded['recorded_at'],
                ];
            }
        } else {
            $recorded = $this->recordedChildOutcomes[$sequence] ?? null;
            if ($recorded !== null) {
                return [
                    'resolved' => true,
                    'value' => $recorded['result'] ?? null,
                    'failure' => $recorded['status'] === 'completed'
                        ? null
                        : ($recorded['exception'] ?? new RuntimeException('Child workflow failed during replay.')),
                    'recorded_at' => $recorded['recorded_at'],
                ];
            }
        }

        return [
            'resolved' => false,
            'value' => null,
            'failure' => null,
            'recorded_at' => null,
        ];
    }

    private static function latestTime(?CarbonInterface $current, ?CarbonInterface $candidate): ?CarbonInterface
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }

    private function historyAvailableForPendingYielded(): bool
    {
        $historySequence = $this->historySequenceForCurrentPosition();
        if ($historySequence === null) {
            return false;
        }

        if ($this->pendingYielded instanceof LocalActivityCall) {
            $this->assertActivityExecutionMode($historySequence, true);
        } elseif ($this->pendingYielded instanceof ActivityCall) {
            $this->assertActivityExecutionMode($historySequence, false);
        }

        return match (true) {
            $this->pendingYielded instanceof AllCall => true,
            $this->pendingYielded instanceof LocalActivityCall => isset(
                $this->recordedActivityOutcomes[$historySequence]
            ) || isset($this->openActivityWaits[$historySequence]),
            $this->pendingYielded instanceof ActivityCall => isset($this->recordedActivityOutcomes[$historySequence])
                || isset($this->openActivityWaits[$historySequence]),
            $this->pendingYielded instanceof TimerCall => isset($this->recordedTimerOutcomes[$historySequence])
                || isset($this->openTimerWaits[$historySequence]),
            $this->pendingYielded instanceof AwaitCall || $this->pendingYielded instanceof AwaitWithTimeoutCall => isset($this->recordedConditionOutcomes[$historySequence])
                || isset($this->openConditionWaits[$historySequence]),
            $this->pendingYielded instanceof SignalCall => isset($this->recordedSignalOutcomes[$historySequence])
                || isset($this->openSignalWaits[$historySequence]),
            $this->pendingYielded instanceof ServiceOperationCall => isset($this->recordedServiceOperationOutcomes[$historySequence])
                || isset($this->openServiceOperationWaits[$historySequence]),
            $this->pendingYielded instanceof ChildWorkflowCall => isset($this->recordedChildOutcomes[$historySequence])
                || isset($this->openChildWaits[$historySequence]),
            default => false,
        };
    }

    private function historySequenceForCurrentPosition(): ?int
    {
        if (isset($this->historySequencesByPosition[$this->sequence])) {
            return $this->historySequencesByPosition[$this->sequence];
        }

        return $this->hasReplayHistory ? null : $this->sequence;
    }

    private function nextDurableCommandSequence(): int
    {
        $lastSequence = 0;

        foreach ($this->historyEvents as $event) {
            if (! self::hasWorkflowCommandSequence(self::eventType($event))) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::intValue($payload['sequence'] ?? null)
                ?? self::intValue($payload['workflow_sequence'] ?? null);

            if ($sequence !== null) {
                $lastSequence = max($lastSequence, $sequence);
            }
        }

        return $lastSequence + 1;
    }

    private function applyRecordedUpdatesForCurrentPosition(): void
    {
        $historySequence = $this->historySequenceForCurrentPosition();

        if ($historySequence === null) {
            return;
        }

        foreach ($this->historyEvents as $event) {
            if (self::eventType($event) !== 'UpdateApplied') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if (self::eventSequence($event, $payload) !== $historySequence) {
                continue;
            }

            $identity = self::historyEventIdentity($event, $payload);

            if (isset($this->appliedUpdateEvents[$identity])) {
                continue;
            }

            $this->invokeUpdateHandlerFromEvent($event);
            $this->appliedUpdateEvents[$identity] = true;
        }
    }

    private function invokeUpdateHandler(string $updateId, ?string $updateName): mixed
    {
        $event = $this->acceptedUpdateEvent($updateId, $updateName);

        if ($event === null) {
            throw new LogicException(sprintf('Workflow update [%s] was not found in task history.', $updateId));
        }

        return $this->invokeUpdateHandlerFromEvent($event, $updateName);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function invokeUpdateHandlerFromEvent(array $event, ?string $fallbackUpdateName = null): mixed
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $target = self::stringValue($payload['update_name'] ?? null) ?? $fallbackUpdateName;

        if ($target === null) {
            throw new LogicException('Workflow update history event is missing an update method name.');
        }

        $resolved = WorkflowDefinition::resolveUpdateTarget($this->workflow::class, $target);

        if ($resolved === null) {
            throw new LogicException(sprintf(
                'Workflow update [%s] is not declared on workflow [%s].',
                $target,
                $this->workflow::class,
            ));
        }

        $sequence = $this->updateHandlerSequence($event, $payload);
        $arguments = self::updateArgumentsFromPayload($payload, $this->payloadCodec, $this->namespace);
        $method = new ReflectionMethod($this->workflow, $resolved['method']);
        $parameters = $this->workflow->resolveMethodDependencies($arguments, $method);

        $this->workflow->syncExecutionCursor($sequence);
        $this->workflow->setCommandDispatchEnabled(false);

        try {
            return $this->workflow->{$resolved['method']}(...$parameters);
        } finally {
            $this->workflow->setCommandDispatchEnabled(true);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private function updateHandlerSequence(array $event, array $payload): int
    {
        $payloadSequence = self::intValue($payload['sequence'] ?? null)
            ?? self::intValue($payload['workflow_sequence'] ?? null);

        if ($payloadSequence !== null) {
            return $payloadSequence;
        }

        if (self::eventType($event) === 'UpdateAccepted') {
            return $this->historySequenceForCurrentPosition() ?? $this->sequence;
        }

        return self::eventSequence($event, $payload) ?? $this->sequence;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, mixed>
     */
    private static function updateArgumentsFromPayload(
        array $payload,
        string $fallbackCodec,
        ?string $namespace,
    ): array {
        if (! array_key_exists('arguments', $payload)) {
            return [];
        }

        $decoded = self::decodePayload(
            $payload['arguments'],
            $fallbackCodec,
            self::stringValue($payload['payload_codec'] ?? null),
            $namespace,
        );

        return is_array($decoded) ? array_values($decoded) : [$decoded];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function acceptedUpdateEvent(string $updateId, ?string $updateName): ?array
    {
        foreach (array_reverse($this->historyEvents) as $event) {
            if (self::eventType($event) !== 'UpdateAccepted') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $eventUpdateId = self::stringValue($payload['update_id'] ?? null);
            $eventUpdateName = self::stringValue($payload['update_name'] ?? null);

            if ($eventUpdateId === $updateId) {
                return $event;
            }

            if ($eventUpdateId === null && $updateName !== null && $eventUpdateName === $updateName) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function singleCommand(WorkflowStep $step): array
    {
        if ($step->command === null) {
            throw new LogicException('Workflow step did not produce a worker protocol command.');
        }

        return $step->command;
    }

    private static function conditionSatisfied(AwaitCall|AwaitWithTimeoutCall $call): bool
    {
        return ($call->condition)() === true;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     */
    private function loadHistoryEvents(array $historyEvents): void
    {
        $this->historyEvents = self::normalizedHistoryEvents($historyEvents);
        $this->appliedUpdateEvents = [];
        $this->hasReplayHistory = $this->historyEvents !== [];
        $this->syncRunReplayMetadata($this->historyEvents);
        $this->startedAt = self::workflowStartedAt($this->historyEvents);
        $this->historySequencesByPosition = self::indexHistorySequencesByPosition($this->historyEvents);
        $this->recordedActivityOutcomes = self::indexRecordedActivityOutcomes(
            $this->historyEvents,
            $this->payloadCodec,
            $this->namespace,
        );
        $this->openActivityWaits = array_diff_key(
            self::indexOpenActivityWaits($this->historyEvents),
            $this->recordedActivityOutcomes,
        );
        $this->localActivitySequences = self::indexLocalActivitySequences($this->historyEvents);
        $this->recordedTimerOutcomes = self::indexRecordedTimerOutcomes($this->historyEvents);
        $this->openTimerWaits = array_diff_key(
            self::indexOpenTimerWaits($this->historyEvents),
            $this->recordedTimerOutcomes,
        );
        $this->recordedConditionOutcomes = self::indexRecordedConditionOutcomes($this->historyEvents);
        $this->openConditionWaits = array_diff_key(
            self::indexOpenConditionWaits($this->historyEvents),
            $this->recordedConditionOutcomes,
        );
        $this->recordedSignalOutcomes = self::indexRecordedSignalOutcomes(
            $this->historyEvents,
            $this->payloadCodec,
            $this->namespace,
        );
        $this->openSignalWaits = array_diff_key(
            self::indexOpenSignalWaits($this->historyEvents),
            $this->recordedSignalOutcomes,
        );
        $this->recordedChildOutcomes = self::indexRecordedChildOutcomes(
            $this->historyEvents,
            $this->payloadCodec,
            $this->namespace,
        );
        $this->openChildWaits = array_diff_key(
            self::indexOpenChildWaits($this->historyEvents),
            $this->recordedChildOutcomes,
        );
        $this->recordedServiceOperationOutcomes = self::indexRecordedServiceOperationOutcomes(
            $this->historyEvents,
            $this->payloadCodec,
            $this->namespace,
        );
        $this->openServiceOperationWaits = array_diff_key(
            self::indexOpenServiceOperationWaits($this->historyEvents),
            array_filter(
                $this->recordedServiceOperationOutcomes,
                static fn (array $outcome): bool => ($outcome['status'] ?? null) !== 'started',
            ),
        );
        $this->recordedSideEffects = self::indexRecordedSideEffects(
            $this->historyEvents,
            $this->payloadCodec,
            $this->namespace,
        );
        $this->recordedVersionMarkers = self::indexRecordedVersionMarkers($this->historyEvents);
        $this->recordedMemoUpserts = self::indexRecordedMemoUpserts($this->historyEvents);
        $this->recordedSearchAttributeUpserts = self::indexRecordedSearchAttributeUpserts($this->historyEvents);
    }

    private function resolveVersion(
        VersionCall $versionCall,
        ?WorkflowHistoryEvent $event,
        int $sequence,
    ): VersionResolution {
        if ($event === null && ! $this->hasReplayHistory) {
            return VersionResolution::fresh($versionCall->maxSupported);
        }

        return VersionResolver::resolve($this->workflow->run, $event, $versionCall, $sequence);
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     */
    private function syncRunReplayMetadata(array $historyEvents): void
    {
        $run = $this->workflow->run;
        $startedPayload = self::workflowStartedPayload($historyEvents);
        $workflowClass = $this->workflow::class;
        $workflowType = self::stringValue($startedPayload['workflow_type'] ?? null)
            ?? self::stringValue($run->workflow_type ?? null)
            ?? $workflowClass;
        $compatibility = self::stringValue($run->compatibility ?? null)
            ?? self::stringValue($startedPayload['compatibility'] ?? null);
        $namespace = self::stringValue($run->namespace ?? null)
            ?? self::stringValue($startedPayload['namespace'] ?? null)
            ?? $this->namespace
            ?? self::historyNamespace($historyEvents);

        $run->forceFill(array_filter([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'compatibility' => $compatibility,
            'namespace' => $namespace,
        ], static fn (mixed $value): bool => $value !== null));
        $this->namespace = $namespace;

        $events = [];

        foreach ($historyEvents as $event) {
            $events[] = self::historyEventModel($event);
        }

        $run->setRelation('historyEvents', new EloquentCollection($events));
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     */
    private static function workflowStartedAt(array $historyEvents): ?CarbonInterface
    {
        $firstEventTime = null;

        foreach ($historyEvents as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $eventTime = self::eventRecordedAt($event, $payload);
            $firstEventTime ??= $eventTime;

            if (self::eventType($event) === 'WorkflowStarted' && $eventTime !== null) {
                return $eventTime;
            }
        }

        return $firstEventTime;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<string, mixed>
     */
    private static function workflowStartedPayload(array $historyEvents): array
    {
        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'WorkflowStarted') {
                continue;
            }

            return is_array($event['payload'] ?? null) ? $event['payload'] : [];
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     */
    private static function historyNamespace(array $historyEvents): ?string
    {
        foreach ($historyEvents as $event) {
            $namespace = self::stringValue($event['namespace'] ?? null);

            if ($namespace !== null) {
                return $namespace;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function historyEventModel(array $event): WorkflowHistoryEvent
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

        /** @var WorkflowHistoryEvent $model */
        $model = new WorkflowHistoryEvent();
        $model->forceFill(array_filter([
            'id' => self::stringValue($event['id'] ?? null),
            'sequence' => self::eventSequence($event, $payload),
            'event_type' => self::eventType($event),
            'payload' => $payload,
            'recorded_at' => self::eventRecordedAt($event, $payload),
        ], static fn (mixed $value): bool => $value !== null));

        return $model;
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private static function historyEventIdentity(array $event, array $payload): string
    {
        return self::stringValue($event['id'] ?? null)
            ?? implode(':', array_filter([
                self::eventType($event),
                (string) (self::eventSequence($event, $payload) ?? ''),
                self::stringValue($payload['update_id'] ?? null),
                self::stringValue($payload['update_name'] ?? null),
            ], static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return list<array<string, mixed>>
     */
    private static function normalizedHistoryEvents(array $historyEvents): array
    {
        $normalized = [];

        foreach ($historyEvents as $event) {
            if (is_array($event)) {
                $normalized[] = $event;
            }
        }

        return $normalized;
    }

    /**
     * Real worker-protocol command sequences are assigned by the bridge in
     * the durable workflow-command domain. The runner advances through the
     * workflow's yielded commands locally, so replay has to translate the
     * local position to the persisted command sequence before looking up
     * ActivityCompleted, TimerFired, marker, and other history outcomes.
     *
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, int>
     */
    private static function indexHistorySequencesByPosition(array $historyEvents): array
    {
        $positions = [];
        $seen = [];

        foreach ($historyEvents as $event) {
            if (! self::hasWorkflowCommandSequence(self::eventType($event))) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null || isset($seen[$sequence])) {
                continue;
            }

            $seen[$sequence] = true;
            $positions[count($positions) + 1] = $sequence;
        }

        return $positions;
    }

    private static function hasWorkflowCommandSequence(?string $type): bool
    {
        return in_array($type, [
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityHeartbeatRecorded',
            'ActivityRetryScheduled',
            'ActivityCompleted',
            'ActivityFailed',
            'ActivityCancelled',
            'ActivityTimedOut',
            'TimerScheduled',
            'TimerCancelled',
            'TimerFired',
            'ConditionWaitOpened',
            'ConditionWaitSatisfied',
            'ConditionWaitTimedOut',
            'SignalWaitOpened',
            'SignalApplied',
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunCompleted',
            'ChildRunFailed',
            'ChildRunCancelled',
            'ChildRunTerminated',
            'ServiceCallStarted',
            'ServiceCallCompleted',
            'ServiceCallFailed',
            'ServiceCallCancelled',
            'SideEffectRecorded',
            'VersionMarkerRecorded',
            'MemoUpserted',
            'SearchAttributesUpserted',
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedActivityOutcomes(
        array $historyEvents,
        string $payloadCodec,
        ?string $namespace,
    ): array {
        $outcomes = [];

        foreach ($historyEvents as $event) {
            $type = self::eventType($event);

            if (! in_array($type, [
                'ActivityCompleted',
                'ActivityFailed',
                'ActivityCancelled',
                'ActivityTimedOut',
            ], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            $recordedAt = self::eventRecordedAt($event, $payload);

            if ($type === 'ActivityCompleted') {
                $outcomes[$sequence] = [
                    'status' => 'completed',
                    'result' => self::decodePayload(
                        $payload['result'] ?? null,
                        $payloadCodec,
                        self::stringValue($payload['payload_codec'] ?? null),
                        $namespace,
                    ),
                    'recorded_at' => $recordedAt,
                ];

                continue;
            }

            $outcomes[$sequence] = [
                'status' => 'failed',
                'exception' => self::failureFromEvent($payload, match ($type) {
                    'ActivityCancelled' => 'Activity cancelled',
                    'ActivityTimedOut' => 'Activity timed out',
                    default => 'Activity failed',
                }),
                'recorded_at' => $recordedAt,
            ];
        }

        return $outcomes;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, true>
     */
    private static function indexOpenActivityWaits(array $historyEvents): array
    {
        $open = [];

        foreach ($historyEvents as $event) {
            if (! in_array(self::eventType($event), [
                'ActivityScheduled',
                'ActivityStarted',
                'ActivityHeartbeatRecorded',
                'ActivityRetryScheduled',
            ], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence !== null) {
                $open[$sequence] = true;
            }
        }

        return $open;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, true>
     */
    private static function indexLocalActivitySequences(array $historyEvents): array
    {
        $sequences = [];

        foreach ($historyEvents as $event) {
            if (! self::isActivityEventType(self::eventType($event))) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            if (($payload['execution_mode'] ?? null) !== LocalActivityRuntime::EXECUTION_MODE
                && ($payload['local_activity'] ?? null) !== true) {
                continue;
            }

            $sequence = self::eventSequence($event, $payload);
            if ($sequence !== null) {
                $sequences[$sequence] = true;
            }
        }

        return $sequences;
    }

    private function assertActivityExecutionMode(int $sequence, bool $expectedLocal): void
    {
        if (isset($this->localActivitySequences[$sequence]) === $expectedLocal) {
            return;
        }

        $recordedEventTypes = [];

        foreach ($this->historyEvents as $event) {
            $eventType = self::eventType($event);
            if (! self::isActivityEventType($eventType)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            if (self::eventSequence($event, $payload) === $sequence && $eventType !== null) {
                $recordedEventTypes[] = $eventType;
            }
        }

        if ($recordedEventTypes === []) {
            return;
        }

        throw new HistoryEventShapeMismatchException(
            $sequence,
            $expectedLocal ? WorkflowStepHistory::LOCAL_ACTIVITY : WorkflowStepHistory::ACTIVITY,
            array_values(array_unique($recordedEventTypes)),
        );
    }

    private static function isActivityEventType(?string $eventType): bool
    {
        return in_array($eventType, [
            'ActivityScheduled',
            'ActivityStarted',
            'ActivityHeartbeatRecorded',
            'ActivityRetryScheduled',
            'ActivityCompleted',
            'ActivityFailed',
            'ActivityCancelled',
            'ActivityTimedOut',
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedTimerOutcomes(array $historyEvents): array
    {
        $outcomes = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'TimerFired') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if (self::isInternalTimeoutTimerKind($payload['timer_kind'] ?? null)) {
                continue;
            }

            $sequence = self::eventSequence($event, $payload);

            if ($sequence !== null) {
                $outcomes[$sequence] = [
                    'result' => true,
                    'recorded_at' => self::eventRecordedAt($event, $payload, ['fired_at']),
                ];
            }
        }

        return $outcomes;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, true>
     */
    private static function indexOpenTimerWaits(array $historyEvents): array
    {
        $open = [];

        foreach ($historyEvents as $event) {
            if (! in_array(self::eventType($event), ['TimerScheduled', 'TimerCancelled'], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if (self::isInternalTimeoutTimerKind($payload['timer_kind'] ?? null)) {
                continue;
            }

            $sequence = self::eventSequence($event, $payload);

            if ($sequence !== null) {
                $open[$sequence] = true;
            }
        }

        return $open;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{result: bool, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedConditionOutcomes(array $historyEvents): array
    {
        $outcomes = [];
        $resolvedSequences = [];

        foreach ($historyEvents as $event) {
            $type = self::eventType($event);
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if (! in_array($type, ['ConditionWaitSatisfied', 'ConditionWaitTimedOut', 'TimerFired'], true)) {
                continue;
            }

            if ($type === 'TimerFired' && ($payload['timer_kind'] ?? null) !== 'condition_timeout') {
                continue;
            }

            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            if (isset($resolvedSequences[$sequence])) {
                continue;
            }

            $outcomes[$sequence] = [
                'result' => $type === 'ConditionWaitSatisfied',
                'recorded_at' => self::eventRecordedAt($event, $payload, ['fired_at']),
            ];
            $resolvedSequences[$sequence] = true;
        }

        return $outcomes;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, true>
     */
    private static function indexOpenConditionWaits(array $historyEvents): array
    {
        $open = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'ConditionWaitOpened') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence !== null) {
                $open[$sequence] = true;
            }
        }

        return $open;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{signal_name: string, signal_wait_id: string|null}>
     */
    private static function indexOpenSignalWaits(array $historyEvents): array
    {
        $open = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'SignalWaitOpened') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);
            $signalName = self::stringValue($payload['signal_name'] ?? null);

            if ($sequence !== null && $signalName !== null) {
                $open[$sequence] = [
                    'signal_name' => $signalName,
                    'signal_wait_id' => self::stringValue($payload['signal_wait_id'] ?? null),
                ];
            }
        }

        return $open;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{signal_name: string, result: mixed, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedSignalOutcomes(
        array $historyEvents,
        string $payloadCodec,
        ?string $namespace,
    ): array {
        $outcomes = [];
        $openBySequence = self::indexOpenSignalWaits($historyEvents);
        $sequenceByWaitId = [];
        $resolvedKinds = [];

        foreach ($openBySequence as $sequence => $wait) {
            if ($wait['signal_wait_id'] !== null) {
                $sequenceByWaitId[$wait['signal_wait_id']] = $sequence;
            }
        }

        foreach ($historyEvents as $event) {
            $type = self::eventType($event);
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if (! in_array($type, ['SignalReceived', 'SignalApplied', 'TimerFired'], true)) {
                continue;
            }

            if ($type === 'TimerFired' && ($payload['timer_kind'] ?? null) !== 'signal_timeout') {
                continue;
            }

            $signalName = self::stringValue($payload['signal_name'] ?? null);
            $sequence = $type === 'SignalReceived'
                ? self::intValue($payload['workflow_sequence'] ?? null)
                : self::eventSequence($event, $payload);

            if ($sequence === null) {
                $signalWaitId = self::stringValue($payload['signal_wait_id'] ?? null);
                $sequence = $signalWaitId === null ? null : ($sequenceByWaitId[$signalWaitId] ?? null);
            }

            if ($sequence === null || $signalName === null) {
                continue;
            }

            if ($type === 'TimerFired') {
                if (isset($resolvedKinds[$sequence])) {
                    continue;
                }

                $outcomes[$sequence] = [
                    'signal_name' => $signalName,
                    'result' => null,
                    'recorded_at' => self::eventRecordedAt($event, $payload, ['fired_at']),
                ];
                $resolvedKinds[$sequence] = 'timeout';

                continue;
            }

            $existingKind = $resolvedKinds[$sequence] ?? null;

            $appliedSignalHasPayload = $type === 'SignalApplied'
                && (array_key_exists('value', $payload) || array_key_exists('arguments', $payload));

            if ($existingKind !== null && ! ($existingKind === 'signal_received' && $appliedSignalHasPayload)) {
                continue;
            }

            if ($type === 'SignalApplied' && array_key_exists('value', $payload)) {
                $result = self::decodePayload(
                    $payload['value'],
                    $payloadCodec,
                    self::stringValue($payload['payload_codec'] ?? null),
                    $namespace,
                );
            } else {
                $result = self::signalValueFromArgumentsPayload(
                    $payload['arguments'] ?? null,
                    $payloadCodec,
                    self::stringValue($payload['payload_codec'] ?? null),
                    $namespace,
                );
            }

            $outcomes[$sequence] = [
                'signal_name' => $signalName,
                'result' => $result,
                'recorded_at' => self::eventRecordedAt($event, $payload),
            ];
            $resolvedKinds[$sequence] = $type === 'SignalApplied'
                ? 'signal_applied'
                : 'signal_received';
        }

        return $outcomes;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedChildOutcomes(
        array $historyEvents,
        string $payloadCodec,
        ?string $namespace,
    ): array {
        $outcomes = [];

        foreach ($historyEvents as $event) {
            $type = self::eventType($event);

            if (! in_array($type, [
                'ChildRunCompleted',
                'ChildRunFailed',
                'ChildRunCancelled',
                'ChildRunTerminated',
            ], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            $recordedAt = self::eventRecordedAt($event, $payload, ['closed_at']);

            if ($type === 'ChildRunCompleted') {
                $outcomes[$sequence] = [
                    'status' => 'completed',
                    'result' => self::decodePayload(
                        $payload['output'] ?? null,
                        $payloadCodec,
                        self::stringValue($payload['payload_codec'] ?? null),
                        $namespace,
                    ),
                    'recorded_at' => $recordedAt,
                ];

                continue;
            }

            $outcomes[$sequence] = [
                'status' => 'failed',
                'exception' => self::failureFromEvent($payload, match ($type) {
                    'ChildRunCancelled' => 'Child workflow cancelled',
                    'ChildRunTerminated' => 'Child workflow terminated',
                    default => 'Child workflow failed',
                }),
                'recorded_at' => $recordedAt,
            ];
        }

        return $outcomes;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, true>
     */
    private static function indexOpenChildWaits(array $historyEvents): array
    {
        $open = [];

        foreach ($historyEvents as $event) {
            if (! in_array(self::eventType($event), ['ChildWorkflowScheduled', 'ChildRunStarted'], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence !== null) {
                $open[$sequence] = true;
            }
        }

        return $open;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{status: string, result?: ServiceOperationResult, exception?: Throwable, recorded_at: CarbonInterface|null, admission_visible?: bool}>
     */
    private static function indexRecordedServiceOperationOutcomes(
        array $historyEvents,
        string $fallbackCodec,
        ?string $namespace,
    ): array {
        $outcomes = [];

        foreach ($historyEvents as $event) {
            $type = self::eventType($event);

            if (! in_array($type, [
                'ServiceCallStarted',
                'ServiceCallCompleted',
                'ServiceCallFailed',
                'ServiceCallCancelled',
            ], true)) {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            $recordedAt = self::eventRecordedAt($event, $payload);

            if (in_array($type, ['ServiceCallFailed', 'ServiceCallCancelled'], true)) {
                $outcomes[$sequence] = [
                    'status' => $type === 'ServiceCallCancelled' ? 'cancelled' : 'failed',
                    'exception' => self::failureFromEvent(
                        $payload,
                        $type === 'ServiceCallCancelled'
                            ? 'Service operation cancelled.'
                            : 'Service operation failed.',
                    ),
                    'recorded_at' => $recordedAt,
                ];

                continue;
            }

            $surface = is_array($payload['service_call'] ?? null) ? $payload['service_call'] : [];
            $surface += array_filter([
                'service_call_id' => self::stringValue($payload['service_call_id'] ?? null),
                'status' => self::stringValue($payload['status'] ?? null),
                'outcome' => self::stringValue($payload['outcome'] ?? null),
                'endpoint_name' => self::stringValue($payload['endpoint_name'] ?? null),
                'service_name' => self::stringValue($payload['service_name'] ?? null),
                'operation_name' => self::stringValue($payload['operation_name'] ?? null),
                'operation_mode' => self::stringValue($payload['operation_mode'] ?? null),
                'wait_for' => self::stringValue($payload['wait_for'] ?? null),
            ], static fn (mixed $value): bool => $value !== null);

            $outcomes[$sequence] = [
                'status' => $type === 'ServiceCallCompleted' ? 'completed' : 'started',
                'result' => ServiceOperationResult::fromSurface(
                    $surface,
                    self::serviceOperationResponsePayload($payload, $fallbackCodec, $namespace),
                ),
                'recorded_at' => $recordedAt,
                'admission_visible' => $type === 'ServiceCallStarted'
                    ? self::serviceOperationStartedPayloadIsVisible($payload, $surface)
                    : true,
            ];
        }

        return $outcomes;
    }

    /**
     * @param array{status: string, result?: ServiceOperationResult, exception?: Throwable, recorded_at: CarbonInterface|null, admission_visible?: bool} $recorded
     */
    private static function serviceOperationStartedOutcomeIsVisible(
        array $recorded,
        ServiceOperationCall $call,
    ): bool {
        return ($recorded['admission_visible'] ?? false)
            || ($call->options?->shouldResumeOnAdmission() ?? false);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $surface
     */
    private static function serviceOperationStartedPayloadIsVisible(array $payload, array $surface): bool
    {
        return self::stringValue($payload['wait_for'] ?? null) === 'accepted'
            || self::stringValue($payload['operation_mode'] ?? null) === 'async'
            || self::stringValue($surface['wait_for'] ?? null) === 'accepted'
            || self::stringValue($surface['operation_mode'] ?? null) === 'async';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function serviceOperationResponsePayload(
        array $payload,
        string $fallbackCodec,
        ?string $namespace,
    ): mixed {
        if (! array_key_exists('response_payload', $payload)) {
            return null;
        }

        $responsePayload = $payload['response_payload'];

        return ServiceResponsePayload::decode(
            $responsePayload,
            $fallbackCodec,
            self::stringValue($payload['payload_codec'] ?? null),
            $namespace,
        );
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, true>
     */
    private static function indexOpenServiceOperationWaits(array $historyEvents): array
    {
        $open = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'ServiceCallStarted') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence !== null) {
                $open[$sequence] = true;
            }
        }

        return $open;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedSideEffects(
        array $historyEvents,
        string $payloadCodec,
        ?string $namespace,
    ): array {
        $sideEffects = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'SideEffectRecorded') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null || ! array_key_exists('result', $payload)) {
                continue;
            }

            $sideEffects[$sequence] = [
                'result' => self::decodePayload(
                    $payload['result'],
                    $payloadCodec,
                    self::stringValue($payload['payload_codec'] ?? null),
                    $namespace,
                ),
                'recorded_at' => self::eventRecordedAt($event, $payload),
            ];
        }

        return $sideEffects;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, WorkflowHistoryEvent>
     */
    private static function indexRecordedVersionMarkers(array $historyEvents): array
    {
        $versionMarkers = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'VersionMarkerRecorded') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            $versionMarkers[$sequence] = self::historyEventModel($event);
        }

        return $versionMarkers;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{payload: array<string, mixed>, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedSearchAttributeUpserts(array $historyEvents): array
    {
        $upserts = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'SearchAttributesUpserted') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            $upserts[$sequence] = [
                'payload' => $payload,
                'recorded_at' => self::eventRecordedAt($event, $payload),
            ];
        }

        return $upserts;
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{entries: mixed, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedMemoUpserts(array $historyEvents): array
    {
        $upserts = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) !== 'MemoUpserted') {
                continue;
            }

            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $sequence = self::eventSequence($event, $payload);

            if ($sequence === null) {
                continue;
            }

            $upserts[$sequence] = [
                'entries' => $payload['entries'] ?? null,
                'recorded_at' => self::eventRecordedAt($event, $payload),
            ];
        }

        return $upserts;
    }

    private static function decodePayload(
        mixed $payload,
        string $fallbackCodec,
        ?string $eventCodec = null,
        ?string $namespace = null,
    ): mixed {
        $codec = self::payloadCodec($payload, $eventCodec, $fallbackCodec);
        $serialized = ExternalPayloads::payloadBlob($payload, $codec, $namespace);

        if ($serialized === null) {
            return null;
        }

        return Serializer::unserializeWithCodec($codec, $serialized);
    }

    private static function signalValueFromArgumentsPayload(
        mixed $payload,
        string $fallbackCodec,
        ?string $eventCodec = null,
        ?string $namespace = null,
    ): mixed {
        if ($payload === null) {
            return true;
        }

        $arguments = self::decodePayload($payload, $fallbackCodec, $eventCodec, $namespace);

        if (! is_array($arguments)) {
            return $arguments;
        }

        $arguments = array_values($arguments);

        if ($arguments === []) {
            return true;
        }

        return count($arguments) === 1 ? $arguments[0] : $arguments;
    }

    private static function payloadCodec(mixed $payload, ?string $eventCodec, string $fallbackCodec): string
    {
        if ($eventCodec !== null) {
            return $eventCodec;
        }

        if (is_array($payload) && is_string($payload['codec'] ?? null) && $payload['codec'] !== '') {
            return $payload['codec'];
        }

        if (is_string($payload) && ExternalPayloads::isStoredReference($payload)) {
            $envelope = ExternalPayloads::storedEnvelope($payload);

            if (is_string($envelope['codec'] ?? null) && $envelope['codec'] !== '') {
                return $envelope['codec'];
            }
        }

        return $fallbackCodec;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function failureFromEvent(array $payload, string $fallbackMessage): Throwable
    {
        $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];
        $exceptionClass = self::stringValue($payload['exception_class'] ?? null) ?? RuntimeException::class;
        $message = self::stringValue($payload['message'] ?? null) ?? $fallbackMessage;
        $code = self::intValue($payload['code'] ?? null) ?? 0;

        if (! is_string($exception['type'] ?? null) && is_string($payload['exception_type'] ?? null)) {
            $exception['type'] = $payload['exception_type'];
        }

        if (! is_string($exception['class'] ?? null)) {
            $exception['class'] = $exceptionClass;
        }

        if (! is_string($exception['message'] ?? null)) {
            $exception['message'] = $message;
        }

        if (! is_int($exception['code'] ?? null)) {
            $exception['code'] = $code;
        }

        try {
            return FailureFactory::restoreForReplay($exception, $exceptionClass, $message, $code);
        } catch (UnresolvedWorkflowFailureException) {
            // External SDKs can report durable type keys without a PHP class mapping.
            return FailureFactory::restoreExternalWorkerFailure($exception, $exceptionClass, $message, $code);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     */
    private static function eventSequence(array $event, array $payload): ?int
    {
        $payloadSequence = self::intValue($payload['sequence'] ?? null);

        if ($payloadSequence !== null) {
            return $payloadSequence;
        }

        $workflowSequence = self::intValue($payload['workflow_sequence'] ?? null);

        if ($workflowSequence !== null) {
            return $workflowSequence;
        }

        return self::intValue($event['sequence'] ?? null);
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function eventType(array $event): ?string
    {
        return self::stringValue($event['event_type'] ?? null)
            ?? self::stringValue($event['type'] ?? null);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     * @param list<string> $payloadKeys
     */
    private static function eventRecordedAt(
        array $event,
        array $payload = [],
        array $payloadKeys = []
    ): ?CarbonInterface {
        $recordedAt = self::eventTime($event['recorded_at'] ?? null);

        if ($recordedAt !== null) {
            return $recordedAt;
        }

        foreach ($payloadKeys as $key) {
            $time = self::eventTime($payload[$key] ?? null);

            if ($time !== null) {
                return $time;
            }
        }

        return null;
    }

    private static function eventTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private static function isInternalTimeoutTimerKind(mixed $value): bool
    {
        return in_array($value, ['condition_timeout', 'signal_timeout'], true);
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
