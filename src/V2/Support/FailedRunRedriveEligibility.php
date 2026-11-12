<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

/**
 * Decide whether redrive would accept a failed run, and name the reason when
 * it would not.
 *
 * `DefaultWorkflowControlPlane::redrive()` applies this decision inside its
 * locked transaction. Run detail and describe read the same decision, so the
 * action an operator sees matches what redrive does.
 */
final class FailedRunRedriveEligibility
{
    public const REASON_ALREADY_REDRIVEN = 'already_redriven';

    /**
     * @param string|null $externalFingerprint the definition fingerprint a service worker presents with the
     *                                         redrive request
     * @param bool $fingerprintPresentedAtRedrive true when the caller cannot know the worker's fingerprint
     *                                            yet (run detail and describe): a service-mode run then only
     *                                            needs a recorded fingerprint
     *
     * @return array{
     *     reason: string|null,
     *     plan: array{
     *         eligible: bool,
     *         reason: string|null,
     *         resume_step_sequence: int|null,
     *         completed: list<array<string, mixed>>,
     *         reused_steps: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, scheduled_payload?: array<string, mixed>, signal_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>, child_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>
     *     }|null,
     *     started_payload: array<string, mixed>,
     *     definition_change?: array{workflow_class: class-string<Workflow>, from: string, to: string}|null,
     *     definition_mismatch?: array{sequence: int|null, kind: string|null}
     * }
     */
    public static function evaluate(
        WorkflowRun $source,
        ?WorkflowRun $currentRun,
        ?string $externalFingerprint = null,
        bool $fingerprintPresentedAtRedrive = false,
        bool $allowDefinitionChange = false,
    ): array {
        if ($currentRun?->id !== $source->id) {
            return self::refuse('source_run_not_current');
        }

        $plan = FailedRunRedrivePlan::forRun($source);

        if (! $plan['eligible']) {
            return self::refuse((string) $plan['reason']);
        }

        // Memo and search attribute rows carry to the successor as they are.
        // A value written by workflow code must come from an upsert step the
        // successor reuses, or its replay would not explain the row.
        if (
            ! self::rowsMatchReusedUpserts(
                $source->memos()
                    ->get(),
                $plan['reused_steps'],
                HistoryEventType::MemoUpserted,
            )
            || ! self::rowsMatchReusedUpserts(
                $source->searchAttributes()
                    ->get(),
                $plan['reused_steps'],
                HistoryEventType::SearchAttributesUpserted,
            )
        ) {
            return self::refuse('unsupported_visibility_metadata');
        }

        if (
            (is_string($source->arguments) && ExternalPayloads::isStoredReference($source->arguments))
            || WorkflowLink::query()
                ->where('child_workflow_run_id', $source->id)
                ->where('link_type', 'child_workflow')
                ->exists()
        ) {
            return self::refuse('unsupported_source_link_or_payload');
        }

        $executionDeadlineAt = $source->execution_deadline_at;
        if ($executionDeadlineAt !== null && now()->gte($executionDeadlineAt)) {
            return self::refuse('execution_deadline_elapsed');
        }

        $startedEvent = $source->historyEvents->firstWhere('event_type', HistoryEventType::WorkflowStarted);
        $startedPayload = $startedEvent instanceof WorkflowHistoryEvent && is_array($startedEvent->payload)
            ? $startedEvent->payload
            : [];
        unset($startedPayload['command'], $startedPayload['task']);

        if (! empty($startedPayload['parent_workflow_run_id'])) {
            return self::refuse('unsupported_source_metadata');
        }

        // Service-mode SDK workers are qualified to replay reused activities,
        // side effects, version markers and timers. Reused signal, memo,
        // search-attribute, update and child workflow steps are not qualified
        // yet, so a run a service worker started refuses them before anything
        // is written.
        if (
            ($startedPayload['workflow_definition_fingerprint_source'] ?? null) === 'worker'
            && (self::reusesServiceUnqualifiedSteps($plan['reused_steps']) || ($plan['update_bundles'] ?? []) !== [])
        ) {
            return self::refuse('service_redrive_history_not_qualified');
        }

        $sourceFingerprint = $startedPayload['workflow_definition_fingerprint'] ?? null;
        $definitionChange = null;

        if (! self::definitionMatches(
            $source,
            $sourceFingerprint,
            $externalFingerprint,
            $fingerprintPresentedAtRedrive,
        )) {
            if (! $allowDefinitionChange || ! is_string($sourceFingerprint) || $sourceFingerprint === '') {
                return self::refuse('workflow_definition_unavailable_or_changed');
            }

            // The source's recorded execution mode decides, not today's type
            // map: the server can't replay a service worker's code, even when
            // a local class is registered for its type later.
            $workerSourced = ($startedPayload['workflow_definition_fingerprint_source'] ?? null) === 'worker';
            $workflowClass = $workerSourced ? null : self::localWorkflowClass($source);
            $currentFingerprint = $workflowClass === null ? null : WorkflowDefinition::fingerprint($workflowClass);

            if ($workflowClass === null || $currentFingerprint === null) {
                return self::refuse('definition_change_requires_replay_check');
            }

            $definitionRefusal = FailedRunRedriveDefinitionCheck::check(
                $source,
                $workflowClass,
                $startedPayload,
                $plan,
            );

            if ($definitionRefusal !== null) {
                $refusal = self::refuse($definitionRefusal['reason']);

                // Only an incompatible prefix has a step to name.
                if ($definitionRefusal['reason'] === 'definition_incompatible_with_prefix') {
                    $refusal['definition_mismatch'] = [
                        'sequence' => $definitionRefusal['mismatch_sequence'],
                        'kind' => $definitionRefusal['mismatch_kind'],
                    ];
                }

                return $refusal;
            }

            $definitionChange = [
                'workflow_class' => $workflowClass,
                'from' => $sourceFingerprint,
                'to' => $currentFingerprint,
            ];
        } elseif (($plan['failed_step_kind'] ?? null) === 'workflow_code') {
            // The same code would throw at the same step again.
            return self::refuse('workflow_code_failure_requires_corrected_definition');
        }

        return [
            'reason' => null,
            'plan' => $plan,
            'started_payload' => $startedPayload,
            'definition_change' => $definitionChange,
        ];
    }

    /**
     * The reason run detail and describe show for a run's redrive action, or
     * null when redrive would accept it.
     */
    public static function blockedReason(WorkflowRun $run, ?WorkflowRun $currentRun): ?string
    {
        if ($run->status !== RunStatus::Failed) {
            return 'run_not_failed';
        }

        if (WorkflowLink::query()
            ->where('parent_workflow_run_id', $run->id)
            ->where('link_type', 'redrive')
            ->exists()) {
            return self::REASON_ALREADY_REDRIVEN;
        }

        return self::evaluate($run, $currentRun, fingerprintPresentedAtRedrive: true)['reason'];
    }

    /**
     * @param list<array{event_type: HistoryEventType, signal_events?: list<mixed>, child_events?: list<mixed>}> $reusedSteps
     */
    private static function reusesServiceUnqualifiedSteps(array $reusedSteps): bool
    {
        foreach ($reusedSteps as $step) {
            if (
                isset($step['signal_events'])
                || isset($step['child_events'])
                || in_array($step['event_type'], [
                    HistoryEventType::MemoUpserted,
                    HistoryEventType::SearchAttributesUpserted,
                    HistoryEventType::SignalApplied,
                ], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<\Workflow\V2\Models\WorkflowMemo|\Workflow\V2\Models\WorkflowSearchAttribute> $rows
     * @param list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}> $reusedSteps
     */
    private static function rowsMatchReusedUpserts(iterable $rows, array $reusedSteps, HistoryEventType $type): bool
    {
        $upsertSequences = [];
        foreach ($reusedSteps as $step) {
            $sequence = $step['payload']['sequence'] ?? null;
            if ($step['event_type'] === $type && is_int($sequence)) {
                $upsertSequences[$sequence] = true;
            }
        }

        foreach ($rows as $row) {
            $sequence = (int) $row->upserted_at_sequence;
            // Start-time values (step 0) and values inherited from an earlier
            // run in the chain are not written by this run's workflow code.
            if ($sequence > 0 && ! $row->inherited_from_parent && ! isset($upsertSequences[$sequence])) {
                return false;
            }
        }

        return true;
    }

    private static function definitionMatches(
        WorkflowRun $source,
        mixed $sourceFingerprint,
        ?string $externalFingerprint,
        bool $fingerprintPresentedAtRedrive,
    ): bool {
        if (! is_string($sourceFingerprint) || $sourceFingerprint === '') {
            return false;
        }

        $workflowClass = self::localWorkflowClass($source);

        if ($workflowClass !== null) {
            return $sourceFingerprint === WorkflowDefinition::fingerprint($workflowClass);
        }

        if ($source->workflow_class !== $source->workflow_type) {
            return false;
        }

        if ($fingerprintPresentedAtRedrive) {
            return true;
        }

        return is_string($externalFingerprint) && hash_equals($sourceFingerprint, $externalFingerprint);
    }

    /**
     * @return class-string<Workflow>|null
     */
    private static function localWorkflowClass(WorkflowRun $source): ?string
    {
        try {
            $resolved = TypeRegistry::resolveWorkflowClass($source->workflow_type, $source->workflow_type);
        } catch (LogicException) {
            $resolved = null;
        }

        if (is_string($resolved) && is_subclass_of($resolved, Workflow::class)) {
            return $resolved;
        }

        return is_subclass_of($source->workflow_class, Workflow::class) ? $source->workflow_class : null;
    }

    /**
     * @return array{reason: string, plan: null, started_payload: array<string, mixed>}
     */
    private static function refuse(string $reason): array
    {
        return [
            'reason' => $reason,
            'plan' => null,
            'started_payload' => [],
        ];
    }
}
