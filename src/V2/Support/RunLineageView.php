<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

final class RunLineageView
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function parentsForRun(WorkflowRun $run): array
    {
        $run->loadMissing(['historyEvents', 'parentLinks.parentRun.summary', 'instance.runs.summary']);

        $entries = [];
        $seen = [];

        $continuedFromRunId = ChildRunHistory::continuedFromRunId($run);

        if ($continuedFromRunId !== null) {
            $entries[] = self::entry(
                id: self::stringValue(self::workflowStartedEvent($run)?->payload['workflow_link_id'] ?? null)
                    ?? sprintf('continue_as_new:%s', $continuedFromRunId),
                linkType: 'continue_as_new',
                sequence: self::intValue(self::workflowStartedEvent($run)?->payload['sequence'] ?? null),
                isPrimaryParent: true,
                relatedRun: self::resolveRun($run, $continuedFromRunId),
                instanceId: $run->workflow_instance_id,
                runId: $continuedFromRunId,
                createdAt: self::workflowStartedEvent($run)?->recorded_at
                    ?? self::workflowStartedEvent($run)?->created_at,
                historyAuthority: ChildRunHistory::HISTORY_AUTHORITY_TYPED,
            );

            $seen[self::key('continue_as_new', $continuedFromRunId)] = true;
        }

        $parentReference = ChildRunHistory::parentReferenceForRun($run);

        if ($parentReference !== null) {
            $entries[] = self::entry(
                id: $parentReference['child_call_id']
                    ?? sprintf('child_workflow:%s:%s', $parentReference['parent_workflow_run_id'], $run->id),
                linkType: 'child_workflow',
                sequence: $parentReference['parent_sequence'],
                isPrimaryParent: true,
                relatedRun: self::resolveRun($run, $parentReference['parent_workflow_run_id']),
                instanceId: $parentReference['parent_workflow_instance_id'],
                runId: $parentReference['parent_workflow_run_id'],
                relationPrefix: 'parent',
                childCallId: $parentReference['child_call_id'],
                createdAt: self::workflowStartedEvent($run)?->recorded_at
                    ?? self::workflowStartedEvent($run)?->created_at,
                historyAuthority: ChildRunHistory::HISTORY_AUTHORITY_TYPED,
            );

            $seen[self::key('child_workflow', $parentReference['parent_workflow_run_id'])] = true;
        }

        foreach ($run->parentLinks as $link) {
            if (! $link instanceof WorkflowLink) {
                continue;
            }

            $key = self::key($link->link_type, $link->parent_workflow_run_id);

            if (isset($seen[$key])) {
                continue;
            }

            $entries[] = self::entry(
                id: $link->link_type === 'child_workflow'
                    ? (ChildRunHistory::childCallIdForRun($run) ?? $link->id)
                    : $link->id,
                linkType: $link->link_type,
                sequence: $link->sequence,
                isPrimaryParent: $link->is_primary_parent,
                relatedRun: $link->parentRun,
                instanceId: $link->parent_workflow_instance_id,
                runId: $link->parent_workflow_run_id,
                relationPrefix: 'parent',
                childCallId: $link->link_type === 'child_workflow'
                    ? ChildRunHistory::childCallIdForRun($run)
                    : null,
                createdAt: $link->created_at,
                historyAuthority: ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK,
                diagnosticOnly: true,
            );

            $seen[$key] = true;
        }

        return array_values($entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function continuedWorkflowsForRun(WorkflowRun $run): array
    {
        $run->loadMissing([
            'historyEvents',
            'childLinks.childRun.summary',
            'childLinks.childRun.instance.currentRun.summary',
            'instance.runs.summary',
        ]);

        $entries = [];
        $seen = [];

        $continuedEvent = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowContinuedAsNew
        );

        if ($continuedEvent instanceof WorkflowHistoryEvent) {
            $continuedRunId = self::stringValue($continuedEvent->payload['continued_to_run_id'] ?? null);

            if ($continuedRunId !== null) {
                $entries[] = self::entry(
                    id: self::stringValue($continuedEvent->payload['workflow_link_id'] ?? null)
                        ?? sprintf('continue_as_new:%s', $continuedRunId),
                    linkType: 'continue_as_new',
                    sequence: self::intValue($continuedEvent->payload['sequence'] ?? null),
                    isPrimaryParent: true,
                    relatedRun: self::resolveRun($run, $continuedRunId),
                    instanceId: $run->workflow_instance_id,
                    runId: $continuedRunId,
                    relationPrefix: 'child',
                    createdAt: $continuedEvent->recorded_at ?? $continuedEvent->created_at,
                    metadata: [
                        'workflow_type' => $run->workflow_type,
                        'workflow_class' => $run->workflow_class,
                        'run_number' => self::intValue($continuedEvent->payload['continued_to_run_number'] ?? null),
                    ],
                    historyAuthority: ChildRunHistory::HISTORY_AUTHORITY_TYPED,
                );

                $seen[self::key('continue_as_new', $continuedRunId)] = true;
            }
        }

        foreach (self::childSequences($run) as $sequence) {
            $childRun = ChildRunHistory::childRunForSequence($run, $sequence);
            $link = ChildRunHistory::latestLinkForSequence($run, $sequence);
            $startedEvent = ChildRunHistory::startedEventForSequence($run, $sequence);
            $scheduledEvent = ChildRunHistory::scheduledEventForSequence($run, $sequence);
            $resolutionEvent = ChildRunHistory::resolutionEventForSequence($run, $sequence);
            $childRunId = $childRun?->id
                ?? self::stringValue($resolutionEvent?->payload['child_workflow_run_id'] ?? null)
                ?? self::stringValue($startedEvent?->payload['child_workflow_run_id'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_run_id'] ?? null)
                ?? $link?->child_workflow_run_id;

            if ($childRunId === null) {
                continue;
            }

            $childInstanceId = $childRun?->workflow_instance_id
                ?? self::stringValue($resolutionEvent?->payload['child_workflow_instance_id'] ?? null)
                ?? self::stringValue($startedEvent?->payload['child_workflow_instance_id'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_instance_id'] ?? null)
                ?? $link?->child_workflow_instance_id;
            $childCallId = ChildRunHistory::childCallIdForSequence($run, $sequence);

            $key = self::childIdentityKey($childCallId, $sequence, $childInstanceId, $childRunId);

            if (isset($seen[$key])) {
                continue;
            }

            if ($childInstanceId === null) {
                continue;
            }

            $hasTypedHistory = $scheduledEvent !== null
                || $startedEvent !== null
                || $resolutionEvent !== null;

            $entries[] = self::entry(
                id: $childCallId ?? sprintf('child_workflow:%s:%s', $sequence, $childRunId),
                linkType: 'child_workflow',
                sequence: $sequence,
                isPrimaryParent: $link?->is_primary_parent ?? true,
                relatedRun: $resolutionEvent === null
                    ? ($childRun ?? self::resolveRun($run, $childRunId))
                    : null,
                instanceId: $childInstanceId,
                runId: $childRunId,
                relationPrefix: 'child',
                childCallId: $childCallId,
                createdAt: $scheduledEvent?->recorded_at
                    ?? $scheduledEvent?->created_at
                    ?? $startedEvent?->recorded_at
                    ?? $startedEvent?->created_at
                    ?? $link?->created_at,
                metadata: self::childMetadata($childRun, $scheduledEvent, $startedEvent, $resolutionEvent),
                historyAuthority: $hasTypedHistory
                    ? ChildRunHistory::HISTORY_AUTHORITY_TYPED
                    : ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK,
                diagnosticOnly: ! $hasTypedHistory,
            );

            $seen[$key] = true;
        }

        foreach ($run->childLinks as $link) {
            if (! $link instanceof WorkflowLink) {
                continue;
            }

            $childCallId = $link->link_type === 'child_workflow' && $link->sequence !== null
                ? ChildRunHistory::childCallIdForSequence($run, (int) $link->sequence)
                : null;
            $key = $link->link_type === 'child_workflow'
                ? self::childIdentityKey(
                    $childCallId,
                    $link->sequence,
                    $link->child_workflow_instance_id,
                    $link->child_workflow_run_id,
                )
                : self::key($link->link_type, $link->child_workflow_run_id);

            if (isset($seen[$key])) {
                continue;
            }

            $entries[] = self::entry(
                id: $link->link_type === 'child_workflow'
                    ? (
                        $childCallId
                        ?? $link->id
                    )
                    : $link->id,
                linkType: $link->link_type,
                sequence: $link->sequence,
                isPrimaryParent: $link->is_primary_parent,
                relatedRun: $link->childRun,
                instanceId: $link->child_workflow_instance_id,
                runId: $link->child_workflow_run_id,
                relationPrefix: 'child',
                childCallId: $childCallId,
                createdAt: $link->created_at,
                historyAuthority: ChildRunHistory::HISTORY_AUTHORITY_MUTABLE_OPEN_FALLBACK,
                diagnosticOnly: true,
            );

            $seen[$key] = true;
        }

        usort($entries, static function (array $left, array $right): int {
            $leftSequence = $left['sequence'] ?? PHP_INT_MAX;
            $rightSequence = $right['sequence'] ?? PHP_INT_MAX;

            if ($leftSequence !== $rightSequence) {
                return $leftSequence <=> $rightSequence;
            }

            return ($left['run_number'] ?? PHP_INT_MAX) <=> ($right['run_number'] ?? PHP_INT_MAX);
        });

        return array_values($entries);
    }

    private static function workflowStartedEvent(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        $run->loadMissing('historyEvents');

        /** @var WorkflowHistoryEvent|null $event */
        $event = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowStarted
        );

        return $event;
    }

    /**
     * @return list<int>
     */
    private static function childSequences(WorkflowRun $run): array
    {
        $scheduled = $run->historyEvents
            ->filter(
                static fn (WorkflowHistoryEvent $event): bool => in_array($event->event_type, [
                    HistoryEventType::ChildWorkflowScheduled,
                    HistoryEventType::ChildRunStarted,
                    HistoryEventType::ChildRunCompleted,
                    HistoryEventType::ChildRunFailed,
                    HistoryEventType::ChildRunCancelled,
                    HistoryEventType::ChildRunTerminated,
                ], true) && is_int($event->payload['sequence'] ?? null)
            )
            ->map(static fn (WorkflowHistoryEvent $event): int => $event->payload['sequence'])
            ->all();
        $linked = $run->childLinks
            ->filter(
                static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow' && $link->sequence !== null
            )
            ->map(static fn (WorkflowLink $link): int => (int) $link->sequence)
            ->all();

        $sequences = array_values(array_unique(array_merge($scheduled, $linked)));
        sort($sequences);

        return $sequences;
    }

    private static function resolveRun(WorkflowRun $selectedRun, string $runId): ?WorkflowRun
    {
        if ($selectedRun->id === $runId) {
            return $selectedRun;
        }

        $sameInstanceRun = $selectedRun->instance?->runs?->firstWhere('id', $runId);

        if ($sameInstanceRun instanceof WorkflowRun) {
            $sameInstanceRun->loadMissing('summary');

            return $sameInstanceRun;
        }

        /** @var WorkflowRun|null $run */
        $run = ConfiguredV2Models::query('run_model', \Workflow\V2\Models\WorkflowRun::class)
            ->with('summary')
            ->find($runId);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(
        string $id,
        string $linkType,
        ?int $sequence,
        bool $isPrimaryParent,
        ?WorkflowRun $relatedRun,
        string $instanceId,
        string $runId,
        string $relationPrefix = 'parent',
        ?string $childCallId = null,
        mixed $createdAt = null,
        array $metadata = [],
        string $historyAuthority = ChildRunHistory::HISTORY_AUTHORITY_TYPED,
        bool $diagnosticOnly = false,
    ): array {
        $summary = $relatedRun?->summary;
        $status = self::stringValue($metadata['status'] ?? null)
            ?? $relatedRun?->status?->value;
        $statusBucket = self::stringValue($metadata['status_bucket'] ?? null)
            ?? $summary?->status_bucket
            ?? self::statusBucketFromValue($status);
        $closedReason = self::stringValue($metadata['closed_reason'] ?? null)
            ?? $summary?->closed_reason
            ?? $relatedRun?->closed_reason;

        return [
            'id' => $id,
            'link_type' => $linkType,
            'child_call_id' => $childCallId,
            'sequence' => $sequence,
            'is_primary_parent' => $isPrimaryParent,
            $relationPrefix . '_workflow_id' => $instanceId,
            $relationPrefix . '_workflow_run_id' => $runId,
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $runId,
            'run_number' => self::intValue($metadata['run_number'] ?? null)
                ?? $relatedRun?->run_number,
            'workflow_type' => self::stringValue($metadata['workflow_type'] ?? null)
                ?? $relatedRun?->workflow_type,
            'class' => self::stringValue($metadata['workflow_class'] ?? null)
                ?? $relatedRun?->workflow_class,
            'status' => $status,
            'status_bucket' => $statusBucket,
            'closed_reason' => $closedReason,
            'created_at' => self::timestamp($createdAt),
            'history_authority' => $historyAuthority,
            'diagnostic_only' => $diagnosticOnly,
        ];
    }

    /**
     * @return array{
     *     workflow_type: ?string,
     *     workflow_class: ?string,
     *     run_number: ?int,
     *     status: ?string,
     *     status_bucket: ?string,
     *     closed_reason: ?string
     * }
     */
    private static function childMetadata(
        ?WorkflowRun $childRun,
        ?WorkflowHistoryEvent $scheduledEvent,
        ?WorkflowHistoryEvent $startedEvent,
        ?WorkflowHistoryEvent $resolutionEvent,
    ): array {
        $status = self::stringValue($resolutionEvent?->payload['child_status'] ?? null)
            ?? self::stringValue($startedEvent?->payload['child_status'] ?? null)
            ?? match ($resolutionEvent?->event_type) {
                HistoryEventType::ChildRunCompleted => RunStatus::Completed->value,
                HistoryEventType::ChildRunFailed => RunStatus::Failed->value,
                HistoryEventType::ChildRunCancelled => RunStatus::Cancelled->value,
                HistoryEventType::ChildRunTerminated => RunStatus::Terminated->value,
                default => (
                    $startedEvent instanceof WorkflowHistoryEvent || $scheduledEvent instanceof WorkflowHistoryEvent
                        ? RunStatus::Waiting->value
                        : $childRun?->status?->value
                ),
            };
        $statusBucket = self::statusBucketFromValue($status);
        $closedReason = self::stringValue($resolutionEvent?->payload['closed_reason'] ?? null);

        if ($closedReason === null && $resolutionEvent instanceof WorkflowHistoryEvent) {
            $closedReason = $status;
        }

        return [
            'workflow_type' => self::stringValue($resolutionEvent?->payload['child_workflow_type'] ?? null)
                ?? self::stringValue($startedEvent?->payload['child_workflow_type'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_type'] ?? null)
                ?? $childRun?->workflow_type,
            'workflow_class' => self::stringValue($resolutionEvent?->payload['child_workflow_class'] ?? null)
                ?? self::stringValue($startedEvent?->payload['child_workflow_class'] ?? null)
                ?? self::stringValue($scheduledEvent?->payload['child_workflow_class'] ?? null)
                ?? $childRun?->workflow_class,
            'run_number' => self::intValue($resolutionEvent?->payload['child_run_number'] ?? null)
                ?? self::intValue($startedEvent?->payload['child_run_number'] ?? null)
                ?? self::intValue($scheduledEvent?->payload['child_run_number'] ?? null)
                ?? $childRun?->run_number,
            'status' => $status,
            'status_bucket' => $statusBucket,
            'closed_reason' => $closedReason ?? $childRun?->closed_reason,
        ];
    }

    private static function key(string $linkType, string $runId): string
    {
        return sprintf('%s:%s', $linkType, $runId);
    }

    private static function childIdentityKey(
        ?string $childCallId,
        ?int $sequence,
        ?string $instanceId,
        ?string $runId,
    ): string {
        if ($childCallId !== null) {
            return sprintf('child_call:%s', $childCallId);
        }

        if ($sequence !== null && $instanceId !== null) {
            return sprintf('child_sequence:%d:%s', $sequence, $instanceId);
        }

        if ($sequence !== null) {
            return sprintf('child_sequence:%d', $sequence);
        }

        if ($instanceId !== null) {
            return sprintf('child_instance:%s', $instanceId);
        }

        return self::key('child_workflow', $runId ?? 'missing-run');
    }

    private static function statusBucket(?RunStatus $status): ?string
    {
        return $status?->statusBucket()->value;
    }

    private static function statusBucketFromValue(?string $status): ?string
    {
        return $status === null
            ? null
            : self::statusBucket(RunStatus::tryFrom($status));
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        return is_int($value)
            ? $value
            : null;
    }

    private static function timestamp(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
