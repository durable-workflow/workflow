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
     *         reused_steps: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, scheduled_payload?: array<string, mixed>}>
     *     }|null,
     *     started_payload: array<string, mixed>
     * }
     */
    public static function evaluate(
        WorkflowRun $source,
        ?WorkflowRun $currentRun,
        ?string $externalFingerprint = null,
        bool $fingerprintPresentedAtRedrive = false,
    ): array {
        if ($currentRun?->id !== $source->id) {
            return self::refuse('source_run_not_current');
        }

        $plan = FailedRunRedrivePlan::forRun($source);

        if (! $plan['eligible']) {
            return self::refuse((string) $plan['reason']);
        }

        // Start-time memos and search attributes carry to the successor.
        // Values upserted by workflow code belong to the replayed prefix,
        // which redrive does not copy yet.
        if (
            $source->memos()
                ->where('upserted_at_sequence', '>', 0)
                ->exists()
            || $source->searchAttributes()
                ->where('upserted_at_sequence', '>', 0)
                ->exists()
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

        if (! self::definitionMatches(
            $source,
            $startedPayload['workflow_definition_fingerprint'] ?? null,
            $externalFingerprint,
            $fingerprintPresentedAtRedrive,
        )) {
            return self::refuse('workflow_definition_unavailable_or_changed');
        }

        return [
            'reason' => null,
            'plan' => $plan,
            'started_payload' => $startedPayload,
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
