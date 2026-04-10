<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;

final class ParallelGroupMetadataBackfill
{
    private const ACTIVITY_EVENT_TYPES = [
        HistoryEventType::ActivityScheduled,
        HistoryEventType::ActivityStarted,
        HistoryEventType::ActivityHeartbeatRecorded,
        HistoryEventType::ActivityRetryScheduled,
        HistoryEventType::ActivityCompleted,
        HistoryEventType::ActivityFailed,
        HistoryEventType::ActivityCancelled,
    ];

    private const CHILD_EVENT_TYPES = [
        HistoryEventType::ChildWorkflowScheduled,
        HistoryEventType::ChildRunStarted,
        HistoryEventType::ChildRunCompleted,
        HistoryEventType::ChildRunFailed,
        HistoryEventType::ChildRunCancelled,
        HistoryEventType::ChildRunTerminated,
    ];

    /**
     * @return array{
     *     activity_events_scanned: int,
     *     activity_events_updated: int,
     *     activity_events_would_update: int,
     *     activity_events_already_authoritative: int,
     *     activity_events_without_sidecar_metadata: int,
     *     child_events_scanned: int,
     *     child_events_updated: int,
     *     child_events_would_update: int,
     *     child_events_already_authoritative: int,
     *     child_events_without_sidecar_metadata: int
     * }
     */
    public static function backfillRun(WorkflowRun $run, bool $dryRun = false): array
    {
        $run->loadMissing(['activityExecutions', 'childLinks', 'historyEvents']);

        $activityMetadataBySequence = self::activityMetadataBySequence($run);
        $childMetadataBySequence = self::childMetadataBySequence($run);

        $report = [
            'activity_events_scanned' => 0,
            'activity_events_updated' => 0,
            'activity_events_would_update' => 0,
            'activity_events_already_authoritative' => 0,
            'activity_events_without_sidecar_metadata' => 0,
            'child_events_scanned' => 0,
            'child_events_updated' => 0,
            'child_events_would_update' => 0,
            'child_events_already_authoritative' => 0,
            'child_events_without_sidecar_metadata' => 0,
        ];

        foreach ($run->historyEvents->sortBy('sequence') as $event) {
            if (! $event instanceof WorkflowHistoryEvent || ! is_array($event->payload)) {
                continue;
            }

            $sequence = self::intValue($event->payload['sequence'] ?? null);

            if ($sequence === null) {
                continue;
            }

            if (in_array($event->event_type, self::ACTIVITY_EVENT_TYPES, true)) {
                self::backfillEvent(
                    $event,
                    $activityMetadataBySequence[$sequence] ?? [],
                    'activity',
                    $dryRun,
                    $report,
                );

                continue;
            }

            if (in_array($event->event_type, self::CHILD_EVENT_TYPES, true)) {
                self::backfillEvent(
                    $event,
                    $childMetadataBySequence[$sequence] ?? [],
                    'child',
                    $dryRun,
                    $report,
                );
            }
        }

        return $report;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private static function activityMetadataBySequence(WorkflowRun $run): array
    {
        $metadata = [];

        foreach ($run->activityExecutions as $execution) {
            if (! $execution instanceof ActivityExecution) {
                continue;
            }

            $sequence = self::intValue($execution->sequence);
            $path = ParallelChildGroup::metadataPathFromPayload([
                'parallel_group_path' => $execution->parallel_group_path,
            ]);

            if ($sequence === null || $path === []) {
                continue;
            }

            $metadata[$sequence] = $path;
        }

        return $metadata;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private static function childMetadataBySequence(WorkflowRun $run): array
    {
        $metadata = [];

        foreach ($run->childLinks as $link) {
            if (! $link instanceof WorkflowLink || $link->link_type !== 'child_workflow') {
                continue;
            }

            $sequence = self::intValue($link->sequence);
            $path = ParallelChildGroup::metadataPathFromPayload([
                'parallel_group_path' => $link->parallel_group_path,
            ]);

            if ($sequence === null || $path === []) {
                continue;
            }

            $metadata[$sequence] ??= $path;
        }

        return $metadata;
    }

    /**
     * @param list<array<string, mixed>> $path
     * @param array<string, int> $report
     */
    private static function backfillEvent(
        WorkflowHistoryEvent $event,
        array $path,
        string $kind,
        bool $dryRun,
        array &$report,
    ): void {
        $scannedKey = sprintf('%s_events_scanned', $kind);
        $updatedKey = sprintf('%s_events_updated', $kind);
        $wouldUpdateKey = sprintf('%s_events_would_update', $kind);
        $alreadyKey = sprintf('%s_events_already_authoritative', $kind);
        $missingKey = sprintf('%s_events_without_sidecar_metadata', $kind);

        $report[$scannedKey]++;

        if (ParallelChildGroup::metadataPathFromPayload($event->payload) !== []) {
            $report[$alreadyKey]++;

            return;
        }

        if ($path === []) {
            $report[$missingKey]++;

            return;
        }

        if ($dryRun) {
            $report[$wouldUpdateKey]++;

            return;
        }

        $event->forceFill([
            'payload' => array_merge($event->payload, ParallelChildGroup::payloadForPath($path)),
        ])->save();

        $report[$updatedKey]++;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?\d+$/', $value) === 1
            ? (int) $value
            : null;
    }
}
