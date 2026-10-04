<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use LogicException;
use Workflow\V2\CancellationContext;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowChildCall;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

/** Read-only cancellation evidence shared by embedded and standalone operators. */
final class CancellationCascadeView
{
    private const RUN_LIMIT = 20;

    private const EDGE_LIMIT = 100;

    private const HISTORY_LIMIT = 128;

    private const TOTAL_HISTORY_LIMIT = 512;

    private const REQUEST_TEXT_BYTES = 8192;

    /**
     * @var list<array<string, mixed>>
     */
    private array $findings = [];

    private bool $truncated = false;

    private int $historyCount = 0;

    /**
     * @var array<string, WorkflowRun|null>
     */
    private array $visibleRuns = [];

    private function __construct(
        private readonly WorkflowRun $selected
    ) {
    }

    /**
     * Resolve the exact selected run, including historical runs. Every related
     * run and instance is scoped before its history or metadata is inspected.
     *
     * @return array<string, mixed>|null
     */
    public static function forRun(WorkflowRun $selected): ?array
    {
        return (new self($selected))->inspect();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspect(): ?array
    {
        $selected = $this->visibleRun($this->selected->id);
        if ($selected === null) {
            return null;
        }
        [$selectedContext] = $this->request($selected);
        if ($selectedContext === null && $selected->cancellation_request_command_id === null
            && $this->findings === []) {
            return null;
        }

        $root = $selected;
        $origin = $selectedContext;
        if ($selectedContext !== null && $selectedContext->rootWorkflowRunId !== $selected->id) {
            $visibleRoot = $this->visibleRun($selectedContext->rootWorkflowRunId);
            if ($visibleRoot === null) {
                $this->incomplete('root_unavailable', $selected->id);
                $origin = null;
            } else {
                $root = $visibleRoot;
                [$origin] = $this->request($root);
                if ($origin === null || ! $this->sameOrigin($origin, $selectedContext)) {
                    $this->incomplete('root_request_mismatch', $selected->id);
                    $origin = null;
                }
            }
        }

        $pending = [$root];
        $seen = [];
        $nodes = [];
        $edges = [];
        while ($pending !== [] && count($nodes) < self::RUN_LIMIT) {
            $run = array_shift($pending);
            if (isset($seen[$run->id])) {
                continue;
            }
            $seen[$run->id] = true;
            $nodes[] = $this->node($run, $origin);
            foreach ($this->references($run, self::EDGE_LIMIT - count($edges)) as $reference) {
                $childId = $reference['target_run_id'];
                $child = is_string($childId) ? $this->visibleRun($childId) : null;
                $edge = [
                    'parent_run_id' => $run->id,
                    'child_run_id' => $child?->id,
                    'kind' => $reference['kind'],
                    'reference_id' => $reference['id'],
                    'reference_source' => $reference['source'],
                    'reference_state' => $childId === null ? 'pending' : ($child === null ? 'unavailable' : 'resolved'),
                ];
                $edges[] = $edge;
                if ($childId !== null && $child === null) {
                    // Do not disclose an inaccessible target's ID, type or request.
                    $this->incomplete('related_run_unavailable', $run->id);
                } elseif ($child !== null && ! isset($seen[$child->id])) {
                    $pending[] = $child;
                }
            }
        }
        if ($pending !== []) {
            $this->truncate('run_limit', $root->id);
        }
        if (! isset($seen[$selected->id])) {
            $this->incomplete('selected_run_not_reachable', $selected->id);
            if (count($nodes) < self::RUN_LIMIT) {
                $nodes[] = $this->node($selected, $origin);
            }
        }

        return [
            'schema' => 'durable-workflow.cancellation-cascade/v1',
            'selected_run_id' => $selected->id,
            'root' => $origin === null ? null : $this->metadata($origin),
            'runs' => $nodes,
            'edges' => $edges,
            'inspection_complete' => $this->findings === [],
            'truncated' => $this->truncated,
            'findings' => $this->findings,
            'limits' => [
                'runs' => self::RUN_LIMIT,
                'edges' => self::EDGE_LIMIT,
                'history_events_per_run' => self::HISTORY_LIMIT,
                'history_events_total' => self::TOTAL_HISTORY_LIMIT,
                'request_text_bytes' => self::REQUEST_TEXT_BYTES,
                'scope_origin_addresses' => self::EDGE_LIMIT,
            ],
        ];
    }

    private function visibleRun(string $id): ?WorkflowRun
    {
        if (array_key_exists($id, $this->visibleRuns)) {
            return $this->visibleRuns[$id];
        }
        return $this->visibleRuns[$id] = $this->selected->newQuery()->whereKey($id)
            ->where('namespace', $this->selected->namespace)
            ->whereHas(
                'instance',
                fn (Builder $query): Builder => $query->where('namespace', $this->selected->namespace)
            )
            ->first();
    }

    /**
     * @return array{CancellationContext|null, WorkflowHistoryEvent|null}
     */
    private function request(WorkflowRun $run): array
    {
        $query = $run->historyEvents()
            ->orderBy('sequence');
        if (is_string($run->cancellation_request_command_id)) {
            $query->where('event_type', HistoryEventType::CooperativeCancellationRequested->value)
                ->where('workflow_command_id', $run->cancellation_request_command_id);
        } else {
            $query->whereIn('event_type', [
                HistoryEventType::CooperativeCancellationRequested->value,
                HistoryEventType::ParentCloseCancellationRequested->value,
            ]);
        }
        $events = $query->limit(2)
            ->get();
        $event = $events->first();
        if ($events->count() > 1) {
            $this->incomplete('request_history_conflict', $run->id);
        }
        if ($event === null) {
            if ($run->cancellation_request_command_id !== null) {
                $this->incomplete('request_history_unavailable', $run->id);
            }
            return [null, null];
        }
        $snapshot = $event->payload['cancellation'] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['lineage'] ?? null)
            || count($snapshot['lineage']) > self::RUN_LIMIT) {
            $this->incomplete('request_context_unavailable', $run->id);
            return [null, $event];
        }
        try {
            $context = CancellationContext::fromArray($snapshot);
            if ($event->event_type === HistoryEventType::ParentCloseCancellationRequested) {
                $context = ParentCloseCancellation::context($run);
                if ($context === null) {
                    throw new LogicException('Parent-close cancellation origin is unavailable.');
                }
            } elseif ($event->workflow_command_id !== $context->requestId
                || ($event->payload['workflow_command_id'] ?? null) !== $context->requestId) {
                throw new InvalidArgumentException('Cancellation history request mismatch.');
            }
            $target = $context->lineage[count($context->lineage) - 1];
            if ($target['workflow_run_id'] !== $run->id
                || $target['workflow_instance_id'] !== $run->workflow_instance_id
                || ($run->cancellation_request_command_id !== null
                    && $context->requestId !== $run->cancellation_request_command_id)) {
                throw new InvalidArgumentException('Cancellation target mismatch.');
            }
        } catch (LogicException) {
            $this->incomplete('request_context_invalid', $run->id);
            return [null, $event];
        }
        if ($event->event_type === HistoryEventType::CooperativeCancellationRequested
            && ($run->cancellation_request_command_id !== $context->requestId
                || $run->cancellation_deadline_at === null
                || ! $run->cancellation_deadline_at->equalTo($context->deadline()))) {
            $this->incomplete('request_projection_mismatch', $run->id);
        }
        return [$context, $event];
    }

    /**
     * @return array<string, mixed>
     */
    private function node(WorkflowRun $run, ?CancellationContext $origin): array
    {
        [$context, $request] = $this->request($run);
        if ($context !== null && $this->visibleRun($context->rootWorkflowRunId) === null) {
            $this->incomplete('request_root_unavailable', $run->id);
            $context = null;
        }
        $limit = min(self::HISTORY_LIMIT, self::TOTAL_HISTORY_LIMIT - $this->historyCount);
        $events = $run->historyEvents()
            ->whereIn('event_type', [
                HistoryEventType::CooperativeCancellationRequested->value,
                HistoryEventType::CooperativeCancellationDelivered->value,
                HistoryEventType::ActivityCancelled->value,
                HistoryEventType::ActivityCancellationAcknowledged->value,
                HistoryEventType::ActivityScheduled->value,
                HistoryEventType::ActivityRetryScheduled->value,
                HistoryEventType::TimerScheduled->value,
                HistoryEventType::ChildCancellationRequested->value,
                HistoryEventType::ChildCancellationResolved->value,
                HistoryEventType::WorkflowCancelled->value,
                HistoryEventType::WorkflowTerminated->value,
                HistoryEventType::WorkflowFailed->value,
                HistoryEventType::WorkflowCompleted->value,
                HistoryEventType::WorkflowTimedOut->value,
            ])->orderByDesc('sequence')
            ->limit($limit + 1)
            ->get();
        if ($events->count() > $limit) {
            $this->truncate('history_limit', $run->id);
        }
        $events = new Collection($events->take($limit)->reverse()->values()->all());
        $this->historyCount += $events->count();
        if ($request !== null && ! $events->contains('id', $request->id)) {
            $events->prepend($request);
        }
        $run->setRelation('historyEvents', $events);
        $deliveries = $events->filter(static fn (WorkflowHistoryEvent $event): bool =>
            $event->event_type === HistoryEventType::CooperativeCancellationDelivered);
        $delivery = $deliveries->first();
        if ($delivery === null && $run->cancellation_delivery_sequence !== null) {
            $this->incomplete('delivery_history_unavailable', $run->id);
        }
        if ($delivery !== null && ($deliveries->count() !== 1 || $context === null
            || $delivery->workflow_command_id !== $context->requestId
            || ($delivery->payload['workflow_command_id'] ?? null) !== $context->requestId
            || ! is_int($delivery->payload['sequence'] ?? null)
            || $delivery->payload['sequence'] !== $run->cancellation_delivery_sequence)) {
            $this->incomplete('delivery_history_invalid', $run->id);
            $delivery = null;
        }
        $terminals = $events->filter(static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
            HistoryEventType::WorkflowCancelled, HistoryEventType::WorkflowTerminated,
            HistoryEventType::WorkflowFailed, HistoryEventType::WorkflowCompleted, HistoryEventType::WorkflowTimedOut,
        ], true));
        $terminal = $terminals->last();
        if ($terminals->count() > 1 || ($run->status->isTerminal() && $terminal === null)) {
            $this->incomplete('terminal_history_unavailable', $run->id);
        }
        $cleanup = $terminal?->payload['cancellation_cleanup'] ?? null;
        $cleanup = is_array($cleanup) && $context !== null ? $cleanup : null;
        if ($terminal?->event_type === HistoryEventType::WorkflowCancelled && $context !== null
            && (! is_array($cleanup) || ! $this->validCleanup($run, $context, $cleanup, $delivery))) {
            $this->incomplete('cleanup_outcome_unavailable', $run->id);
            $cleanup = null;
        }
        if ($terminal !== null && $run->status->value !== match ($terminal->event_type) {
            HistoryEventType::WorkflowCancelled => 'cancelled',
            HistoryEventType::WorkflowTerminated => 'terminated',
            HistoryEventType::WorkflowFailed => 'failed',
            HistoryEventType::WorkflowTimedOut => 'failed',
            HistoryEventType::WorkflowCompleted => 'completed',
            default => null,
        }) {
            $this->incomplete('terminal_projection_mismatch', $run->id);
        }

        $stops = [];
        $recovery = [];
        $propagation = [];
        foreach ($events as $event) {
            if (in_array($event->event_type, [HistoryEventType::ChildCancellationRequested,
                HistoryEventType::ChildCancellationResolved], true) && $context !== null) {
                if ($event->workflow_command_id !== $context->requestId
                    || ($event->payload['parent_request_id'] ?? null) !== $context->requestId
                    || ($event->payload['root_request_id'] ?? null) !== $context->rootRequestId
                    || ($event->payload['cleanup_deadline_at'] ?? null) !== $context->deadline()->toISOString()) {
                    $this->incomplete('propagation_history_invalid', $run->id);
                    continue;
                }
                $targetId = $event->payload['child_workflow_run_id'] ?? null;
                $target = is_string($targetId) ? $this->visibleRun($targetId) : null;
                if ($target === null) {
                    $this->incomplete('related_run_unavailable', $run->id);
                }
                $propagation[] = [
                    'history_event_id' => $event->id,
                    'event_type' => $event->event_type->value,
                    'child_run_id' => $target?->id,
                    'policy' => $event->payload['policy'] ?? null,
                    'request_outcome' => $event->payload['request_outcome'] ?? null,
                    'rejection_reason' => $event->payload['rejection_reason'] ?? null,
                    'child_terminal_history_event_id' => $target === null ? null
                        : ($event->payload['child_terminal_history_event_id'] ?? null),
                ];
            }
            if ($event->event_type === HistoryEventType::ActivityCancelled && $context !== null
                && $event->workflow_command_id === $context->requestId) {
                $receipt = ActivityCancellationCompletion::stopReceipt($run, $event);
                $stops[] = [
                    'activity_execution_id' => $event->payload['activity_execution_id'] ?? null,
                    'activity_attempt_id' => $event->payload['activity_attempt_id'] ?? null,
                    'activity_type' => $event->payload['activity_type'] ?? null,
                    'execution_mode' => ($event->payload['local_activity'] ?? false) === true ? 'local' : 'remote',
                    'fence_history_event_id' => $event->id,
                    'callback_state' => $receipt === null ? 'unknown' : 'reported_stopped',
                    'stop_history_event_id' => $receipt?->id,
                    'evidence_source' => $receipt?->payload['evidence_source'] ?? null,
                    'acknowledged_at' => $receipt?->payload['acknowledged_at'] ?? null,
                    'received_after_deadline' => $receipt?->payload['received_after_deadline'] ?? null,
                ];
            }
            if ($event->event_type === HistoryEventType::ActivityRetryScheduled
                && is_array($event->payload['local_recovery'] ?? null)) {
                $recovery[] = [
                    'history_event_id' => $event->id,
                    'activity_execution_id' => $event->payload['activity_execution_id'] ?? null,
                    'recorded_at' => $event->recorded_at?->toISOString(),
                    'attempt' => array_intersect_key($event->payload['local_recovery'], array_flip([
                        'original_workflow_task_id', 'original_workflow_task_attempt',
                        'original_lease_owner', 'original_lease_expires_at',
                        'workflow_task_id', 'workflow_task_attempt', 'lease_owner', 'callback_stop_state',
                    ])),
                ];
            }
        }

        return [
            'run_id' => $run->id,
            'workflow_id' => $run->workflow_instance_id,
            'workflow_type' => $run->workflow_type ?? $run->workflow_class,
            'projected_status' => $run->status->value,
            'lifecycle' => $this->lifecycle($run, $context, $events, $delivery, $terminal, $cleanup),
            'terminal_event_type' => $terminal?->event_type->value,
            'terminal_history_event_id' => $terminal?->id,
            'request' => $context === null ? null : $this->metadata($context),
            'same_root_budget' => $context !== null && $origin !== null && $this->sameOrigin($origin, $context),
            'delivery' => $delivery === null ? null : [
                'history_event_id' => $delivery->id,
                'sequence' => $delivery->payload['sequence'],
                'sequence_span' => $delivery->payload['sequence_span'] ?? 1,
                'call_kind' => $delivery->payload['call_kind'] ?? null,
                'recorded_at' => $delivery->recorded_at?->toISOString(),
            ],
            'cleanup' => is_array($cleanup) ? array_intersect_key($cleanup, array_flip([
                'request_id', 'outcome', 'cleanup_deadline_at', 'finished_at',
                'delivery_history_event_id', 'delivery_sequence',
            ])) : null,
            'activity_stops' => $stops,
            'cleanup_recovery' => $recovery,
            'child_propagation' => $propagation,
        ];
    }

    /**
     * @return list<array{id: int|string, source: string, kind: string, target_run_id: string|null}>
     */
    private function references(WorkflowRun $run, int $remaining): array
    {
        $calls = WorkflowChildCall::query()->where('parent_workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->limit($remaining + 1)
            ->get();
        $references = [];
        foreach ($calls->take($remaining) as $call) {
            $references[] = [
                'id' => $call->id,
                'source' => 'child_call',
                'kind' => 'child_workflow',
                'target_run_id' => $call->resolved_child_run_id,
            ];
        }
        if ($calls->count() > $remaining) {
            $this->truncate('edge_limit', $run->id);
        }
        $remaining -= count($references);
        $links = WorkflowLink::query()->where('parent_workflow_run_id', $run->id)
            ->whereIn('link_type', ['child_workflow', 'continue_as_new'])
            ->whereNotIn('child_workflow_run_id', array_filter(array_column($references, 'target_run_id')))
            ->orderBy('sequence')
            ->orderBy('id')
            ->limit($remaining + 1)
            ->get();
        foreach ($links->take($remaining) as $link) {
            $references[] = [
                'id' => $link->id,
                'source' => 'workflow_link',
                'kind' => $link->link_type,
                'target_run_id' => $link->child_workflow_run_id,
            ];
        }
        if ($links->count() > $remaining) {
            $this->truncate('edge_limit', $run->id);
        }
        return $references;
    }

    private function sameOrigin(CancellationContext $root, CancellationContext $child): bool
    {
        $child = $child->scopeOrigin?->rootContext ?? $child;
        return $root->rootRequestId === $child->rootRequestId
            && $root->rootWorkflowRunId === $child->rootWorkflowRunId
            && $root->rootWorkflowInstanceId === $child->rootWorkflowInstanceId
            && $root->requestedAt()
                ->equalTo($child->requestedAt())
            && $root->deadline()
                ->equalTo($child->deadline())
            && $root->reason === $child->reason && $root->requester === $child->requester
            && $root->source === $child->source;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(CancellationContext $context): array
    {
        // Edges explain lineage without exposing unresolvable snapshot targets.
        $metadata = array_diff_key($context->toArray(), [
            'lineage' => true,
        ]);
        if ($context->scopeOrigin !== null) {
            $metadata['scope_origin'] = null;
            $lineage = $context->scopeOrigin->lineage;
            $available = count($lineage) <= self::EDGE_LIMIT;
            if (! $available) {
                $this->truncate(
                    'scope_origin_limit',
                    $context->lineage[count($context->lineage) - 1]['workflow_run_id']
                );
            } else {
                foreach ($lineage as $entry) {
                    $run = $this->visibleRun($entry['workflow_run_id']);
                    if ($run === null || $run->workflow_instance_id !== $entry['workflow_instance_id']) {
                        $available = false;
                        $this->incomplete(
                            'scope_origin_unavailable',
                            $context->lineage[count($context->lineage) - 1]['workflow_run_id']
                        );
                        break;
                    }
                }
            }
            if ($available) {
                $metadata['scope_origin'] = [
                    'schema' => $context->scopeOrigin::SCHEMA,
                    'root_context' => $this->metadata($context->scopeOrigin->rootContext),
                    'lineage' => $lineage,
                ];
            } else {
                $metadata['parent_request_id'] = null;
            }
        }
        foreach ($metadata as $key => $value) {
            if (is_string($value) && strlen($value) > self::REQUEST_TEXT_BYTES) {
                $metadata[$key] = mb_strcut($value, 0, self::REQUEST_TEXT_BYTES, 'UTF-8');
                $this->truncate(
                    'request_text_limit',
                    $context->lineage[count($context->lineage) - 1]['workflow_run_id']
                );
            }
        }
        foreach ($context->requester as $key => $value) {
            if (strlen($value) > self::REQUEST_TEXT_BYTES) {
                $metadata['requester'][$key] = mb_strcut($value, 0, self::REQUEST_TEXT_BYTES, 'UTF-8');
                $this->truncate(
                    'request_text_limit',
                    $context->lineage[count($context->lineage) - 1]['workflow_run_id']
                );
            }
        }
        return $metadata;
    }

    /**
     * @param array<string, mixed> $cleanup
     */
    private function validCleanup(
        WorkflowRun $run,
        CancellationContext $context,
        array $cleanup,
        ?WorkflowHistoryEvent $delivery,
    ): bool {
        if (($cleanup['request_id'] ?? null) !== $context->requestId
            || ($cleanup['cleanup_deadline_at'] ?? null) !== $context->deadline()->toISOString()
            || $run->closed_at === null
            || ($cleanup['finished_at'] ?? null) !== $run->closed_at->toISOString()) {
            return false;
        }
        $expired = $run->closed_at->gte($context->deadline());
        return match ($cleanup['outcome'] ?? null) {
            'deadline_expired' => $expired,
            'completed' => ! $expired && $delivery !== null
                && ($cleanup['delivery_history_event_id'] ?? null) === $delivery->id
                && ($cleanup['delivery_sequence'] ?? null) === $delivery->payload['sequence'],
            'not_delivered' => ! $expired && $delivery === null && $run->cancellation_delivery_sequence === null,
            'unavailable' => ! $expired,
            default => false,
        };
    }

    /**
     * @param Collection<int, WorkflowHistoryEvent> $events
     * @param array<string, mixed>|null $cleanup
     */
    private function lifecycle(
        WorkflowRun $run,
        ?CancellationContext $context,
        Collection $events,
        ?WorkflowHistoryEvent $delivery,
        ?WorkflowHistoryEvent $terminal,
        ?array $cleanup,
    ): ?string {
        if ($terminal !== null) {
            return match ($terminal->event_type) {
                HistoryEventType::WorkflowCancelled => ($cleanup['outcome'] ?? null) === 'deadline_expired'
                    ? 'deadline_expired' : 'cancelled',
                HistoryEventType::WorkflowTerminated => 'terminated',
                HistoryEventType::WorkflowFailed => 'failed',
                HistoryEventType::WorkflowTimedOut => 'timed_out',
                HistoryEventType::WorkflowCompleted => 'completed',
                default => 'unknown',
            };
        }
        if ($run->status->isTerminal()) {
            return 'unknown';
        }
        if ($delivery !== null) {
            $lastSequence = $delivery->payload['sequence'] + ($delivery->payload['sequence_span'] ?? 1) - 1;
            $started = $events->contains(static fn (WorkflowHistoryEvent $event): bool =>
                in_array(
                    $event->event_type,
                    [HistoryEventType::ActivityScheduled, HistoryEventType::TimerScheduled],
                    true
                )
                && $event->sequence > $delivery->sequence
                && is_int($event->payload['sequence'] ?? null) && $event->payload['sequence'] > $lastSequence);
            return $started ? 'cleaning_up' : 'delivered';
        }
        return $context === null ? null : 'requested';
    }

    private function truncate(string $code, string $runId): void
    {
        $this->truncated = true;
        $this->incomplete($code, $runId);
    }

    private function incomplete(string $code, string $runId): void
    {
        $message = match ($code) {
            'run_limit' => 'Some related runs are omitted because this view reached its run limit.',
            'edge_limit' => 'Some relations are omitted because this view reached its relation limit.',
            'history_limit' => 'Some history is omitted. Open the run history for additional evidence.',
            'request_text_limit' => 'Some request text is omitted because it exceeds the inspection text limit.',
            'scope_origin_limit' => 'The originating scope path exceeds this view\'s inspection limit. Its details are omitted.',
            'scope_origin_unavailable' => 'An originating scope run is unavailable in this namespace. Its path is omitted.',
            'root_unavailable', 'request_root_unavailable' => 'The root run is unavailable. Its original cascade budget cannot be verified.',
            'related_run_unavailable' => 'A related run is unavailable in this namespace. Its details are omitted.',
            'selected_run_not_reachable' => 'The selected run has no retained path from the root. It is shown separately.',
            'request_history_unavailable' => 'The recorded cancellation request is missing. Its identity and deadline cannot be verified.',
            'request_context_unavailable' => 'The recorded request has no usable cancellation context.',
            'request_projection_mismatch' => 'Saved run state disagrees with the original cancellation request or budget. The original request remains in history.',
            'request_history_conflict', 'request_context_invalid', 'root_request_mismatch' => 'The recorded request has inconsistent cancellation metadata.',
            'delivery_history_invalid', 'delivery_history_unavailable' => 'The original cancellation delivery cannot be verified.',
            'terminal_history_unavailable' => 'The run has missing or conflicting terminal history.',
            'terminal_projection_mismatch' => 'Run status disagrees with its terminal history. Inspect the recorded terminal event before recovery.',
            'cleanup_outcome_unavailable' => 'Cleanup completion cannot be verified from the recorded cancellation outcome.',
            'propagation_history_invalid' => 'Child propagation does not match the original cancellation request and budget.',
            default => 'Some cancellation evidence cannot be verified.',
        };
        $finding = [
            'code' => $code,
            'run_id' => $runId,
            'message' => $message,
        ];
        if (! in_array($finding, $this->findings, true)) {
            $this->findings[] = $finding;
        }
    }
}
