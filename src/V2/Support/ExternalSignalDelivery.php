<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\Support\AfterCommit;
use Workflow\V2\CommandContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\ExternalSignalOutcome;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * Delivers durable signal-external requests (.github#223), as an outbox.
 *
 * The sending workflow task records `ExternalSignalRequested` and commits,
 * locking only its own rows. Delivery then runs after that commit, outside
 * the sender's transaction:
 *
 * 1. The target admits an ordinary signal in its own transaction (the same
 *    accepted command, signal record, `SignalReceived` and message task as
 *    any signal), keyed `workflow-signal:<sender run>:<sequence>` and
 *    attributed through `CommandContext::workflow()`. A repeated delivery
 *    returns the retained receipt instead of signalling twice.
 * 2. The sender's run is locked on its own, exactly one
 *    `ExternalSignalDelivered` or `ExternalSignalFailed` is appended at the
 *    request's sequence, and the sender gets a workflow task to read it.
 *
 * A transient database failure (deadlock, lock wait, lost connection) is
 * retried here and otherwise left pending: it never fails the sender. The
 * sender's delayed resume task re-attempts a pending delivery, so a crash
 * between the sender's commit and its delivery is recovered by the same
 * task repair that recovers any workflow task. Only definitive answers
 * (not found, closed, rejected) are recorded as failures.
 *
 * @internal
 */
final class ExternalSignalDelivery
{
    private const INLINE_ATTEMPTS = 3;

    /**
     * Deliver one pending request after the caller's transaction commits.
     * Never throws: a delivery that cannot finish now stays pending.
     */
    public static function afterCommit(WorkflowRun $sender, int $sequence): void
    {
        $runId = $sender->id;
        AfterCommit::on($sender->getConnection(), static function () use ($runId, $sequence): void {
            self::deliverPending($runId, $sequence);
        });
    }

    /**
     * Deliver the request recorded at this sequence, if it is still pending.
     * Returns true once an outcome is recorded (now or earlier).
     */
    public static function deliverPending(string $senderRunId, int $sequence): bool
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return self::attempt($senderRunId, $sequence);
            } catch (Throwable $throwable) {
                if (! self::transient($throwable) || $attempt >= self::INLINE_ATTEMPTS) {
                    if (! self::transient($throwable)) {
                        report($throwable);
                    }

                    return false;
                }

                usleep($attempt * 50_000);
            }
        }
    }

    private static function attempt(string $senderRunId, int $sequence): bool
    {
        /** @var WorkflowRun|null $sender */
        $sender = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->with('historyEvents')
            ->find($senderRunId);

        if (! $sender instanceof WorkflowRun) {
            return false;
        }

        $request = ExternalSignalHistory::pendingRequests($sender)[$sequence] ?? null;
        if (! $request instanceof WorkflowHistoryEvent) {
            // Already resolved, or never requested.
            return ExternalSignalHistory::resolutionEvent($sender, $sequence) instanceof WorkflowHistoryEvent;
        }

        if ($sender->status->isTerminal()) {
            // A closed sender has no workflow code left to read an outcome.
            return false;
        }

        $resolution = self::resolve($sender, $sequence, ExternalSignalHistory::callFromRequest($request));
        $wake = null;

        $recorded = SqliteTransaction::run(static function () use ($senderRunId, $sequence, $resolution, &$wake): bool {
            /** @var WorkflowRun $locked */
            $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->lockForUpdate()
                ->findOrFail($senderRunId);
            $locked->setRelation('historyEvents', $locked->historyEvents()->get());

            if ($locked->status->isTerminal()) {
                return false;
            }

            if (! isset(ExternalSignalHistory::pendingRequests($locked)[$sequence])) {
                return ExternalSignalHistory::resolutionEvent($locked, $sequence) instanceof WorkflowHistoryEvent;
            }

            $event = WorkflowHistoryEvent::record($locked, $resolution['event_type'], $resolution['payload']);
            $wake = self::wakeSender($locked, $event);
            /** @var HistoryProjectionRole $projection */
            $projection = app(HistoryProjectionRole::class);
            $projection->projectRun(
                $locked->fresh(['instance', 'tasks', 'activityExecutions', 'timers', 'failures', 'historyEvents'])
                    ?? $locked
            );

            return true;
        });

        if ($wake instanceof WorkflowTask) {
            TaskDispatcher::dispatch($wake);
        }

        return $recorded;
    }

    /**
     * Make the sender's waiting workflow task due now, or create one.
     * Runs under the sender's run lock.
     */
    private static function wakeSender(WorkflowRun $sender, WorkflowHistoryEvent $event): ?WorkflowTask
    {
        /** @var WorkflowTask|null $open */
        $open = ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $sender->id)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();

        if ($open instanceof WorkflowTask) {
            if ($open->status !== TaskStatus::Ready) {
                // The leased task reads this outcome or leaves its own resume task.
                return null;
            }

            if ($open->available_at !== null && $open->available_at->isFuture()) {
                $open->forceFill([
                    'available_at' => now(),
                ])->save();
            }

            return $open;
        }

        /** @var WorkflowTask $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->create([
            'workflow_run_id' => $sender->id,
            'namespace' => $sender->namespace,
            'task_type' => TaskType::Workflow->value,
            'status' => TaskStatus::Ready->value,
            'available_at' => now(),
            'payload' => WorkflowTaskPayload::forExternalSignal($event),
            'connection' => $sender->connection,
            'queue' => $sender->queue,
            'compatibility' => $sender->compatibility,
        ]);

        return $task;
    }

    /**
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>}
     */
    private static function resolve(WorkflowRun $sender, int $sequence, SignalExternalWorkflowCall $call): array
    {
        $requestId = ExternalSignalHistory::requestId($sender->id, $sequence);
        $identity = [
            'sequence' => $sequence,
            'target_workflow_instance_id' => $call->workflowId,
            'signal_name' => $call->signalName,
            'request_id' => $requestId,
        ];

        if ($call->workflowId === $sender->workflow_instance_id) {
            return self::failed(
                $identity,
                null,
                ExternalSignalOutcome::Rejected,
                'self_target',
                null,
                'A workflow cannot signal its own instance as a durable signal-external command.'
            );
        }

        if ($call->signalName === WorkflowStub::MESSAGE_STREAM_RUNTIME_SIGNAL) {
            return self::failed(
                $identity,
                null,
                ExternalSignalOutcome::Rejected,
                'runtime_reserved_signal',
                null,
                'The signal name is reserved for the runtime message stream.'
            );
        }

        /** @var WorkflowInstance|null $instance */
        $instance = ConfiguredV2Models::query('instance_model', WorkflowInstance::class)
            ->find($call->workflowId);

        // Exact identity in the sender's namespace: a collation-folded match
        // or a workflow in another namespace is not this target.
        if (! $instance instanceof WorkflowInstance
            || $instance->id !== $call->workflowId
            || NamespaceScope::canonical(self::stringValue($instance->namespace))
                !== NamespaceScope::canonical(self::stringValue($sender->namespace))) {
            return self::failed(
                $identity,
                null,
                ExternalSignalOutcome::TargetNotFound,
                'instance_not_found',
                null,
                sprintf('No workflow instance [%s] exists in this namespace.', $call->workflowId)
            );
        }

        if ($call->runId !== null) {
            /** @var WorkflowRun|null $run */
            $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->find($call->runId);

            if (! $run instanceof WorkflowRun
                || $run->id !== $call->runId
                || $run->workflow_instance_id !== $instance->id) {
                return self::failed(
                    $identity,
                    null,
                    ExternalSignalOutcome::TargetNotFound,
                    'run_not_found',
                    null,
                    sprintf('Workflow instance [%s] has no run [%s].', $instance->id, $call->runId)
                );
            }
        }

        $stub = WorkflowStub::loadSelection($instance->id, $call->runId)
            ->withCommandContext(CommandContext::workflow($sender->workflow_instance_id, $sender->id, $sequence));
        // The reserved key is admitted only through this delivery.
        $deliver = \Closure::bind(
            static fn (WorkflowStub $target): CommandResult => $target->attemptExternalWorkflowSignal(
                $requestId,
                $call->signalName,
                $call->arguments,
            ),
            null,
            WorkflowStub::class,
        );
        $result = $deliver($stub);

        return self::resolution($identity, $call, $result);
    }

    private static function transient(Throwable $throwable): bool
    {
        if (TransactionFailure::endsTransaction($throwable)) {
            return true;
        }

        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            $message = $current->getMessage();
            if (str_contains($message, 'lock timeout')
                || str_contains($message, 'Lock wait timeout')
                || str_contains($message, '55P03')
                || str_contains($message, 'database is locked')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>}
     */
    private static function resolution(array $identity, SignalExternalWorkflowCall $call, CommandResult $result): array
    {
        if ($result->accepted()) {
            /** @var WorkflowSignal|null $signal */
            $signal = ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
                ->where('workflow_command_id', $result->commandId())
                ->first();

            return [
                'event_type' => HistoryEventType::ExternalSignalDelivered,
                'payload' => [
                    ...$identity,
                    'target_workflow_run_id' => $result->runId(),
                    'outcome' => ExternalSignalOutcome::Delivered->value,
                    'target_command_id' => $result->commandId(),
                    'target_signal_id' => $signal?->id,
                    'retained' => $result->retained(),
                ],
            ];
        }

        $reason = $result->rejectionReason() ?? 'rejected';
        // A key conflict refuses against the retained command and records
        // nothing new on the target.
        $targetCommandId = $result->requestIdConflict() ? null : $result->commandId();
        $targetRunId = $result->requestIdConflict() ? null : $result->runId();

        [$outcome, $reason, $message] = match ($reason) {
            'instance_not_started' => [
                ExternalSignalOutcome::TargetNotFound,
                'instance_not_found',
                sprintf('Workflow instance [%s] has not started.', $call->workflowId),
            ],
            'run_not_active' => [
                ExternalSignalOutcome::TargetClosed,
                'run_not_active',
                'The target run is closed.',
            ],
            'selected_run_not_current' => [
                ExternalSignalOutcome::TargetClosed,
                'historical_run',
                'The requested run has been replaced by a newer run of the target instance.',
            ],
            default => [
                ExternalSignalOutcome::Rejected,
                $reason,
                $result->message() ?? sprintf('The target refused the signal: %s.', $reason),
            ],
        };

        return self::failed($identity, $targetRunId, $outcome, $reason, $targetCommandId, $message);
    }

    /**
     * @param array<string, mixed> $identity
     * @return array{event_type: HistoryEventType, payload: array<string, mixed>}
     */
    private static function failed(
        array $identity,
        ?string $targetRunId,
        ExternalSignalOutcome $outcome,
        string $reason,
        ?string $targetCommandId,
        string $message,
    ): array {
        return [
            'event_type' => HistoryEventType::ExternalSignalFailed,
            'payload' => [
                ...$identity,
                'target_workflow_run_id' => $targetRunId,
                'outcome' => $outcome->value,
                'reason' => $reason,
                'message' => $message,
                'target_command_id' => $targetCommandId,
            ],
        ];
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
