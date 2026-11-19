<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Throwable;
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

    /**
     * The definition a redrive replaced when it moved this run to corrected
     * code, or null when the run kept its source's definition.
     */
    public static function changedFromForRun(WorkflowRun $run): ?string
    {
        return self::stringValue(self::workflowStartedEvent($run)?->payload['definition_changed_from'] ?? null);
    }

    /**
     * The fingerprint algorithm version this run compares by: the version of
     * its recorded fingerprint. A run that recorded none compares by
     * version 1, as before versioned fingerprints existed.
     */
    public static function versionForRun(WorkflowRun $run): int
    {
        return SourceFingerprint::versionOf(self::recordedForRun($run));
    }

    /**
     * The current definition's fingerprint, computed with the algorithm of
     * the run's recorded fingerprint so the two are comparable. A run with no
     * recorded fingerprint gets the version new runs record.
     */
    public static function currentForRun(WorkflowRun $run): ?string
    {
        return self::currentFor($run, self::recordedForRun($run));
    }

    public static function matchesCurrent(WorkflowRun $run): ?bool
    {
        $recorded = self::recordedForRun($run);
        $current = self::currentFor($run, $recorded);

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
        // Compare by the algorithm the recorded fingerprint names.
        $currentFingerprint = WorkflowDefinition::fingerprint(
            $fallbackClass,
            SourceFingerprint::versionOf($recorded),
        );

        if ($currentFingerprint !== null && hash_equals($recorded, $currentFingerprint)) {
            return $fallbackClass;
        }

        $pinnedClass = WorkflowDefinition::findClassByFingerprint($recorded);

        if ($pinnedClass !== null && is_subclass_of($pinnedClass, Workflow::class)) {
            return $pinnedClass;
        }

        return $fallbackClass;
    }

    private static function currentFor(WorkflowRun $run, ?string $recorded): ?string
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return null;
        }

        return WorkflowDefinition::fingerprint(
            $workflowClass,
            $recorded === null ? SourceFingerprint::CURRENT : SourceFingerprint::versionOf($recorded),
        );
    }

    private static function pinningEnabled(): bool
    {
        if (! function_exists('config')) {
            return true;
        }

        try {
            return (bool) config('workflows.v2.compatibility.pin_to_recorded_fingerprint', true);
        } catch (Throwable) {
            return true;
        }
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
