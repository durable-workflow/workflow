<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

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

    /**
     * Resolve the workflow class to use for a given in-flight run, preferring
     * the class that matches the `workflow_definition_fingerprint` recorded
     * in the run's `WorkflowStarted` history event.
     *
     * This keeps a run pinned to the definition it started under when a
     * deploy has promoted a new class under the same `workflow_type` while
     * the run is parked on a signal/timer. Without pinning the engine picks
     * the new class from `workflow_runs.workflow_class` and runs the wrong
     * code path against the existing history.
     *
     * Resolution order:
     *  1. Fast path — if no fingerprint was recorded (legacy run), or the
     *     recorded fingerprint equals the current class's fingerprint, or
     *     pinning is disabled via config, fall back to
     *     {@see TypeRegistry::resolveWorkflowClass()}.
     *  2. Reverse-lookup — ask the definition registry for the class whose
     *     source fingerprint matches the recorded hash. Requires the class
     *     to have been seen by {@see WorkflowDefinition::fingerprint()} in
     *     the current process.
     *  3. Fall back — if the registry has no match for the recorded
     *     fingerprint, resolve via {@see TypeRegistry::resolveWorkflowClass()}
     *     so the run still makes progress rather than failing hard. The
     *     `RunDetailView` fingerprint-drift signal still surfaces the
     *     mismatch to operators.
     *
     * Controlled by `workflows.v2.compatibility.pin_to_recorded_fingerprint`
     * (default `true`). Set to `false` to restore hot-swap behavior.
     *
     * @return class-string<Workflow>
     */
    public static function resolveClassForRun(WorkflowRun $run): string
    {
        $fallbackClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);

        if (! self::pinningEnabled()) {
            return $fallbackClass;
        }

        $recorded = self::recordedForRun($run);

        if ($recorded === null) {
            return $fallbackClass;
        }

        // Warm the reverse index for the fallback class so repeated calls
        // for a run whose fingerprint matches the current class stay on the
        // fast path (O(1) hash-equals) instead of running a registry lookup.
        $currentFingerprint = WorkflowDefinition::fingerprint($fallbackClass);

        if ($currentFingerprint !== null && hash_equals($recorded, $currentFingerprint)) {
            return $fallbackClass;
        }

        $pinnedClass = WorkflowDefinition::findClassByFingerprint($recorded);

        if ($pinnedClass !== null && is_subclass_of($pinnedClass, Workflow::class)) {
            return $pinnedClass;
        }

        return $fallbackClass;
    }

    private static function pinningEnabled(): bool
    {
        $configured = function_exists('config')
            ? config('workflows.v2.compatibility.pin_to_recorded_fingerprint', true)
            : true;

        return (bool) $configured;
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
        $event = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
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
