<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Exceptions\ConditionOccurrenceDeadlineConflict;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTimer;

/** @internal The opt-in first-open wall deadline of one authored condition call. */
final class ConditionOccurrenceDeadline
{
    /**
     * An empty array means legacy; null means invalid. The original budget is
     * separate from the physical open's remaining timeout.
     *
     * @param array<string, mixed> $command
     * @return array{deadline_policy: string, condition_wait_timeout_budget_seconds: int}|array{}|null
     */
    public static function fields(array $command): ?array
    {
        $policy = $command['deadline_policy'] ?? null;
        $budget = $command['condition_wait_timeout_budget_seconds'] ?? null;
        if ($policy === null && $budget === null) {
            return [];
        }

        $timeout = $command['timeout_seconds'] ?? null;
        $occurrence = $command['condition_wait_occurrence_id'] ?? null;
        if ($policy !== 'occurrence' || ! is_int($budget) || $budget < 0
            || ! is_int($timeout) || $timeout < 0 || $timeout > $budget
            || ! is_string($occurrence) || trim($occurrence) === '') {
            return null;
        }

        return [
            'deadline_policy' => $policy,
            'condition_wait_timeout_budget_seconds' => $budget,
        ];
    }

    /**
     * Called under the completion's run lock. Read current history, including
     * earlier commands in this batch, rather than a cached relation. A refusal
     * is thrown so signal application and every sibling command roll back too.
     *
     * @param array<string, mixed> $command
     * @return array{deadline_at: CarbonInterface|null, keep_open: bool}
     */
    public static function resolve(WorkflowRun $run, array $command): array
    {
        $fields = self::fields($command);
        if ($fields === null) {
            throw new ConditionOccurrenceDeadlineConflict('invalid_condition_deadline_policy');
        }
        $occurrence = $command['condition_wait_occurrence_id'] ?? null;
        $budget = $fields['condition_wait_timeout_budget_seconds'] ?? null;
        if (! is_string($occurrence) || $occurrence === '') {
            return [
                'deadline_at' => null,
                'keep_open' => false,
            ];
        }

        $current = clone $run;
        $current->unsetRelation('historyEvents');
        $chain = array_values(array_filter(
            ConditionWaits::forRun($current),
            static fn (array $wait): bool => $wait['condition_wait_occurrence_id'] === $occurrence
        ));
        if ($budget === null) {
            foreach ($chain as $wait) {
                if ($wait['deadline_policy'] !== null || $wait['condition_wait_timeout_budget_seconds'] !== null) {
                    throw new ConditionOccurrenceDeadlineConflict('condition_wait_occurrence_mismatch');
                }
            }

            return [
                'deadline_at' => null,
                'keep_open' => false,
            ];
        }

        if ($chain === []) {
            if ($command['timeout_seconds'] !== $budget) {
                throw new ConditionOccurrenceDeadlineConflict('condition_wait_occurrence_mismatch');
            }

            return [
                'deadline_at' => now()
                    ->addSeconds($budget),
                'keep_open' => false,
            ];
        }

        $first = $chain[0];
        if ($first['deadline_policy'] !== 'occurrence' || $first['condition_wait_timeout_budget_seconds'] === null) {
            throw new ConditionOccurrenceDeadlineConflict('condition_wait_anchor_unavailable');
        }
        $keepOpen = false;
        foreach ($chain as $wait) {
            if ($wait['deadline_policy'] !== 'occurrence'
                || $wait['condition_wait_timeout_budget_seconds'] !== $budget
                || $wait['condition_key'] !== ($command['condition_key'] ?? null)
                || $wait['condition_definition_fingerprint'] !== ($command['condition_definition_fingerprint'] ?? null)) {
                throw new ConditionOccurrenceDeadlineConflict('condition_wait_occurrence_mismatch');
            }
            if ($wait['timeout_fired_at'] !== null) {
                throw new ConditionOccurrenceDeadlineConflict('condition_wait_occurrence_timed_out');
            }
            $keepOpen = $keepOpen || $wait['status'] === 'open';
        }

        /** @var WorkflowTimer|null $timer */
        $timer = ConfiguredV2Models::query('timer_model', WorkflowTimer::class)
            ->where('workflow_run_id', $run->id)
            ->whereKey($first['timer_id'])
            ->first();
        if (! $timer instanceof WorkflowTimer || (int) $timer->sequence !== $first['sequence']
            || ! $first['deadline_at'] instanceof CarbonInterface) {
            throw new ConditionOccurrenceDeadlineConflict('condition_wait_anchor_unavailable');
        }

        return [
            // The scheduled history retains the first deadline even if an
            // older MariaDB schema rewrote the cancelled timer row's fire_at.
            'deadline_at' => $first['deadline_at'],
            'keep_open' => $keepOpen,
        ];
    }
}
