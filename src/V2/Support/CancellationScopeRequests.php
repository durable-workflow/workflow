<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\CommandContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\ScopedCancellationContext;

/**
 * @internal Canonical request authority for operation scopes, not a public API.
 * This records requests without delivering, stopping callbacks or cancelling runs.
 */
final class CancellationScopeRequests
{
    public const SCHEMA = 'durable-workflow.cancellation-scope-request/v1';

    /**
     * A direct retry returns the accepted event, even after the run has closed.
     * Inheritance names the immediate canonical parent, never caller-supplied metadata.
     * Competing roots return a distinct conflict event without replacing acceptance.
     */
    public static function request(
        WorkflowRun $run,
        string $scopeId,
        string $protocolVersion,
        int $cleanupTimeoutSeconds = 30,
        ?string $reason = null,
        ?CommandContext $caller = null,
        ?string $parentScopeId = null,
    ): WorkflowHistoryEvent {
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        if ($cleanupTimeoutSeconds < 1 || $cleanupTimeoutSeconds > 3600 || ! $run->exists) {
            throw new LogicException('invalid_scope_cancellation_request');
        }

        return $run->getConnection()
            ->transaction(static function () use (
                $run,
                $scopeId,
                $cleanupTimeoutSeconds,
                $reason,
                $caller,
                $parentScopeId
            ): WorkflowHistoryEvent {
                // Claims and heartbeat lock task before run. Acceptance must
                // establish recoverable ownership before a worker prepares delivery.
                $claims = ConfiguredV2Models::query('task_model', WorkflowTask::class)
                    ->where('workflow_run_id', $run->id)
                    ->where('task_type', TaskType::Workflow)
                    ->where('status', TaskStatus::Leased)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                /** @var WorkflowRun $locked */
                $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($run->id);
                $scopes = CancellationScopeHistory::forRun($locked);
                self::assertScope($scopes, $scopeId);
                $parent = $parentScopeId === null ? null : self::parentContext($locked, $scopeId, $parentScopeId);
                $existing = self::acceptedEvent($locked, $scopeId);
                $accepted = $existing === null ? null : self::context($locked, $scopeId);
                if ($existing !== null && ($parent === null
                    || $accepted?->rootContext->rootRequestId === $parent->rootContext->rootRequestId)) {
                    return $existing;
                }
                if ($accepted !== null && $parent !== null) {
                    foreach ($locked->historyEvents()->where(
                        'event_type',
                        HistoryEventType::CancellationScopeRequestConflicted
                    )
                        ->get() as $conflict) {
                        $payload = $conflict->payload;
                        if (($payload['scope_id'] ?? null) === $scopeId && ($payload['parent_scope_id'] ?? null) === $parentScopeId
                            && ($payload['incoming_cancellation']['root_context']['root_request_id'] ?? null) === $parent->rootContext->rootRequestId) {
                            $priorAccepted = ScopedCancellationContext::fromArray($payload['accepted_cancellation']);
                            $priorIncoming = ScopedCancellationContext::fromArray($payload['incoming_cancellation']);
                            if ($priorAccepted->toArray() !== $accepted->toArray()
                                || $priorIncoming->toArray() !== $parent->forDescendant(
                                    $priorIncoming->requestId,
                                    $locked->workflow_instance_id,
                                    $locked->id,
                                    $scopeId
                                )->toArray()) {
                                throw new LogicException('cancellation_scope_request_history_invalid');
                            }
                            return $conflict;
                        }
                    }
                }

                $requestId = (string) Str::ulid();
                $incoming = $parent?->forDescendant($requestId, $locked->workflow_instance_id, $locked->id, $scopeId)
                    ?? self::directContext($locked, $scopeId, $requestId, $cleanupTimeoutSeconds, $reason, $caller);
                if ($accepted !== null) {
                    return WorkflowHistoryEvent::record($locked, HistoryEventType::CancellationScopeRequestConflicted, [
                        'schema' => self::SCHEMA,
                        'workflow_run_id' => $locked->id,
                        'scope_id' => $scopeId,
                        'parent_scope_id' => $parentScopeId,
                        'reason' => 'cancellation_root_conflict',
                        'accepted_cancellation' => $accepted->toArray(),
                        'incoming_cancellation' => $incoming->toArray(),
                    ]);
                }

                $authority = self::authoritySnapshot($locked, $scopeId);
                if (! $authority['active'] || now()->gte($incoming->deadline())) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $event = WorkflowHistoryEvent::record($locked, HistoryEventType::CancellationScopeRequested, [
                    'schema' => self::SCHEMA,
                    'workflow_run_id' => $locked->id,
                    'scope_id' => $scopeId,
                    'parent_scope_id' => $parentScopeId,
                    'request_id' => $requestId,
                    'cancellation' => $incoming->toArray(),
                ]);
                $ceiling = $incoming->deadline();
                if ($authority['deadline_at'] !== null) {
                    $authorityDeadline = CarbonImmutable::parse($authority['deadline_at']);
                    if ($authorityDeadline->lt($ceiling)) {
                        $ceiling = $authorityDeadline;
                    }
                }
                if ($locked->cancellation_scope_recovery_until === null || $ceiling->gt(
                    $locked->cancellation_scope_recovery_until
                )) {
                    $locked->forceFill([
                        'cancellation_scope_recovery_until' => $ceiling,
                    ])->save();
                }
                $expiry = CancellationCleanupLease::expiresAt($locked);
                /** @var WorkflowTask $claim */
                foreach ($claims as $claim) {
                    if ($claim->lease_expires_at === null || now()->gte($claim->lease_expires_at)
                        || ! $expiry->lt($claim->lease_expires_at)) {
                        continue;
                    }
                    $claim->forceFill([
                        'lease_expires_at' => $expiry,
                    ])->save();
                    WorkflowRunSummary::query()->whereKey($locked->id)->where('next_task_id', $claim->id)->update([
                        'next_task_lease_expires_at' => $expiry,
                    ]);
                }
                return $event;
            }, 3);
    }

    /**
     * Read the accepted canonical address, including after a cold reload.
     */
    public static function context(WorkflowRun $run, string $scopeId): ?ScopedCancellationContext
    {
        self::assertScope(CancellationScopeHistory::forRun($run), $scopeId);
        $event = self::acceptedEvent($run, $scopeId);
        if ($event === null) {
            return null;
        }
        $payload = $event->payload;
        if (($payload['schema'] ?? null) !== self::SCHEMA || ($payload['workflow_run_id'] ?? null) !== $run->id
            || ! is_array($payload['cancellation'] ?? null) || ! array_key_exists('parent_scope_id', $payload)) {
            throw new LogicException('cancellation_scope_request_history_invalid');
        }
        $context = ScopedCancellationContext::fromArray($payload['cancellation']);
        if ($context->scopeId !== $scopeId || $context->workflowRunId !== $run->id
            || $context->workflowInstanceId !== $run->workflow_instance_id
            || $context->requestId !== ($payload['request_id'] ?? null)) {
            throw new LogicException('cancellation_scope_request_history_invalid');
        }
        $parentScopeId = $payload['parent_scope_id'];
        if ($parentScopeId === null) {
            if (count($context->lineage) !== 1 || $context->rootContext->requestId !== $context->requestId) {
                throw new LogicException('cancellation_scope_request_history_invalid');
            }
        } else {
            if (! is_string($parentScopeId)) {
                throw new LogicException('cancellation_scope_request_history_invalid');
            }
            $parent = self::parentContext($run, $scopeId, $parentScopeId);
            $expected = $parent->forDescendant($context->requestId, $run->workflow_instance_id, $run->id, $scopeId);
            if ($context->toArray() !== $expected->toArray()) {
                throw new LogicException('cancellation_scope_request_history_invalid');
            }
        }
        return $context;
    }

    /**
     * Inspect authority separately from the immutable accepted request budget.
     * Shields defer delivery; they do not remove ancestor/run authority ceilings.
     *
     * @return array{active: bool, deadline_at: string|null}
     */
    public static function authority(WorkflowRun $run, string $scopeId): array
    {
        return $run->getConnection()
            ->transaction(static function () use ($run, $scopeId): array {
                /** @var WorkflowRun $locked */
                $locked = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                    ->lockForUpdate()
                    ->findOrFail($run->id);
                return self::authoritySnapshot($locked, $scopeId);
            });
    }

    /**
     * @return array{active: bool, deadline_at: string|null}
     */
    private static function authoritySnapshot(WorkflowRun $run, string $scopeId): array
    {
        $ancestry = CancellationScopeHistory::ancestry($run, $scopeId);
        self::assertScope(CancellationScopeHistory::forRun($run), $scopeId);
        $deadline = null;
        $ceilings = [$run->execution_deadline_at, $run->run_deadline_at, self::runContext($run)?->deadline()];
        foreach ($ancestry as $ancestor) {
            if ($ancestor !== CancellationScopeHistory::ROOT_SCOPE_ID) {
                $ceilings[] = self::context($run, $ancestor)?->deadline();
            }
        }
        foreach ($ceilings as $ceiling) {
            if ($ceiling !== null && ($deadline === null || $ceiling->lt($deadline))) {
                $deadline = CarbonImmutable::instance($ceiling);
            }
        }
        return [
            'active' => ! $run->status->isTerminal() && ($deadline === null || now()->lt($deadline)),
            'deadline_at' => $deadline?->toISOString(),
        ];
    }

    /**
     * @param array<string, array{scope_id: string, parent_scope_id: string, shield_parent: bool, sequence: int, history_event_id: string}> $scopes
     */
    private static function assertScope(array $scopes, string $scopeId): void
    {
        if ($scopeId === CancellationScopeHistory::ROOT_SCOPE_ID) {
            throw new LogicException('root_scope_requires_run_cancellation');
        }
        if (! isset($scopes[$scopeId])) {
            throw new LogicException('cancellation_scope_not_recorded');
        }
    }

    private static function acceptedEvent(WorkflowRun $run, string $scopeId): ?WorkflowHistoryEvent
    {
        $matching = $run->historyEvents()
            ->where('event_type', HistoryEventType::CancellationScopeRequested)
            ->get()
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => ($event->payload['scope_id'] ?? null) === $scopeId
            );
        if ($matching->count() > 1) {
            throw new LogicException('cancellation_scope_request_history_invalid');
        }
        return $matching->first();
    }

    private static function parentContext(
        WorkflowRun $run,
        string $scopeId,
        string $parentScopeId
    ): ScopedCancellationContext {
        $scope = CancellationScopeHistory::forRun($run)[$scopeId] ?? null;
        if ($scope === null || $scope['parent_scope_id'] !== $parentScopeId) {
            throw new LogicException('cancellation_scope_parent_mismatch');
        }
        $parent = $parentScopeId === CancellationScopeHistory::ROOT_SCOPE_ID
            ? (($context = self::runContext($run)) === null ? null : ScopedCancellationContext::fromRunContext(
                $context
            ))
            : self::context($run, $parentScopeId);
        if ($parent === null) {
            throw new LogicException('cancellation_scope_parent_request_unavailable');
        }
        return $parent;
    }

    private static function runContext(WorkflowRun $run): ?CancellationContext
    {
        if ($run->cancellation_request_command_id === null) {
            return null;
        }
        /** @var WorkflowCommand|null $command */
        $command = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->find($run->cancellation_request_command_id);
        $context = $command === null ? null : (new CommandResult($command))->cancellationContext();
        $last = $context === null ? null : $context->lineage[count($context->lineage) - 1];
        if ($command === null || $command->status !== CommandStatus::Accepted
            || $command->command_type !== CommandType::RequestCancellation || $command->workflow_run_id !== $run->id
            || $context === null || $context->requestId !== $command->id
            || $last['workflow_run_id'] !== $run->id || $last['workflow_instance_id'] !== $run->workflow_instance_id
            || $run->cancellation_deadline_at === null || ! $context->deadline()
                ->equalTo($run->cancellation_deadline_at)) {
            throw new LogicException('cancellation_scope_run_context_mismatch');
        }
        return $context;
    }

    private static function directContext(
        WorkflowRun $run,
        string $scopeId,
        string $requestId,
        int $timeout,
        ?string $reason,
        ?CommandContext $caller
    ): ScopedCancellationContext {
        $attributes = ($caller ?? CommandContext::phpApi())->attributes();
        $requestedAt = CarbonImmutable::instance(now());
        $root = CancellationContext::fromArray([
            'schema' => 'durable-workflow.cancellation-context/v1',
            'request_id' => $requestId,
            'root_request_id' => $requestId,
            'root_workflow_instance_id' => $run->workflow_instance_id,
            'root_workflow_run_id' => $run->id,
            'parent_request_id' => null,
            'reason' => $reason,
            'requester' => array_intersect_key(
                $attributes['context']['principal'] ?? $attributes['context']['caller'],
                array_flip(['type', 'id', 'label']),
            ),
            'source' => $attributes['source'],
            'requested_at' => $requestedAt->toISOString(),
            'cleanup_deadline_at' => $requestedAt->addSeconds($timeout)
                ->toISOString(),
            'lineage' => [[
                'request_id' => $requestId,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_run_id' => $run->id,
            ]],
        ]);
        return ScopedCancellationContext::fromArray([
            'schema' => ScopedCancellationContext::SCHEMA,
            'root_context' => $root->toArray(),
            'lineage' => [[
                ...$root->lineage[0],
                'scope_id' => $scopeId,
                'cleanup_deadline_at' => $root->deadline()
                    ->toISOString(),
            ]],
        ]);
    }
}
