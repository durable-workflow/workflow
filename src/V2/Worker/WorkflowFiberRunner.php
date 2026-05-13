<?php

declare(strict_types=1);

namespace Workflow\V2\Worker;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use LogicException;
use RuntimeException;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\FailureFactory;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\UpsertSearchAttributesCall;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\VersionResolution;
use Workflow\V2\Support\VersionResolver;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Workflow;

/**
 * Cold-replay executor for PHP-authored workflows driven by worker protocol tasks.
 *
 * Pass bridge `history_events` when constructing a cold runner; recorded
 * activity, timer, child-workflow, side-effect, version marker, and
 * search-attribute outcomes are replayed into the Fiber before new commands
 * are emitted.
 *
 * @api Stable v2 worker protocol API.
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
     * @var array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private array $recordedTimerOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openTimerWaits = [];

    /**
     * @var array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private array $recordedChildOutcomes = [];

    /**
     * @var array<int, true>
     */
    private array $openChildWaits = [];

    /**
     * @var array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private array $recordedSideEffects = [];

    /**
     * @var array<int, WorkflowHistoryEvent>
     */
    private array $recordedVersionMarkers = [];

    /**
     * @var array<int, array{recorded_at: CarbonInterface|null}>
     */
    private array $recordedSearchAttributeUpserts = [];

    private bool $hasReplayHistory = false;

    /**
     * @param array<int, mixed> $arguments
     * @param list<array<string, mixed>> $historyEvents
     */
    public function __construct(
        private readonly Workflow $workflow,
        private readonly array $arguments,
        private readonly string $payloadCodec = 'avro',
        array $historyEvents = [],
    ) {
        $this->loadHistoryEvents($historyEvents);
    }

    /**
     * Build a runner for a workflow class registered to an external worker.
     *
     * The standalone server owns the durable run row, so this helper passes
     * a transient in-memory WorkflowRun with the protocol-assigned identity.
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
    ): self {
        $run = new WorkflowRun();
        $run->id = $runId;
        $run->workflow_instance_id = $workflowId;
        $run->workflow_class = $workflowClass;
        $run->workflow_type = $workflowClass;
        $run->payload_codec = $payloadCodec;

        $workflow = new $workflowClass($run);

        if (! $workflow instanceof Workflow) {
            throw new RuntimeException(sprintf(
                'Worker protocol runner can only host Workflow\\V2\\Workflow subclasses; got %s.',
                $workflowClass,
            ));
        }

        return new self($workflow, $arguments, $payloadCodec, $historyEvents);
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
                    ++$this->sequence;
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

            if ($current instanceof SideEffectCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $this->recordedSideEffects[$historySequence] ?? null;

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
                $versionMarkerEvent = $this->recordedVersionMarkers[$historySequence] ?? null;
                $resolution = $this->resolveVersion($current, $versionMarkerEvent, $historySequence);

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

            if ($current instanceof UpsertSearchAttributesCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $this->recordedSearchAttributeUpserts[$historySequence] ?? null;

                if ($recorded !== null) {
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

            if ($current instanceof ActivityCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $this->recordedActivityOutcomes[$historySequence] ?? null;

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

                if (isset($this->openActivityWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof TimerCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $this->recordedTimerOutcomes[$historySequence] ?? null;

                if ($recorded !== null) {
                    ++$this->sequence;
                    $this->execution->send($recorded['result'], $recorded['recorded_at']);

                    continue;
                }

                if (isset($this->openTimerWaits[$historySequence])) {
                    $this->waitingForHistory = true;

                    return WorkflowStep::waiting($current)
                        ->withPrependedCommands($immediateCommands);
                }
            }

            if ($current instanceof ChildWorkflowCall) {
                $historySequence = $this->historySequenceForCurrentPosition();
                $recorded = $this->recordedChildOutcomes[$historySequence] ?? null;

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

                if (isset($this->openChildWaits[$historySequence])) {
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

    private function historyAvailableForPendingYielded(): bool
    {
        $historySequence = $this->historySequenceForCurrentPosition();

        return match (true) {
            $this->pendingYielded instanceof ActivityCall => isset($this->recordedActivityOutcomes[$historySequence])
                || isset($this->openActivityWaits[$historySequence]),
            $this->pendingYielded instanceof TimerCall => isset($this->recordedTimerOutcomes[$historySequence])
                || isset($this->openTimerWaits[$historySequence]),
            $this->pendingYielded instanceof ChildWorkflowCall => isset($this->recordedChildOutcomes[$historySequence])
                || isset($this->openChildWaits[$historySequence]),
            default => false,
        };
    }

    private function historySequenceForCurrentPosition(): int
    {
        return $this->historySequencesByPosition[$this->sequence] ?? $this->sequence;
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

    /**
     * @param list<array<string, mixed>> $historyEvents
     */
    private function loadHistoryEvents(array $historyEvents): void
    {
        $this->hasReplayHistory = $historyEvents !== [];
        $this->syncRunReplayMetadata($historyEvents);
        $this->startedAt = self::workflowStartedAt($historyEvents);
        $this->historySequencesByPosition = self::indexHistorySequencesByPosition($historyEvents);
        $this->recordedActivityOutcomes = self::indexRecordedActivityOutcomes($historyEvents, $this->payloadCodec);
        $this->openActivityWaits = array_diff_key(
            self::indexOpenActivityWaits($historyEvents),
            $this->recordedActivityOutcomes,
        );
        $this->recordedTimerOutcomes = self::indexRecordedTimerOutcomes($historyEvents);
        $this->openTimerWaits = array_diff_key(
            self::indexOpenTimerWaits($historyEvents),
            $this->recordedTimerOutcomes,
        );
        $this->recordedChildOutcomes = self::indexRecordedChildOutcomes($historyEvents, $this->payloadCodec);
        $this->openChildWaits = array_diff_key(
            self::indexOpenChildWaits($historyEvents),
            $this->recordedChildOutcomes,
        );
        $this->recordedSideEffects = self::indexRecordedSideEffects($historyEvents, $this->payloadCodec);
        $this->recordedVersionMarkers = self::indexRecordedVersionMarkers($historyEvents);
        $this->recordedSearchAttributeUpserts = self::indexRecordedSearchAttributeUpserts($historyEvents);
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

        $run->forceFill(array_filter([
            'workflow_class' => $workflowClass,
            'workflow_type' => $workflowType,
            'compatibility' => $compatibility,
        ], static fn (mixed $value): bool => $value !== null));

        $startedEvents = [];

        foreach ($historyEvents as $event) {
            if (self::eventType($event) === 'WorkflowStarted') {
                $startedEvents[] = self::historyEventModel($event);
            }
        }

        $run->setRelation('historyEvents', new EloquentCollection($startedEvents));
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
     * Real worker-protocol command sequences are assigned by the bridge from
     * the run's global history sequence. The runner advances through the
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
            'ChildWorkflowScheduled',
            'ChildRunStarted',
            'ChildRunCompleted',
            'ChildRunFailed',
            'ChildRunCancelled',
            'ChildRunTerminated',
            'SideEffectRecorded',
            'VersionMarkerRecorded',
            'SearchAttributesUpserted',
        ], true);
    }

    /**
     * @param list<array<string, mixed>> $historyEvents
     * @return array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedActivityOutcomes(array $historyEvents, string $payloadCodec): array
    {
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
     * @return array<int, array{status: string, result?: mixed, exception?: Throwable, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedChildOutcomes(array $historyEvents, string $payloadCodec): array
    {
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
     * @return array<int, array{result: mixed, recorded_at: CarbonInterface|null}>
     */
    private static function indexRecordedSideEffects(array $historyEvents, string $payloadCodec): array
    {
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
     * @return array<int, array{recorded_at: CarbonInterface|null}>
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
                'recorded_at' => self::eventRecordedAt($event, $payload),
            ];
        }

        return $upserts;
    }

    private static function decodePayload(mixed $payload, string $fallbackCodec, ?string $eventCodec = null): mixed
    {
        $codec = self::payloadCodec($payload, $eventCodec, $fallbackCodec);
        $serialized = ExternalPayloads::payloadBlob($payload, $codec, null);

        if ($serialized === null) {
            return null;
        }

        return Serializer::unserializeWithCodec($codec, $serialized);
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

        if (! is_string($exception['type'] ?? null) && is_string($payload['exception_type'] ?? null)) {
            $exception['type'] = $payload['exception_type'];
        }

        return FailureFactory::restoreForReplay(
            $exception,
            self::stringValue($payload['exception_class'] ?? null) ?? RuntimeException::class,
            self::stringValue($payload['message'] ?? null) ?? $fallbackMessage,
            self::intValue($payload['code'] ?? null) ?? 0,
        );
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
    private static function eventRecordedAt(array $event, array $payload = [], array $payloadKeys = []): ?CarbonInterface
    {
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
