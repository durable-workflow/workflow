<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\CommandResult;
use Workflow\V2\Enums\CancellationPolicy;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\ScopedCancellationContext;
use Workflow\V2\WorkflowStub;

/** @internal Own the child request and parent receipt in one independent commit. */
final class ScopedChildCancellationDelivery
{
    public const SCHEMA = 'durable-workflow.scoped-child-cancellation/v1';

    /**
     * @return array<string, mixed>
     */
    public static function request(
        WorkflowRun $run,
        WorkflowTask $workflowTask,
        string $childCallId,
        string $scopeId,
        string $requestId,
        string $protocolVersion,
        ?string $preparationHistoryEventId = null,
    ): array {
        if (! WorkerProtocolVersion::supportsCancellationScopeMembership($protocolVersion)) {
            throw new LogicException('cancellation_scope_requires_protocol_1_20');
        }
        if ($run->getConnection()->transactionLevel() !== 0) {
            throw new LogicException('cancellation_scope_child_requires_own_transaction');
        }
        return $run->getConnection()
            ->transaction(static function () use (
                $run,
                $workflowTask,
                $childCallId,
                $scopeId,
                $requestId,
                $preparationHistoryEventId
            ): array {
                /** @var WorkflowRun $parent */
                $parent = ConfiguredV2Models::query('run_model', WorkflowRun::class)->lockForUpdate()->findOrFail(
                    $run->id
                );
                /** @var WorkflowTask|null $claim */
                $claim = ConfiguredV2Models::query('task_model', WorkflowTask::class)->lockForUpdate()->find(
                    $workflowTask->id
                );
                if ($claim === null || $claim->workflow_run_id !== $parent->id || $claim->namespace !== $parent->namespace
                    || $claim->task_type !== TaskType::Workflow || $claim->status !== TaskStatus::Leased
                    || $claim->lease_owner === null || $claim->lease_owner === '' || $claim->attempt_count < 1
                    || $claim->lease_owner !== $workflowTask->lease_owner || $claim->attempt_count !== $workflowTask->attempt_count
                    || $claim->lease_expires_at === null || now()
                        ->gte($claim->lease_expires_at)) {
                    throw new LogicException('cancellation_scope_workflow_claim_mismatch');
                }
                $preparation = ScopedCancellationPreparation::forScope($parent, $scopeId, $preparationHistoryEventId);
                $origin = CancellationScopeRequests::context($parent, $scopeId);
                $authority = CancellationScopeRequests::authority($parent, $scopeId);
                if ($origin === null || $origin->requestId !== $requestId) {
                    throw new LogicException('cancellation_scope_request_mismatch');
                }
                if ($preparation === null) {
                    throw new LogicException('cancellation_scope_delivery_not_prepared');
                }
                if (! $authority['active'] || now()->gte($origin->deadline())
                    || now()
                        ->gte(CarbonImmutable::parse($preparation->authorityDeadlineAt))) {
                    throw new LogicException('cancellation_scope_authority_expired');
                }
                $member = collect($preparation->childMembers)
                    ->firstWhere('child_call_id', $childCallId);
                if ($member === null) {
                    throw new LogicException('cancellation_scope_child_target_mismatch');
                }
                $policy = CancellationPolicy::from($member['cancellation_policy']);
                $child = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find(
                    $member['child_workflow_run_id']
                );
                if ($child === null || $child->workflow_instance_id !== $member['child_workflow_instance_id']
                    || $child->namespace !== $parent->namespace) {
                    throw new LogicException('cancellation_scope_child_target_mismatch');
                }
                $base = self::base($parent, $preparation, $member);
                $receipt = self::receipt(
                    $parent,
                    $preparation,
                    $childCallId,
                    HistoryEventType::ChildCancellationRequested
                );
                $terminal = ChildRunHistory::terminalEventForRun($child);
                if ($receipt === null) {
                    $context = null;
                    $outcome = $policy === CancellationPolicy::Abandon ? 'abandoned' : 'already_terminal';
                    if ($policy !== CancellationPolicy::Abandon && $terminal === null) {
                        $request = WorkflowStub::loadRun($child->id)->attemptRequestCancellationFromScope(
                            $parent->id,
                            $scopeId,
                            $preparation->historyEventId,
                            $childCallId
                        );
                        if ($request->rejected()) {
                            throw new LogicException(
                                'cancellation_scope_child_request_refused:' . $request->rejectionReason()
                            );
                        }
                        $context = $request->cancellationContext();
                        $outcome = 'accepted';
                        // The child request rechecks parent authority after taking child locks.
                        $child->refresh();
                        $terminal = ChildRunHistory::terminalEventForRun($child);
                    }
                    // A child-instance lock can outlast the hosting lease or budget.
                    $authority = CancellationScopeRequests::authority($parent, $scopeId);
                    if (! $authority['active'] || now()->gte($origin->deadline())
                        || now()
                            ->gte($claim->lease_expires_at)
                        || now()
                            ->gte(CarbonImmutable::parse($preparation->authorityDeadlineAt))) {
                        throw new LogicException('cancellation_scope_authority_expired');
                    }
                    $receipt = WorkflowHistoryEvent::record($parent, HistoryEventType::ChildCancellationRequested, [
                        ...$base,
                        'child_request_id' => $context?->requestId,
                        'child_root_request_id' => $context?->rootRequestId,
                        'child_cleanup_deadline_at' => $context?->deadline()
                            ->toISOString(),
                        'child_cancellation' => $context?->toArray(),
                        'request_outcome' => $outcome,
                        'rejection_reason' => null,
                    ], $claim);
                    $parent->historyEvents->push($receipt);
                }
                self::assertRequest($parent, $preparation, $member, $receipt);
                $resolved = self::receipt(
                    $parent,
                    $preparation,
                    $childCallId,
                    HistoryEventType::ChildCancellationResolved
                );
                if ($policy !== CancellationPolicy::Abandon && $terminal !== null && $resolved === null) {
                    $authority = CancellationScopeRequests::authority($parent, $scopeId);
                    if (! $authority['active'] || now()->gte($origin->deadline())
                        || now()
                            ->gte($claim->lease_expires_at)
                        || now()
                            ->gte(CarbonImmutable::parse($preparation->authorityDeadlineAt))) {
                        throw new LogicException('cancellation_scope_authority_expired');
                    }
                    $resolved = WorkflowHistoryEvent::record($parent, HistoryEventType::ChildCancellationResolved, [
                        ...$base,
                        'child_request_id' => $receipt->payload['child_request_id'],
                        'child_root_request_id' => $receipt->payload['child_root_request_id'],
                        'child_cleanup_deadline_at' => $receipt->payload['child_cleanup_deadline_at'],
                        'child_status' => ChildRunHistory::resolvedStatus(null, $child)?->value,
                        'child_terminal_history_event_id' => $terminal->id,
                        'child_terminal_event_type' => $terminal->event_type->value,
                        'reason' => $terminal->payload['reason'] ?? null,
                    ], $claim);
                    $parent->historyEvents->push($resolved);
                }
                return [
                    'child_call_id' => $childCallId,
                    'child_workflow_run_id' => $child->id,
                    'history_event_id' => $receipt->id,
                    'resolution_history_event_id' => $resolved?->id,
                    'request_id' => $receipt->payload['child_request_id'],
                    'policy' => $policy->value,
                    'ready' => $policy !== CancellationPolicy::WaitCancellationCompleted || $resolved !== null,
                ];
            }, 5);
    }

    public static function assertReady(
        WorkflowRun $run,
        WorkflowHistoryEvent|ScopedCancellationPreparation $preparation,
        ?int $beforeHistorySequence = null,
    ): void {
        $preparation = $preparation instanceof WorkflowHistoryEvent
            ? ScopedCancellationPreparation::fromEvent($run, $preparation) : $preparation;
        foreach ($preparation->childMembers as $member) {
            $receipt = self::receipt(
                $run,
                $preparation,
                $member['child_call_id'],
                HistoryEventType::ChildCancellationRequested,
                $beforeHistorySequence
            );
            if ($receipt === null) {
                throw new LogicException('cancellation_scope_child_delivery_not_established');
            }
            self::assertRequest($run, $preparation, $member, $receipt);
            if ($member['cancellation_policy'] !== CancellationPolicy::WaitCancellationCompleted->value
                && $receipt->payload['request_outcome'] !== 'already_terminal') {
                continue;
            }
            $resolved = self::receipt(
                $run,
                $preparation,
                $member['child_call_id'],
                HistoryEventType::ChildCancellationResolved,
                $beforeHistorySequence
            );
            if ($resolved === null || $resolved->sequence <= $receipt->sequence) {
                throw new LogicException('cancellation_scope_child_completion_not_established');
            }
            self::assertBase($run, $preparation, $member, $resolved);
            $terminal = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                ->find($resolved->payload['child_terminal_history_event_id'] ?? null);
            if ($terminal === null || $terminal->workflow_run_id !== $member['child_workflow_run_id']
                || ! in_array(
                    $terminal->event_type,
                    [HistoryEventType::WorkflowCompleted, HistoryEventType::WorkflowFailed,
                        HistoryEventType::WorkflowCancelled, HistoryEventType::WorkflowTerminated],
                    true
                )
                || $terminal->event_type->value !== ($resolved->payload['child_terminal_event_type'] ?? null)
                || ($resolved->payload['child_status'] ?? null) !== match ($terminal->event_type) {
                    HistoryEventType::WorkflowCompleted => RunStatus::Completed->value,
                    HistoryEventType::WorkflowCancelled => RunStatus::Cancelled->value,
                    HistoryEventType::WorkflowTerminated => RunStatus::Terminated->value,
                    default => RunStatus::Failed->value,
                }
                || ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                    ->where('workflow_run_id', $member['child_workflow_run_id'])
                    ->whereIn('event_type', [HistoryEventType::WorkflowCompleted, HistoryEventType::WorkflowFailed,
                        HistoryEventType::WorkflowCancelled, HistoryEventType::WorkflowTerminated])->count() !== 1
                || ($resolved->payload['child_request_id'] ?? null) !== $receipt->payload['child_request_id']
                || ($resolved->payload['child_root_request_id'] ?? null) !== $receipt->payload['child_root_request_id']
                || ($resolved->payload['child_cleanup_deadline_at'] ?? null) !== $receipt->payload['child_cleanup_deadline_at']) {
                throw new LogicException('cancellation_scope_child_completion_not_established');
            }
        }
    }

    /**
     * @param array<string, mixed> $member
     */
    private static function assertRequest(
        WorkflowRun $run,
        ScopedCancellationPreparation $preparation,
        array $member,
        WorkflowHistoryEvent $receipt,
    ): void {
        self::assertBase($run, $preparation, $member, $receipt);
        $payload = $receipt->payload;
        $abandoned = $member['cancellation_policy'] === CancellationPolicy::Abandon->value;
        if ($abandoned || ($payload['request_outcome'] ?? null) === 'already_terminal') {
            if (($payload['request_outcome'] ?? null) !== ($abandoned ? 'abandoned' : 'already_terminal')
                || $payload['child_request_id'] !== null || $payload['child_cancellation'] !== null
                || $payload['child_root_request_id'] !== null || $payload['child_cleanup_deadline_at'] !== null) {
                throw new LogicException('cancellation_scope_child_delivery_not_established');
            }
            return;
        }
        if (($payload['request_outcome'] ?? null) !== 'accepted' || ! is_array(
            $payload['child_cancellation'] ?? null
        )) {
            throw new LogicException('cancellation_scope_child_delivery_not_established');
        }
        $context = self::context($payload['child_cancellation']);
        $origin = $preparation->context;
        $expected = CancellationContext::fromScopeContext(
            $origin,
            $context->requestId,
            $member['child_workflow_instance_id'],
            $member['child_workflow_run_id'],
            $context->deadline()
        );
        $child = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($member['child_workflow_run_id']);
        $canonical = $child === null ? null : CooperativeCancellationDelivery::context($child);
        $command = ConfiguredV2Models::query('command_model', WorkflowCommand::class)->find($context->requestId);
        $requested = $child?->historyEvents()
            ->where('event_type', HistoryEventType::CooperativeCancellationRequested)->get();
        if (self::canonical($context->toArray()) !== self::canonical($expected->toArray())
            || $context->deadline()
                ->greaterThan(CarbonImmutable::parse($preparation->authorityDeadlineAt))
            || self::canonical($canonical?->toArray()) !== self::canonical($context->toArray())
            || $command === null || $command->status !== CommandStatus::Accepted
            || $command->command_type !== CommandType::RequestCancellation
            || $command->workflow_run_id !== $member['child_workflow_run_id']
            || $command->workflow_instance_id !== $member['child_workflow_instance_id']
            || self::canonical((new CommandResult($command))->cancellationContext()?->toArray()) !== self::canonical(
                $context->toArray()
            )
            || $context->requestId !== $payload['child_request_id']
            || $context->rootRequestId !== $payload['child_root_request_id']
            || $context->deadline()
                ->toISOString() !== $payload['child_cleanup_deadline_at']
            || $requested?->count() !== 1 || $requested->sole()
                ->workflow_command_id !== $context->requestId
                            || self::canonical(self::context(
                                $requested->sole()
                                    ->payload['cancellation']
                            )->toArray()) !== self::canonical($context->toArray())) {
            throw new LogicException('cancellation_scope_child_delivery_not_established');
        }
    }

    /**
     * @param array<string, mixed> $member
     */
    private static function assertBase(
        WorkflowRun $run,
        ScopedCancellationPreparation $preparation,
        array $member,
        WorkflowHistoryEvent $receipt,
    ): void {
        $expected = self::base($run, $preparation, $member);
        $payload = $receipt->payload;
        if (! is_array($payload['cancellation_scope'] ?? null)
            || ! is_array($payload['cancellation_scope']['cancellation'] ?? null)) {
            throw new LogicException('cancellation_scope_child_delivery_not_established');
        }
        try {
            $payload['cancellation_scope']['cancellation'] = ScopedCancellationContext::fromArray(
                $payload['cancellation_scope']['cancellation']
            )->toArray();
        } catch (InvalidArgumentException $error) {
            throw new LogicException('cancellation_scope_child_delivery_not_established', previous: $error);
        }
        $payload['cancellation_scope']['member'] = ScopedChildCancellation::normalizeMembers([
            $payload['cancellation_scope']['member'] ?? null,
        ])[0];
        foreach ($expected as $key => $value) {
            if (self::canonical($payload[$key] ?? null) !== self::canonical($value)
                || $receipt->sequence <= $preparation->historySequence) {
                throw new LogicException('cancellation_scope_child_delivery_not_established');
            }
        }
    }

    /** @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private static function base(WorkflowRun $run, ScopedCancellationPreparation $preparation, array $member): array
    {
        $origin = $preparation->context;
        return [
            'sequence' => $member['sequence'],
            'policy' => $member['cancellation_policy'],
            'parent_request_id' => $origin->requestId,
            'root_request_id' => $origin->rootContext->rootRequestId,
            'cleanup_deadline_at' => $origin->deadline()
                ->toISOString(),
            'child_workflow_instance_id' => $member['child_workflow_instance_id'],
            'child_workflow_run_id' => $member['child_workflow_run_id'],
            'cancellation_scope' => [
                'schema' => self::SCHEMA,
                'workflow_run_id' => $run->id,
                'scope_id' => $origin->scopeId,
                'request_id' => $origin->requestId,
                'preparation_history_event_id' => $preparation->historyEventId,
                'authority_deadline_at' => $preparation->authorityDeadlineAt,
                'cancellation' => $origin->toArray(),
                'member' => $member,
            ],
        ];
    }

    private static function receipt(
        WorkflowRun $run,
        ScopedCancellationPreparation $preparation,
        string $callId,
        HistoryEventType $type,
        ?int $beforeHistorySequence = null,
    ): ?WorkflowHistoryEvent {
        $run->loadMissing('historyEvents');
        $member = collect($preparation->childMembers)
            ->firstWhere('child_call_id', $callId);
        $matches = $run->historyEvents->filter(static fn (WorkflowHistoryEvent $row): bool =>
            $row->event_type === $type
            && $row->workflow_command_id === null
            && ($row->payload['sequence'] ?? null) === $member['sequence']
            && ($beforeHistorySequence === null || $row->sequence < $beforeHistorySequence));
        if ($matches->count() > 1) {
            throw new LogicException('cancellation_scope_child_delivery_not_established');
        }
        return $matches->first();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function context(array $snapshot): CancellationContext
    {
        try {
            return CancellationContext::fromArray($snapshot);
        } catch (InvalidArgumentException $error) {
            throw new LogicException('cancellation_scope_child_delivery_not_established', previous: $error);
        }
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonical($entry);
        }
        return $value;
    }
}
