<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;

final class PendingMessageTask
{
    public static function createForRun(
        WorkflowRun $run,
        ?string $alreadyAttemptedSignalId = null,
        bool $includeReceivedProjectedSignalWait = true,
    ): ?WorkflowTask {
        if (self::hasOpenWorkflowTask($run->id)) {
            return null;
        }

        $signal = self::nextEligibleSignal(
            $run,
            $alreadyAttemptedSignalId,
            $includeReceivedProjectedSignalWait,
        );
        $update = self::nextReadyUpdate($run);

        if ($signal instanceof WorkflowSignal && self::signalPrecedesUpdate($signal, $update)) {
            return self::createSignalTask($run, $signal);
        }

        if ($update instanceof WorkflowUpdate) {
            return self::createUpdateTask($run, $update);
        }

        if ($signal instanceof WorkflowSignal) {
            return self::createSignalTask($run, $signal);
        }

        return null;
    }

    private static function nextEligibleSignal(
        WorkflowRun $run,
        ?string $alreadyAttemptedSignalId,
        bool $includeReceivedProjectedSignalWait,
    ): ?WorkflowSignal {
        $signals = ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
            ->where('workflow_run_id', $run->id)
            ->where('status', SignalStatus::Received->value)
            ->whereNull('closed_at')
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('received_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $freshRun = $run->fresh(['historyEvents']) ?? $run;
        $hasAdvanceableConditionWait = self::hasAdvanceableConditionWait($freshRun);
        $advanceableSignalWaitsById = self::advanceableSignalWaitsById(
            $freshRun,
            $includeReceivedProjectedSignalWait,
        );
        $unprojectedSignalContract = self::unprojectedSignalContract($freshRun);
        $afterAttemptedSignal = $alreadyAttemptedSignalId === null;
        $firstEligibleSignal = null;

        foreach ($signals as $signal) {
            if (! $signal instanceof WorkflowSignal || ! self::signalCanAdvanceRun(
                $signal,
                $hasAdvanceableConditionWait,
                $advanceableSignalWaitsById,
                $unprojectedSignalContract,
            )) {
                continue;
            }

            $firstEligibleSignal ??= $signal;

            if ($afterAttemptedSignal) {
                return $signal;
            }

            if ($signal->id === $alreadyAttemptedSignalId) {
                $afterAttemptedSignal = true;
            }
        }

        return $afterAttemptedSignal ? null : $firstEligibleSignal;
    }

    private static function nextReadyUpdate(WorkflowRun $run): ?WorkflowUpdate
    {
        /** @var WorkflowUpdate|null $update */
        $update = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->where('workflow_run_id', $run->id)
            ->where('status', UpdateStatus::Accepted->value)
            ->orderByRaw('CASE WHEN command_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('command_sequence')
            ->orderBy('accepted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if (! $update instanceof WorkflowUpdate) {
            return null;
        }

        return UpdateCommandGate::blockingSignal($run, $update->command_sequence) === null
            ? $update
            : null;
    }

    private static function signalPrecedesUpdate(
        WorkflowSignal $signal,
        ?WorkflowUpdate $update,
    ): bool {
        if (! $update instanceof WorkflowUpdate) {
            return true;
        }

        $signalSequence = $signal->command_sequence ?? PHP_INT_MAX;
        $updateSequence = $update->command_sequence ?? PHP_INT_MAX;

        if ($signalSequence !== $updateSequence) {
            return $signalSequence < $updateSequence;
        }

        $signalAcceptedAt = $signal->received_at?->getTimestampMs() ?? PHP_INT_MAX;
        $updateAcceptedAt = $update->accepted_at?->getTimestampMs() ?? PHP_INT_MAX;

        if ($signalAcceptedAt !== $updateAcceptedAt) {
            return $signalAcceptedAt < $updateAcceptedAt;
        }

        return $signal->id < $update->id;
    }

    private static function createSignalTask(WorkflowRun $run, WorkflowSignal $signal): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->create([
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

        return $task;
    }

    private static function createUpdateTask(WorkflowRun $run, WorkflowUpdate $update): WorkflowTask
    {
        /** @var WorkflowTask $task */
        $task = ConfiguredV2Models::query('task_model', WorkflowTask::class)->create([
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

        return $task;
    }

    private static function signalCanAdvanceRun(
        WorkflowSignal $signal,
        bool $hasAdvanceableConditionWait,
        array $advanceableSignalWaitsById,
        ?array $unprojectedSignalContract,
    ): bool {
        if ($hasAdvanceableConditionWait) {
            return true;
        }

        $signalWaitId = self::nonEmptyString($signal->signal_wait_id);

        if ($signalWaitId !== null
            && ($advanceableSignalWaitsById[$signalWaitId] ?? null) === $signal->signal_name
        ) {
            return true;
        }

        if ($unprojectedSignalContract === null) {
            return false;
        }

        $declaredSignals = $unprojectedSignalContract['signals'] ?? [];

        return ($unprojectedSignalContract['source'] ?? null) === RunCommandContract::SOURCE_DURABLE_HISTORY
            && is_array($declaredSignals)
            && in_array($signal->signal_name, $declaredSignals, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function unprojectedSignalContract(WorkflowRun $run): ?array
    {
        if (WorkflowExecutionGate::blockedReason($run)
            !== WorkflowExecutionGate::BLOCKED_WORKFLOW_DEFINITION_UNAVAILABLE
        ) {
            return null;
        }

        return RunCommandContract::forRun($run);
    }

    private static function hasAdvanceableConditionWait(WorkflowRun $run): bool
    {
        foreach (ConditionWaits::forRun($run) as $wait) {
            if (($wait['status'] ?? null) === 'open' && ($wait['source_status'] ?? null) !== 'timeout_fired') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function advanceableSignalWaitsById(
        WorkflowRun $run,
        bool $includeReceivedProjectedSignalWait,
    ): array
    {
        $waits = [];

        foreach (SignalWaits::forRun($run) as $wait) {
            $isOpen = ($wait['status'] ?? null) === 'open';
            $isReceived = $includeReceivedProjectedSignalWait
                && ($wait['status'] ?? null) === 'resolved'
                && ($wait['source_status'] ?? null) === 'received';

            if (! $isOpen && ! $isReceived) {
                continue;
            }

            $signalWaitId = self::nonEmptyString($wait['signal_wait_id'] ?? null);
            $signalName = self::nonEmptyString($wait['signal_name'] ?? null);

            if ($signalWaitId !== null && $signalName !== null) {
                $waits[$signalWaitId] = $signalName;
            }
        }

        return $waits;
    }

    private static function hasOpenWorkflowTask(string $runId): bool
    {
        return ConfiguredV2Models::query('task_model', WorkflowTask::class)
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->exists();
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
