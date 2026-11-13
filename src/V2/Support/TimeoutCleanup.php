<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CancellationContext;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/**
 * Deadline-triggered cleanup (#863, contract in
 * docs/architecture/timeout-cleanup.md).
 *
 * A start that opts in reserves the last `allowance_seconds` of each hard
 * timeout for workflow-authored cleanup. The work deadline is the hard
 * deadline minus the allowance; the hard deadline never moves. The admitted
 * values are recorded once in `WorkflowStarted` and read from there.
 *
 * @internal
 */
final class TimeoutCleanup
{
    /**
     * `WorkflowStarted` payload key.
     */
    public const STARTED_KEY = 'timeout_cleanup';

    /**
     * Enter timeout cleanup once, at or after the run's work deadline and
     * before its hard deadline (#863). Records a system cooperative
     * cancellation request with `cause: timeout` whose cleanup deadline is the
     * earliest hard deadline, plus `WorkflowTimeoutCleanupStarted`, so the
     * existing cooperative-cancellation delivery and cleanup-deadline
     * enforcement apply unchanged. The caller holds the run lock.
     */
    public static function enter(WorkflowRun $run, CarbonInterface $now): bool
    {
        $workDeadline = $run->timeout_cleanup_work_deadline_at;
        if ($workDeadline === null || $run->status->isTerminal()
            || is_string($run->cancellation_request_command_id) || $now->lt($workDeadline)) {
            return false;
        }

        [$timeoutKind, $hardDeadline] = self::earliestHardDeadline($run);
        if ($hardDeadline === null || $now->gte($hardDeadline)) {
            // The hard timeout closes the run instead.
            return false;
        }

        $run->loadMissing(['instance', 'historyEvents']);
        if ($run->historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowTimeoutCleanupStarted
        )) {
            return false;
        }

        /** @var WorkflowInstance $instance */
        $instance = $run->instance;
        $requestId = (string) Str::ulid();
        $reason = 'Workflow work deadline passed; timeout cleanup requested.';
        $context = [
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => $requestId,
            'root_request_id' => $requestId,
            'root_workflow_instance_id' => $instance->id,
            'root_workflow_run_id' => $run->id,
            'parent_request_id' => null,
            'reason' => $reason,
            'requester' => [
                'type' => 'system',
                'id' => 'timeout_cleanup',
            ],
            'source' => 'system',
            'requested_at' => $now->toISOString(),
            'cleanup_deadline_at' => $hardDeadline->toISOString(),
            'lineage' => [[
                'request_id' => $requestId,
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ]],
            'cause' => CancellationContext::CAUSE_TIMEOUT,
        ];
        $context = CancellationContext::fromArray($context)->toArray();
        $codec = $run->payload_codec ?? CodecRegistry::defaultCodec();

        $command = WorkflowCommand::record($instance, $run, [
            'id' => $requestId,
            'command_type' => CommandType::RequestCancellation->value,
            ...CommandContext::system('timeout_cleanup')->attributes(),
            'target_scope' => 'run',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::CancellationRequested->value,
            'payload_codec' => $codec,
            'payload' => Serializer::serializeWithCodec($codec, [
                'reason' => $reason,
                'cleanup_deadline_at' => $hardDeadline->toISOString(),
                'cancellation' => $context,
            ]),
            'accepted_at' => $now,
            'applied_at' => $now,
        ]);

        $run->forceFill([
            'cancellation_request_command_id' => $command->id,
            'cancellation_requested_at' => $now,
            'cancellation_deadline_at' => $hardDeadline,
            'last_progress_at' => $now,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowTimeoutCleanupStarted, [
            'workflow_command_id' => $command->id,
            'timeout_kind' => $timeoutKind,
            'work_deadline_at' => $workDeadline->toIso8601String(),
            'cleanup_deadline_at' => $hardDeadline->toIso8601String(),
            'hard_deadline_at' => $hardDeadline->toIso8601String(),
            'detected_at' => $now->toIso8601String(),
        ], null, $command);
        WorkflowHistoryEvent::record($run, HistoryEventType::CooperativeCancellationRequested, [
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => CommandType::RequestCancellation->value,
            'reason' => $reason,
            'cleanup_deadline_at' => $hardDeadline->toISOString(),
            'cancellation' => $context,
        ], null, $command);

        CancellationScopeDescendants::request($run, CancellationScopeHistory::ROOT_SCOPE_ID);

        $run->unsetRelation('historyEvents');
        $run->unsetRelation('commands');

        return true;
    }

    /**
     * The `timeout_cleanup` outcome recorded on the terminal event.
     *
     * @param 'completed'|'failed'|'expired'|'preempted' $outcome
     * @return array<string, mixed>
     */
    public static function outcome(WorkflowRun $run, string $outcome, ?string $failureId = null): array
    {
        /** @var WorkflowHistoryEvent|null $started */
        $started = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowTimeoutCleanupStarted->value)
            ->first();
        $payload = is_array($started?->payload) ? $started->payload : [];

        return array_filter([
            'outcome' => $outcome,
            'timeout_kind' => $payload['timeout_kind'] ?? null,
            'started_at' => $payload['detected_at'] ?? null,
            'cleanup_deadline_at' => $payload['cleanup_deadline_at'] ?? null,
            'finished_at' => now()
                ->toIso8601String(),
            'failure_id' => $failureId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array{0: string|null, 1: CarbonInterface|null}
     */
    public static function earliestHardDeadline(WorkflowRun $run): array
    {
        $kind = null;
        $earliest = null;
        foreach ([
            'execution' => $run->execution_deadline_at,
            'run' => $run->run_deadline_at,
        ] as $candidateKind => $deadline) {
            if ($deadline !== null && ($earliest === null || $deadline->lt($earliest))) {
                $kind = $candidateKind;
                $earliest = $deadline;
            }
        }

        return [$kind, $earliest];
    }

    /**
     * The allowance a run was admitted with, read from its `WorkflowStarted`.
     * A continued or redriven run inherits it, as it inherits the hard
     * deadlines.
     */
    public static function allowanceForRun(WorkflowRun $run): ?int
    {
        /** @var WorkflowHistoryEvent|null $started */
        $started = $run->relationLoaded('historyEvents')
            ? $run->historyEvents->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowStarted
            )
            : $run->historyEvents()
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->first();
        $allowance = $started?->payload[self::STARTED_KEY]['allowance_seconds'] ?? null;

        return is_int($allowance) && $allowance > 0 ? $allowance : null;
    }

    /**
     * The earliest work deadline: each hard deadline minus the allowance.
     */
    public static function workDeadline(
        ?int $allowanceSeconds,
        ?CarbonInterface $executionDeadlineAt,
        ?CarbonInterface $runDeadlineAt,
    ): ?CarbonInterface {
        if ($allowanceSeconds === null) {
            return null;
        }

        $earliest = null;
        foreach ([$executionDeadlineAt, $runDeadlineAt] as $hardDeadline) {
            if ($hardDeadline === null) {
                continue;
            }
            $work = $hardDeadline->copy()
                ->subSeconds($allowanceSeconds);
            if ($earliest === null || $work->lt($earliest)) {
                $earliest = $work;
            }
        }

        return $earliest;
    }

    /**
     * @return array{allowance_seconds: int, work_deadlines: array<string, string>}|null
     */
    public static function startedPayload(
        ?int $allowanceSeconds,
        ?CarbonInterface $executionDeadlineAt,
        ?CarbonInterface $runDeadlineAt,
    ): ?array {
        if ($allowanceSeconds === null) {
            return null;
        }

        $workDeadlines = [];
        foreach ([
            'execution' => $executionDeadlineAt,
            'run' => $runDeadlineAt,
        ] as $kind => $hardDeadline) {
            if ($hardDeadline !== null) {
                $workDeadlines[$kind] = $hardDeadline->copy()
                    ->subSeconds($allowanceSeconds)
                    ->toIso8601String();
            }
        }

        return [
            'allowance_seconds' => $allowanceSeconds,
            'work_deadlines' => $workDeadlines,
        ];
    }
}
