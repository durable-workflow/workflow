<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Exceptions\ActivityRetryPolicyMismatchException;
use Workflow\V2\Exceptions\ConditionWaitDefinitionMismatchException;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Exceptions\VersionRangeMismatchException;
use Workflow\V2\Exceptions\WorkflowEntryArgumentsMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Workflow;

/**
 * Before redrive accepts a changed workflow definition, replay the successor's
 * reused prefix with the new code, in memory, inside the redrive transaction.
 *
 * The check writes nothing. It is bounded by the history budget, so the run
 * lock is never held for a prefix larger than an ordinary replay would load.
 */
final class FailedRunRedriveDefinitionCheck
{
    /**
     * @param class-string<Workflow> $workflowClass the definition the successor would run
     * @param array<string, mixed> $startedPayload the source's WorkflowStarted payload
     * @param array{resume_step_sequence: int|null, reused_steps: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null, scheduled_payload?: array<string, mixed>, signal_events?: list<array{event_type: HistoryEventType, payload: array<string, mixed>, recorded_at: string|null}>}>} $plan
     *
     * @return array{reason: string, mismatch_sequence: int|null, mismatch_kind: string|null}|null the
     *     refusal, or null when the new code replays the prefix. The mismatch fields name only the
     *     first incompatible step and a stable kind, never an exception message or payload.
     */
    public static function check(
        WorkflowRun $source,
        string $workflowClass,
        array $startedPayload,
        array $plan,
    ): ?array {
        $resumeStep = (int) $plan['resume_step_sequence'];
        unset($startedPayload['definition_changed_from']);
        $started = array_merge($startedPayload, [
            'workflow_class' => $workflowClass,
            'workflow_definition_fingerprint' => WorkflowDefinition::fingerprint($workflowClass),
        ]);
        $events = [
            [
                'event_type' => HistoryEventType::WorkflowStarted,
                'payload' => $started,
            ],
            ...FailedRunRedrivePlan::successorEvents($plan, $source->id),
        ];

        if (
            count($events) > HistoryBudget::eventHardThreshold()
            || strlen(json_encode($events, JSON_THROW_ON_ERROR)) > HistoryBudget::sizeBytesHardThreshold()
        ) {
            return self::refusal('definition_check_over_budget');
        }

        $candidate = $source->replicate();
        $candidate->forceFill([
            'id' => (string) Str::ulid(),
            'workflow_class' => $workflowClass,
            'status' => RunStatus::Running->value,
            'closed_at' => null,
            'closed_reason' => null,
            'started_at' => now(),
        ]);

        $history = new Collection();
        foreach ($events as $index => $event) {
            $history->push((new WorkflowHistoryEvent())->forceFill([
                'id' => (string) Str::ulid(),
                'workflow_run_id' => $candidate->id,
                'sequence' => $index + 1,
                'event_type' => $event['event_type'],
                'payload' => $event['payload'],
                'recorded_at' => now(),
            ]));
        }

        // Every relation replay reads is set, so the check never loads the
        // source's rows for an id that doesn't exist.
        $candidate->setRelation('instance', $source->instance);
        $candidate->setRelation('historyEvents', $history);
        foreach (['activityExecutions', 'timers', 'failures', 'commands', 'signals', 'childLinks'] as $relation) {
            $candidate->setRelation($relation, new Collection());
        }

        try {
            $state = (new QueryStateReplayer())->replayState($candidate);
        } catch (Throwable $throwable) {
            [$sequence, $kind] = self::mismatch($throwable);

            return self::refusal('definition_incompatible_with_prefix', $sequence, $kind);
        }

        // The new code must use every reused step and stop at the step where
        // the source failed, either to take it or to finish there.
        if ($state->sequence === $resumeStep) {
            return null;
        }

        // The first recorded step the new code didn't take. A workflow that
        // returns before any step reports position 0, which is step 1.
        return self::refusal(
            'definition_incompatible_with_prefix',
            max(1, $state->sequence),
            $state->current === null ? 'finished_before_boundary' : 'new_step_before_boundary',
        );
    }

    /**
     * @return array{int|null, string}
     */
    private static function mismatch(Throwable $throwable): array
    {
        return match (true) {
            $throwable instanceof HistoryEventShapeMismatchException => [
                $throwable->workflowSequence,
                'step_shape',
            ],
            $throwable instanceof ActivityRetryPolicyMismatchException => [
                $throwable->workflowSequence,
                'activity_retry_policy',
            ],
            $throwable instanceof ConditionWaitDefinitionMismatchException => [
                $throwable->workflowSequence,
                'condition_wait_definition',
            ],
            $throwable instanceof VersionRangeMismatchException => [$throwable->workflowSequence, 'version_range'],
            $throwable instanceof WorkflowEntryArgumentsMismatchException => [null, 'entry_arguments'],
            default => [null, 'replay_error'],
        };
    }

    /**
     * @return array{reason: string, mismatch_sequence: int|null, mismatch_kind: string|null}
     */
    private static function refusal(string $reason, ?int $sequence = null, ?string $kind = null): array
    {
        return [
            'reason' => $reason,
            'mismatch_sequence' => $sequence,
            'mismatch_kind' => $kind,
        ];
    }
}
