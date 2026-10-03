<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Str;
use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/**
 * @internal Canonical scope registration kernel. No scope API/capability is advertised.
 * Request, delivery, operation membership and portable consumers are separate gates.
 */
final class CancellationScopeHistory
{
    public const ROOT_SCOPE_ID = 'root';

    public const SCHEMA = 'durable-workflow.cancellation-scope/v1';

    public const MINIMUM_PROTOCOL_VERSION = '1.20';

    /**
     * The caller supplies its authenticated workflow claim. Re-read that claim
     * under the run lock so response-loss retries cannot open another scope.
     */
    public static function open(
        WorkflowRun $run,
        WorkflowTask $task,
        int $sequence,
        string $protocolVersion,
        string $parentScopeId = self::ROOT_SCOPE_ID,
        bool $shieldParent = false,
    ): WorkflowHistoryEvent {
        if (preg_match('/^[0-9]+\.[0-9]+$/D', $protocolVersion) !== 1
            || version_compare($protocolVersion, self::MINIMUM_PROTOCOL_VERSION, '<')) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        if ($sequence < 1 || $parentScopeId === '' || ! $run->exists || ! $task->exists) {
            throw new LogicException('invalid_cancellation_scope_open');
        }

        $event = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->getModel()
            ->getConnection()
            ->transaction(static function () use (
                $run,
                $task,
                $sequence,
                $parentScopeId,
                $shieldParent
            ): WorkflowHistoryEvent {
                /** @var WorkflowRun $lockedRun */
                $lockedRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()->findOrFail(
                    $run->id
                );
                /** @var WorkflowTask|null $claim */
                $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find($task->id);
                if (! $claim instanceof WorkflowTask || $claim->workflow_run_id !== $lockedRun->id
                    || $claim->namespace !== $lockedRun->namespace || $claim->task_type !== TaskType::Workflow
                    || $claim->status !== TaskStatus::Leased || $claim->lease_owner === null || $claim->lease_owner === ''
                    || $claim->attempt_count < 1
                    || $claim->lease_owner !== $task->lease_owner || $claim->attempt_count !== $task->attempt_count
                    || $claim->lease_expires_at === null || now()
                        ->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                if ($lockedRun->status->isTerminal()) {
                    throw new LogicException('cancellation_scope_run_not_active');
                }
                $scopes = self::forRun($lockedRun);
                foreach ($scopes as $scope) {
                    if ($scope['sequence'] === $sequence) {
                        if ($scope['parent_scope_id'] !== $parentScopeId || $scope['shield_parent'] !== $shieldParent) {
                            throw new HistoryEventShapeMismatchException(
                                $sequence,
                                WorkflowStepHistory::CANCELLATION_SCOPE,
                                [HistoryEventType::CancellationScopeOpened->value],
                                'Recorded cancellation scope parent or shield mode differs from replay.',
                            );
                        }
                        return $lockedRun->historyEvents()
                            ->whereKey($scope['history_event_id'])->firstOrFail();
                    }
                }
                if ($lockedRun->cancellation_request_command_id !== null
                    || ($lockedRun->execution_deadline_at !== null && now()->gte($lockedRun->execution_deadline_at))
                    || ($lockedRun->run_deadline_at !== null && now()->gte($lockedRun->run_deadline_at))) {
                    throw new LogicException('cancellation_scope_run_not_active');
                }
                if ($parentScopeId !== self::ROOT_SCOPE_ID && ! isset($scopes[$parentScopeId])) {
                    throw new LogicException('cancellation_scope_parent_not_recorded');
                }
                WorkflowStepHistory::assertCompatible($lockedRun, $sequence, WorkflowStepHistory::CANCELLATION_SCOPE);
                if ($sequence !== WorkflowStepHistory::nextDurableCommandSequence($lockedRun)) {
                    throw new LogicException('cancellation_scope_sequence_mismatch');
                }
                return WorkflowHistoryEvent::record($lockedRun, HistoryEventType::CancellationScopeOpened, [
                    'schema' => self::SCHEMA,
                    'workflow_run_id' => $lockedRun->id,
                    'sequence' => $sequence,
                    'scope_id' => (string) Str::ulid(),
                    'parent_scope_id' => $parentScopeId,
                    'shield_parent' => $shieldParent,
                ], $claim);
            }, 3);

        $run->refresh();
        return $event;
    }

    /**
     * Read exact-run canonical addresses without following an instance's current run.
     * Parent-before-child validation makes malformed forward edges and cycles invalid.
     *
     * @return array<string, array{scope_id: string, parent_scope_id: string, shield_parent: bool, sequence: int, history_event_id: string}>
     */
    public static function forRun(WorkflowRun $run): array
    {
        $scopes = [];
        $sequences = [];
        foreach ($run->historyEvents()->where('event_type', HistoryEventType::CancellationScopeOpened)
            ->orderBy('sequence')
            ->cursor() as $event) {
            $payload = $event->payload;
            $id = $payload['scope_id'] ?? null;
            $parent = $payload['parent_scope_id'] ?? null;
            $shield = $payload['shield_parent'] ?? null;
            $sequence = $payload['sequence'] ?? null;
            if (($payload['schema'] ?? null) !== self::SCHEMA || ($payload['workflow_run_id'] ?? null) !== $run->id
                || ! is_string($id) || $id === '' || $id === self::ROOT_SCOPE_ID || isset($scopes[$id])
                || ! is_string($parent) || $parent === '' || ! is_bool($shield)
                || ! is_int($sequence) || $sequence < 1 || isset($sequences[$sequence])
                || ($parent !== self::ROOT_SCOPE_ID && (! isset($scopes[$parent]) || $scopes[$parent]['sequence'] >= $sequence))) {
                throw new LogicException('cancellation_scope_history_invalid');
            }
            $scopes[$id] = [
                'scope_id' => $id,
                'parent_scope_id' => $parent,
                'shield_parent' => $shield,
                'sequence' => $sequence,
                'history_event_id' => $event->id,
            ];
            $sequences[$sequence] = true;
        }
        return $scopes;
    }

    /**
     * @return list<string>
     */
    public static function ancestry(WorkflowRun $run, string $scopeId): array
    {
        $scopes = self::forRun($run);
        $ancestry = [];
        while ($scopeId !== self::ROOT_SCOPE_ID) {
            if (! isset($scopes[$scopeId])) {
                throw new LogicException('cancellation_scope_not_recorded');
            }
            $ancestry[] = $scopeId;
            $scopeId = $scopes[$scopeId]['parent_scope_id'];
        }
        $ancestry[] = self::ROOT_SCOPE_ID;
        return array_reverse($ancestry);
    }
}
