<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
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
     * @return string|null the refusal reason, or null when the new code replays the prefix
     */
    public static function check(
        WorkflowRun $source,
        string $workflowClass,
        array $startedPayload,
        array $plan,
    ): ?string {
        $resumeStep = $plan['resume_step_sequence'];
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
            return 'definition_check_over_budget';
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
        } catch (Throwable) {
            return 'definition_incompatible_with_prefix';
        }

        // The new code must use every reused step and stop at the step where
        // the source failed, either to take it or to finish there.
        return $state->sequence === $resumeStep ? null : 'definition_incompatible_with_prefix';
    }
}
