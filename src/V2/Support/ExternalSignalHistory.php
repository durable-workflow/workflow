<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ExternalSignalOutcome;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\ExternalSignalResult;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/**
 * Replay authority for durable signal-external (.github#223).
 *
 * One command records `ExternalSignalRequested` and exactly one resolution,
 * `ExternalSignalDelivered` or `ExternalSignalFailed`, at the same workflow
 * sequence. Replay returns the recorded outcome and never sends again. A
 * different target, run, signal name or arguments at that sequence is a
 * history shape mismatch, as is malformed resolution history.
 *
 * @internal
 */
final class ExternalSignalHistory
{
    /**
     * Every delivery is keyed on its target as `workflow-signal:<sender run>:<sequence>`.
     * Callers cannot use the prefix (`request_id_reserved`).
     */
    public const REQUEST_ID_PREFIX = 'workflow-signal:';

    public const SHAPE = 'external signal';

    public const NONE = 'none';

    public const PENDING = 'pending';

    public const RESOLVED = 'resolved';

    public static function requestId(string $senderRunId, int $sequence): string
    {
        return self::REQUEST_ID_PREFIX . $senderRunId . ':' . $sequence;
    }

    public static function isReservedRequestId(mixed $requestId): bool
    {
        return is_string($requestId) && str_starts_with($requestId, self::REQUEST_ID_PREFIX);
    }

    /**
     * What history records for this command at the sequence: nothing yet, a
     * request whose delivery has not recorded its outcome, or the outcome.
     *
     * @return array{status: self::NONE|self::PENDING|self::RESOLVED, result: ExternalSignalResult|null}
     */
    public static function lookup(WorkflowRun $run, int $sequence, SignalExternalWorkflowCall $call): array
    {
        $requested = self::events($run, $sequence, HistoryEventType::ExternalSignalRequested);
        $resolutions = [
            ...self::events($run, $sequence, HistoryEventType::ExternalSignalDelivered),
            ...self::events($run, $sequence, HistoryEventType::ExternalSignalFailed),
        ];

        if ($requested === [] && $resolutions === []) {
            return [
                'status' => self::NONE,
                'result' => null,
            ];
        }

        if (count($requested) !== 1) {
            self::invalid($sequence, $requested === []
                ? 'An external signal outcome was recorded without its request.'
                : 'More than one external signal request was recorded at one sequence.');
        }

        $request = $requested[0];
        self::assertSameIntent($sequence, $request, $call);

        if ($resolutions === []) {
            return [
                'status' => self::PENDING,
                'result' => null,
            ];
        }

        if (count($resolutions) !== 1) {
            self::invalid($sequence, 'More than one external signal outcome was recorded at one sequence.');
        }

        return [
            'status' => self::RESOLVED,
            'result' => self::resultFor($sequence, $request, $resolutions[0]),
        ];
    }

    /**
     * The recorded outcome for this command, or null when nothing is
     * resolved at the sequence yet.
     */
    public static function recordedResult(
        WorkflowRun $run,
        int $sequence,
        SignalExternalWorkflowCall $call,
    ): ?ExternalSignalResult {
        return self::lookup($run, $sequence, $call)['result'];
    }

    /**
     * The signal a recorded request describes, for its delivery.
     */
    public static function callFromRequest(WorkflowHistoryEvent $request): SignalExternalWorkflowCall
    {
        $payload = is_array($request->payload) ? $request->payload : [];
        $arguments = $payload['arguments'] ?? null;
        $codec = is_array($arguments) && is_string($arguments['codec'] ?? null)
            ? $arguments['codec']
            : (is_string($payload['payload_codec'] ?? null) ? $payload['payload_codec'] : null);
        $blob = is_array($arguments) ? ($arguments['blob'] ?? null) : $arguments;
        $decoded = is_string($blob) ? Serializer::unserializeWithCodec($codec, $blob) : [];

        return new SignalExternalWorkflowCall(
            (string) ($payload['target_workflow_instance_id'] ?? ''),
            (string) ($payload['signal_name'] ?? ''),
            is_array($decoded) ? $decoded : [],
            is_string($payload['target_workflow_run_id'] ?? null) ? $payload['target_workflow_run_id'] : null,
        );
    }

    /**
     * Requests recorded on this run that have no outcome yet, by sequence.
     *
     * @return array<int, WorkflowHistoryEvent>
     */
    public static function pendingRequests(WorkflowRun $run): array
    {
        $pending = [];
        $resolved = [];

        foreach ($run->historyEvents as $event) {
            $sequence = self::intValue($event->payload['sequence'] ?? null);
            if ($sequence === null) {
                continue;
            }

            if ($event->event_type === HistoryEventType::ExternalSignalRequested) {
                $pending[$sequence] ??= $event;
            } elseif (in_array($event->event_type, [
                HistoryEventType::ExternalSignalDelivered,
                HistoryEventType::ExternalSignalFailed,
            ], true)) {
                $resolved[$sequence] = true;
            }
        }

        return array_diff_key($pending, $resolved);
    }

    /**
     * The recorded resolution at this sequence, for replay time.
     */
    public static function resolutionEvent(WorkflowRun $run, int $sequence): ?WorkflowHistoryEvent
    {
        return [
            ...self::events($run, $sequence, HistoryEventType::ExternalSignalDelivered),
            ...self::events($run, $sequence, HistoryEventType::ExternalSignalFailed),
        ][0] ?? null;
    }

    /**
     * @return array{codec: string, blob: string}
     */
    public static function argumentsEnvelope(SignalExternalWorkflowCall $call, ?string $payloadCodec): array
    {
        $codec = CodecRegistry::canonicalize($payloadCodec ?? CodecRegistry::defaultCodec());

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, $call->arguments),
        ];
    }

    private static function assertSameIntent(
        int $sequence,
        WorkflowHistoryEvent $request,
        SignalExternalWorkflowCall $call,
    ): void {
        $payload = is_array($request->payload) ? $request->payload : [];
        $recordedRun = $payload['target_workflow_run_id'] ?? null;

        $changed = match (true) {
            ($payload['target_workflow_instance_id'] ?? null) !== $call->workflowId => 'target workflow',
            $recordedRun !== $call->runId => 'target run',
            ($payload['signal_name'] ?? null) !== $call->signalName => 'signal name',
            ! self::argumentsMatch($payload, $call) => 'arguments',
            default => null,
        };

        if ($changed === null) {
            return;
        }

        throw new HistoryEventShapeMismatchException(
            $sequence,
            self::SHAPE . ' with matching ' . $changed,
            [HistoryEventType::ExternalSignalRequested->value],
            sprintf(
                'Recorded external signal %s does not match the current yielded signal (external_signal_mismatch).',
                $changed,
            ),
        );
    }

    /**
     * Compare after one codec round trip, so equal values match whatever
     * encoder recorded them. Argument values never appear in a diagnostic.
     *
     * @param array<string, mixed> $payload
     */
    private static function argumentsMatch(array $payload, SignalExternalWorkflowCall $call): bool
    {
        $arguments = $payload['arguments'] ?? null;
        $codec = is_array($arguments) && is_string($arguments['codec'] ?? null)
            ? $arguments['codec']
            : (is_string($payload['payload_codec'] ?? null) ? $payload['payload_codec'] : null);
        $blob = is_array($arguments) ? ($arguments['blob'] ?? null) : $arguments;

        if (! is_string($codec) || ! is_string($blob)) {
            return false;
        }

        try {
            $recorded = Serializer::unserializeWithCodec($codec, $blob);

            return is_array($recorded)
                && hash_equals(
                    Serializer::serializeWithCodec(
                        $codec,
                        array_is_list($recorded) ? array_values($recorded) : $recorded
                    ),
                    Serializer::serializeWithCodec($codec, $call->arguments),
                );
        } catch (Throwable) {
            return false;
        }
    }

    private static function resultFor(
        int $sequence,
        WorkflowHistoryEvent $request,
        WorkflowHistoryEvent $resolution,
    ): ExternalSignalResult {
        $requested = is_array($request->payload) ? $request->payload : [];
        $payload = is_array($resolution->payload) ? $resolution->payload : [];

        foreach (['target_workflow_instance_id', 'signal_name', 'request_id'] as $field) {
            if (($payload[$field] ?? null) !== ($requested[$field] ?? null)) {
                self::invalid($sequence, sprintf('The external signal outcome names a different %s.', $field));
            }
        }

        $outcome = is_string($payload['outcome'] ?? null)
            ? ExternalSignalOutcome::tryFrom($payload['outcome'])
            : null;
        $delivered = $resolution->event_type === HistoryEventType::ExternalSignalDelivered;

        if (! $outcome instanceof ExternalSignalOutcome
            || ($outcome === ExternalSignalOutcome::Delivered) !== $delivered) {
            self::invalid($sequence, 'The external signal outcome is unknown or does not match its event.');
        }

        $reason = self::stringValue($payload['reason'] ?? null);
        if (! $delivered && $reason === null) {
            self::invalid($sequence, 'A failed external signal outcome has no reason.');
        }

        return new ExternalSignalResult(
            outcome: $outcome,
            reason: $delivered ? null : $reason,
            message: self::stringValue($payload['message'] ?? null),
            targetWorkflowId: (string) $requested['target_workflow_instance_id'],
            targetRunId: self::stringValue($payload['target_workflow_run_id'] ?? null),
            signalName: (string) $requested['signal_name'],
            requestId: (string) ($requested['request_id'] ?? ''),
            targetCommandId: self::stringValue($payload['target_command_id'] ?? null),
            targetSignalId: self::stringValue($payload['target_signal_id'] ?? null),
            retained: ($payload['retained'] ?? false) === true,
        );
    }

    /**
     * @return list<WorkflowHistoryEvent>
     */
    private static function events(WorkflowRun $run, int $sequence, HistoryEventType $type): array
    {
        return $run->historyEvents
            ->filter(static fn (WorkflowHistoryEvent $event): bool => $event->event_type === $type
                && self::intValue($event->payload['sequence'] ?? null) === $sequence)
            ->values()
            ->all();
    }

    private static function invalid(int $sequence, string $detail): never
    {
        throw new HistoryEventShapeMismatchException(
            $sequence,
            self::SHAPE,
            [
                HistoryEventType::ExternalSignalRequested->value,
                HistoryEventType::ExternalSignalDelivered->value,
                HistoryEventType::ExternalSignalFailed->value,
            ],
            $detail . ' (external_signal_history_invalid)',
        );
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
