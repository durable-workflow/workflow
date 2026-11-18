<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

/**
 * Decides unresolved condition waits from durable input order.
 *
 * A condition wait that already recorded ConditionWaitSatisfied or
 * ConditionWaitTimedOut replays that outcome and never reaches this class.
 * For an unresolved wait, the engine evaluates the predicate on the state the
 * wait started with and again after each input it applies at the wait's step,
 * in durable order. The first evaluation that returns true satisfies the
 * wait; later input cannot reverse it.
 *
 * When the wait's own condition-timeout TimerFired is in history, only input
 * whose acceptance is ordered before that record is eligible. The first input
 * ordered after it closes the wait to further evaluation, so input that
 * arrived after the deadline cannot rescue it. This is the same rule named
 * signal waits use: the earlier durable record wins, regardless of when the
 * workflow task runs or what the wall clock says.
 *
 * @internal
 */
final class ConditionWaitInputOrder
{
    /**
     * @var array<int, array{condition: callable, boundary: int|null, satisfied: bool, closed: bool}>
     */
    private array $waits = [];

    /**
     * @param int $sequence the condition wait's workflow step
     * @param int|null $timeoutFiredSequence history sequence of the wait's condition-timeout TimerFired
     */
    public function track(int $sequence, callable $condition, ?int $timeoutFiredSequence): void
    {
        $this->waits[$sequence] = [
            'condition' => $condition,
            'boundary' => $timeoutFiredSequence,
            'satisfied' => false,
            'closed' => false,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->waits === [];
    }

    /**
     * Evaluate each tracked wait on the state it starts with.
     */
    public function observeEntry(): void
    {
        foreach (array_keys($this->waits) as $sequence) {
            $this->evaluate($sequence);
        }
    }

    /**
     * Evaluate each tracked wait after an input's effects were applied.
     *
     * @param int|null $acceptedSequence history sequence of the input's acceptance, when known
     */
    public function observeInput(?int $acceptedSequence): void
    {
        foreach ($this->waits as $sequence => $wait) {
            if ($wait['satisfied'] || $wait['closed']) {
                continue;
            }

            $boundary = $wait['boundary'];

            if ($boundary !== null && ($acceptedSequence === null || $acceptedSequence >= $boundary)) {
                $this->waits[$sequence]['closed'] = true;

                continue;
            }

            $this->evaluate($sequence);
        }
    }

    public function satisfied(int $sequence): bool
    {
        return $this->waits[$sequence]['satisfied'] ?? false;
    }

    /**
     * The history sequence of the UpdateAccepted record for an update.
     */
    public static function updateAcceptedSequence(WorkflowRun $run, ?string $updateId, ?string $commandId): ?int
    {
        if ($updateId === null && $commandId === null) {
            return null;
        }

        /** @var WorkflowHistoryEvent|null $accepted */
        $accepted = $run->historyEvents->first(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type === HistoryEventType::UpdateAccepted
                && (
                    ($updateId !== null && ($event->payload['update_id'] ?? null) === $updateId)
                    || ($commandId !== null && $event->workflow_command_id === $commandId)
                )
        );

        return $accepted?->sequence === null ? null : (int) $accepted->sequence;
    }

    /**
     * The history sequence of the acceptance behind a recorded UpdateApplied.
     *
     * History without a matching UpdateAccepted falls back to the applied
     * record itself, which is never earlier than the acceptance.
     */
    public static function appliedUpdateAcceptedSequence(WorkflowRun $run, WorkflowHistoryEvent $applied): ?int
    {
        $updateId = $applied->payload['update_id'] ?? null;

        return self::updateAcceptedSequence(
            $run,
            is_string($updateId) && $updateId !== '' ? $updateId : null,
            is_string($applied->workflow_command_id) && $applied->workflow_command_id !== ''
                ? $applied->workflow_command_id
                : null,
        ) ?? ($applied->sequence === null ? null : (int) $applied->sequence);
    }

    private function evaluate(int $sequence): void
    {
        $wait = $this->waits[$sequence];

        if ($wait['satisfied'] || $wait['closed']) {
            return;
        }

        try {
            $satisfied = ($wait['condition'])() === true;
        } catch (Throwable) {
            // An intermediate evaluation that throws is not a satisfaction. The
            // engine still evaluates the final state itself and fails the run
            // when that evaluation throws, as it did before input ordering.
            $satisfied = false;
        }

        $this->waits[$sequence]['satisfied'] = $satisfied;
    }
}
