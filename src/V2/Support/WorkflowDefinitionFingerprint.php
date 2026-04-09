<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowDefinitionFingerprint
{
    public static function recordedForRun(WorkflowRun $run): ?string
    {
        return self::stringValue(
            self::workflowStartedEvent($run)?->payload['workflow_definition_fingerprint'] ?? null
        );
    }

    public static function currentForRun(WorkflowRun $run): ?string
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return null;
        }

        return WorkflowDefinition::fingerprint($workflowClass);
    }

    public static function matchesCurrent(WorkflowRun $run): ?bool
    {
        $recorded = self::recordedForRun($run);
        $current = self::currentForRun($run);

        if ($recorded === null || $current === null) {
            return null;
        }

        return hash_equals($recorded, $current);
    }

    private static function workflowStartedEvent(WorkflowRun $run): ?WorkflowHistoryEvent
    {
        if ($run->relationLoaded('historyEvents')) {
            /** @var WorkflowHistoryEvent|null $event */
            $event = $run->historyEvents->first(
                static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::WorkflowStarted
            );

            return $event;
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', HistoryEventType::WorkflowStarted->value)
            ->orderBy('sequence')
            ->first();

        return $event;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
