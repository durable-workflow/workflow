<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowUpdate;

final class WorkflowTaskPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function forUpdate(WorkflowUpdate $update): array
    {
        return array_filter([
            'workflow_wait_kind' => 'update',
            'open_wait_id' => sprintf('update:%s', $update->id),
            'resume_source_kind' => 'workflow_update',
            'resume_source_id' => $update->id,
            'workflow_update_id' => $update->id,
            'workflow_command_id' => $update->workflow_command_id,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSignal(WorkflowSignal $signal): array
    {
        return array_filter([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => sprintf('signal-application:%s', $signal->id),
            'resume_source_kind' => 'workflow_signal',
            'resume_source_id' => $signal->id,
            'workflow_signal_id' => $signal->id,
            'workflow_command_id' => $signal->workflow_command_id,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forChildResolution(WorkflowHistoryEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $sequence = self::intValue($payload['sequence'] ?? null);
        $childCallId = self::nonEmptyString($payload['child_call_id'] ?? null)
            ?? self::nonEmptyString($payload['workflow_link_id'] ?? null);
        $childRunId = self::nonEmptyString($payload['child_workflow_run_id'] ?? null);
        $openWaitId = $childCallId === null
            ? ($sequence === null ? null : sprintf('child:%d', $sequence))
            : sprintf('child:%s', $childCallId);

        return array_filter([
            'workflow_wait_kind' => 'child',
            'open_wait_id' => $openWaitId,
            'resume_source_kind' => 'child_workflow_run',
            'resume_source_id' => $childRunId,
            'child_call_id' => $childCallId,
            'child_workflow_run_id' => $childRunId,
            'workflow_sequence' => $sequence,
            'workflow_event_type' => $event->event_type?->value,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forMissingWorkflowTask(WorkflowRunSummary $summary): array
    {
        if ($summary->wait_kind === 'update') {
            return self::forMissingUpdateTask($summary);
        }

        if ($summary->wait_kind === 'signal') {
            return self::forMissingSignalTask($summary);
        }

        if ($summary->wait_kind === 'child') {
            return self::forMissingChildTask($summary);
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function forMissingUpdateTask(WorkflowRunSummary $summary): array
    {
        $resumeSourceId = self::nonEmptyString($summary->resume_source_id);
        $update = $summary->resume_source_kind === 'workflow_update' && $resumeSourceId !== null
            ? WorkflowUpdate::query()->find($resumeSourceId)
            : null;

        if ($update instanceof WorkflowUpdate) {
            return self::forUpdate($update);
        }

        return array_filter([
            'workflow_wait_kind' => 'update',
            'open_wait_id' => self::nonEmptyString($summary->open_wait_id),
            'resume_source_kind' => self::nonEmptyString($summary->resume_source_kind),
            'resume_source_id' => $resumeSourceId,
            'workflow_update_id' => $summary->resume_source_kind === 'workflow_update' ? $resumeSourceId : null,
            'workflow_command_id' => $summary->resume_source_kind === 'workflow_command' ? $resumeSourceId : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function forMissingSignalTask(WorkflowRunSummary $summary): array
    {
        $resumeSourceId = self::nonEmptyString($summary->resume_source_id);
        $signal = $summary->resume_source_kind === 'workflow_signal' && $resumeSourceId !== null
            ? WorkflowSignal::query()->find($resumeSourceId)
            : null;

        if ($signal instanceof WorkflowSignal) {
            return self::forSignal($signal);
        }

        return array_filter([
            'workflow_wait_kind' => 'signal',
            'open_wait_id' => self::nonEmptyString($summary->open_wait_id),
            'resume_source_kind' => self::nonEmptyString($summary->resume_source_kind),
            'resume_source_id' => $resumeSourceId,
            'workflow_signal_id' => $summary->resume_source_kind === 'workflow_signal' ? $resumeSourceId : null,
            'workflow_command_id' => $summary->resume_source_kind === 'workflow_command' ? $resumeSourceId : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function forMissingChildTask(WorkflowRunSummary $summary): array
    {
        $resumeSourceId = self::nonEmptyString($summary->resume_source_id);
        $openWaitId = self::nonEmptyString($summary->open_wait_id);
        $childCallId = $openWaitId !== null && str_starts_with($openWaitId, 'child:')
            ? substr($openWaitId, 6)
            : null;

        return array_filter([
            'workflow_wait_kind' => 'child',
            'open_wait_id' => $openWaitId,
            'resume_source_kind' => 'child_workflow_run',
            'resume_source_id' => $resumeSourceId,
            'child_call_id' => self::nonEmptyString($childCallId),
            'child_workflow_run_id' => $resumeSourceId,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : null;
    }
}
