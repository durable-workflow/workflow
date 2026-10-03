<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/** @internal Freeze child targets from typed history before dispatching effects. */
final class ScopedChildCancellation
{
    /**
     * @return list<array{sequence: int, child_call_id: string, child_workflow_instance_id: string, child_workflow_run_id: string, cancellation_policy: string, descriptor_hash: string}>
     */
    public static function members(WorkflowRun $run, string $scopeId): array
    {
        $run->loadMissing('historyEvents');
        $members = [];
        $calls = [];
        $sequences = [];
        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if ($event->event_type !== HistoryEventType::ChildWorkflowScheduled) {
                continue;
            }
            $payload = $event->payload;
            $descriptor = $payload['child_workflow'] ?? [];
            if (! is_array($descriptor)) {
                throw new LogicException('cancellation_scope_child_history_invalid');
            }
            $nested = $descriptor['cancellation_scope_id'] ?? CancellationScopeHistory::ROOT_SCOPE_ID;
            $address = $payload['cancellation_scope_id'] ?? $nested;
            if ($address !== $scopeId && $nested !== $scopeId) {
                continue;
            }
            if ($address !== $scopeId || (array_key_exists('cancellation_scope_id', $descriptor)
                && $nested !== $scopeId)) {
                throw new LogicException('cancellation_scope_delivery_membership_mismatch');
            }
            $sequence = $payload['sequence'] ?? null;
            $callId = $payload['child_call_id'] ?? null;
            $instanceId = $payload['child_workflow_instance_id'] ?? null;
            $runId = $payload['child_workflow_run_id'] ?? null;
            $policy = $payload['cancellation_policy'] ?? CancellationPolicy::Abandon->value;
            if (! is_int($sequence) || $sequence < 1 || ! self::text($callId) || ! self::text($instanceId)
                || ! self::text($runId) || ! is_string($policy) || CancellationPolicy::tryFrom($policy) === null
                || $runId === $run->id || isset($calls[$callId]) || isset($sequences[$sequence])) {
                throw new LogicException('cancellation_scope_child_history_invalid');
            }
            // A continued/retried child is selected only from history that was
            // committed before preparation. Mutable links and current_run_id
            // must never substitute a newer target during cold replay.
            $started = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $row): bool =>
                $row->event_type === HistoryEventType::ChildRunStarted
                && ($row->payload['sequence'] ?? null) === $sequence)->sortBy('sequence');
            foreach ($started as $row) {
                $next = $row->payload;
                if ($row->sequence <= $event->sequence || ($next['child_call_id'] ?? null) !== $callId
                    || ($next['child_workflow_instance_id'] ?? null) !== $instanceId
                    || ! self::text($next['child_workflow_run_id'] ?? null)
                    || $next['child_workflow_run_id'] === $run->id
                    || ($next['cancellation_scope_id'] ?? $address) !== $address
                    || ($next['cancellation_policy'] ?? $policy) !== $policy) {
                    throw new LogicException('cancellation_scope_child_history_invalid');
                }
                $runId = $next['child_workflow_run_id'];
            }
            $calls[$callId] = true;
            $sequences[$sequence] = true;
            $members[] = [
                'sequence' => $sequence,
                'child_call_id' => $callId,
                'child_workflow_instance_id' => $instanceId,
                'child_workflow_run_id' => $runId,
                'cancellation_policy' => $policy,
                'descriptor_hash' => hash('sha256', json_encode([
                    $scopeId, $event->id, $sequence, $callId, $instanceId, $runId, $policy,
                    $payload['parent_close_policy'] ?? null, $payload['child_workflow_type'] ?? null,
                    $started->last()?->id, ParallelChildGroup::metadataPathFromPayload($payload),
                ], JSON_THROW_ON_ERROR)),
            ];
        }
        return $members;
    }

    /**
     * @return list<array{sequence: int, child_call_id: string, child_workflow_instance_id: string, child_workflow_run_id: string, cancellation_policy: string, descriptor_hash: string}>
     */
    public static function normalizeMembers(mixed $members): array
    {
        if (! is_array($members) || ! array_is_list($members)) {
            throw new LogicException('cancellation_scope_preparation_history_invalid');
        }
        $normalized = [];
        foreach ($members as $member) {
            if (! is_array($member) || count($member) !== 6 || ! is_int($member['sequence'] ?? null)
                || $member['sequence'] < 1 || ! self::text($member['child_call_id'] ?? null)
                || ! self::text($member['child_workflow_instance_id'] ?? null)
                || ! self::text($member['child_workflow_run_id'] ?? null)
                || ! is_string($member['cancellation_policy'] ?? null)
                || CancellationPolicy::tryFrom($member['cancellation_policy']) === null
                || ! is_string($member['descriptor_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $member['descriptor_hash']) !== 1) {
                throw new LogicException('cancellation_scope_preparation_history_invalid');
            }
            $normalized[] = [
                'sequence' => $member['sequence'],
                'child_call_id' => $member['child_call_id'],
                'child_workflow_instance_id' => $member['child_workflow_instance_id'],
                'child_workflow_run_id' => $member['child_workflow_run_id'],
                'cancellation_policy' => $member['cancellation_policy'],
                'descriptor_hash' => $member['descriptor_hash'],
            ];
        }
        return $normalized;
    }

    /**
     * A preparation snapshot is not proof that the child actor committed.
     */
    public static function assertReady(WorkflowHistoryEvent $preparation): void
    {
        if (self::normalizeMembers($preparation->payload['child_members']) !== []) {
            throw new LogicException('cancellation_scope_child_delivery_not_established');
        }
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
