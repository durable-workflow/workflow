<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;

/** Executable source-contract model, not a runtime metric or backend adapter. */
final class WorkflowTaskFailureEpisodeVectors
{
    public static function validate(string $json): int
    {
        self::require(strlen($json) <= 262144, 'Vector document exceeds its bound');
        $document = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        self::uniqueObjectKeys($json);
        self::fields($document, ['contract', 'format_version', 'status', 'normalized_transitions_are_wire_messages',
            'proposed_operator_metric_path', 'proposed_fields', 'authority', 'adoption_gates', 'vectors']);
        self::require($document->contract === 'workflow-task-failure-episodes'
            && $document->format_version === 1 && $document->status === 'source_proposal_not_enabled'
            && $document->normalized_transitions_are_wire_messages === false
            && $document->proposed_operator_metric_path === 'workflow_task_failure_episodes', 'Source boundary changed');
        self::fields(
            $document->proposed_fields,
            ['evaluation', 'count', 'oldest_started_at', 'max_age_ms', 'age_unknown_count']
        );
        self::fields($document->authority, ['clock', 'membership', 'anchor', 'repeat', 'reset', 'repair_origin',
            'noop', 'legacy', 'aggregate', 'retention', 'advisory', 'consumer']);
        foreach ([$document->proposed_fields, $document->authority] as $descriptions) {
            foreach (get_object_vars($descriptions) as $description) {
                self::require(is_string($description) && trim($description) !== '', 'Empty contract description');
            }
        }
        self::require(
            is_array($document->adoption_gates) && $document->adoption_gates !== [],
            'Missing adoption gates'
        );
        foreach ($document->adoption_gates as $gate) {
            self::require(is_string($gate) && trim($gate) !== '', 'Invalid adoption gate');
        }
        self::require(is_array($document->vectors) && $document->vectors !== []
            && count($document->vectors) <= 128, 'Invalid vector list');
        $ids = [];
        foreach ($document->vectors as $vector) {
            self::fields($vector, ['id', 'sample_at', 'backend_support', 'before', 'transitions', 'after',
                'expected_metric', 'expected_health']);
            self::require(is_string($vector->id) && preg_match('/\A[a-z][a-z0-9_]*\z/D', $vector->id) === 1
                && ! isset($ids[$vector->id]), 'Invalid or duplicate vector ID');
            $ids[$vector->id] = true;
            self::vector($vector);
        }

        return count($ids);
    }

    private static function vector(stdClass $vector): void
    {
        $sample = self::micros($vector->sample_at);
        self::require(
            in_array($vector->backend_support, ['supported', 'unsupported'], true),
            'Invalid backend support'
        );
        self::require(is_array($vector->transitions) && count($vector->transitions) <= 128
            && is_array($vector->after) && count($vector->after) <= 128, 'Invalid transitions or run list');
        if ($vector->before !== null) {
            self::state($vector->before);
        }
        $projected = $vector->before;
        $last = null;
        foreach ($vector->transitions as $transition) {
            $at = self::transition($transition);
            self::require($at <= $sample && ($last === null || $at >= $last), 'Transition clock is out of order');
            self::require(
                $projected instanceof stdClass && ! isset($projected->membership),
                'Transition lacks run authority'
            );
            $last = $at;
            $projected = self::apply($projected, $transition);
        }
        $runs = [];
        foreach ($vector->after as $state) {
            self::state($state);
            self::require(is_string($state->id ?? null) && $state->id !== '', 'Run membership lacks an ID');
            self::require(! isset($runs[$state->id]), 'Duplicate run membership');
            $runs[$state->id] = $state;
        }
        if (isset($projected->membership)) {
            self::require(
                (bool) array_filter($vector->after, static fn (stdClass $run): bool => isset($run->membership)),
                'Unknown membership cannot disappear without authoritative evaluation'
            );
        }
        if ($projected !== null && ! isset($projected->membership)) {
            self::require(isset($runs[$projected->id]), 'Projected run is missing');
            foreach (['open', 'episode_active', 'first_failure_at'] as $field) {
                self::require(
                    $runs[$projected->id]->{$field} === $projected->{$field},
                    'Transition projection differs: ' . $field
                );
            }
        }
        $metric = self::metric($vector->backend_support, $vector->after, $sample);
        self::fields($vector->expected_metric, array_keys($metric));
        foreach ($metric as $field => $value) {
            self::require($vector->expected_metric->{$field} === $value, $vector->id . ': metric differs: ' . $field);
        }
        $health = $metric['evaluation'] === 'complete' && $metric['count'] === 0 ? 'ok' : 'warning';
        self::require($vector->expected_health === $health, 'Health must preserve unknown and positive episodes');
    }

    private static function state(mixed $state): void
    {
        if ($state instanceof stdClass && isset($state->membership)) {
            self::fields($state, ['membership'], ['id', 'open']);
            self::require($state->membership === 'unknown', 'Invalid membership');
            if (property_exists($state, 'id')) {
                self::require(is_string($state->id) && $state->id !== '', 'Invalid run ID');
            }
            if (property_exists($state, 'open')) {
                self::require(is_bool($state->open), 'Invalid open flag');
            }

            return;
        }
        self::fields(
            $state,
            ['id', 'open', 'episode_active', 'first_failure_at'],
            ['age_authority', 'liveness', 'task_problem_history', 'retained_failed_tasks']
        );
        self::require(is_string($state->id) && $state->id !== '' && is_bool($state->open)
            && is_bool($state->episode_active), 'Invalid run state');
        self::require($state->open || ! $state->episode_active, 'Closed run cannot have an active episode');
        self::require($state->episode_active || $state->first_failure_at === null, 'Inactive episode has an anchor');
        if ($state->first_failure_at !== null) {
            self::micros($state->first_failure_at);
        }
        if (property_exists($state, 'age_authority')) {
            self::require($state->age_authority === 'unknown' && $state->episode_active
                && $state->first_failure_at === null, 'Invalid unknown-age authority');
        }
        self::require(! $state->episode_active || $state->first_failure_at !== null
            || ($state->age_authority ?? null) === 'unknown', 'Missing active age authority');
        if (property_exists($state, 'liveness')) {
            self::require(in_array($state->liveness, ['repair_needed', 'workflow_task_ready',
                'workflow_replay_blocked', 'workflow_waiting'], true), 'Invalid liveness annotation');
        }
        if (property_exists($state, 'task_problem_history')) {
            self::require(is_bool($state->task_problem_history), 'Invalid historical badge');
        }
        if (property_exists($state, 'retained_failed_tasks')) {
            self::require(
                is_int($state->retained_failed_tasks) && $state->retained_failed_tasks >= 0,
                'Invalid retained count'
            );
        }
    }

    private static function transition(mixed $transition): int
    {
        self::require($transition instanceof stdClass && is_string($transition->kind ?? null), 'Invalid transition');
        $extras = match ($transition->kind) {
            'ordinary_failure' => [[], ['retry_successor_created', 'another_workflow_task_open']],
            'automatic_retry', 'replay_block', 'run_closure', 'deadline_closure' => [[], []],
            'repair_command_accepted' => [['applied'], []],
            'failure_repair_applied' => [['writer_intent'], []],
            'repair_not_needed' => [['state_changed'], []],
            'automatic_lease_recovery', 'abandoned_task_recovery', 'delivery_redispatch' =>
                [['writer_intent', 'emitted_event', 'repair_count_incremented'], []],
            'waiting_for_history_ack' => [['task_completed'], ['committed']],
            'task_completion' => [['committed'], ['receipt_replay']],
            'progress_checkpoint' => [['committed', 'state_changed'], []],
            default => throw new InvalidArgumentException('Unknown transition kind'),
        };
        self::fields($transition, array_merge(['kind', 'at', 'authority', 'recorded'], $extras[0]), $extras[1]);
        self::require(in_array($transition->authority, ['current', 'stale', 'wrong_attempt', 'wrong_namespace',
            'wrong_owner', 'run_closed'], true) && is_bool($transition->recorded), 'Invalid transition authority');
        self::require(
            ! $transition->recorded || $transition->authority === 'current',
            'Refused authority cannot record'
        );
        foreach (['applied', 'state_changed', 'retry_successor_created', 'another_workflow_task_open',
            'repair_count_incremented', 'task_completed', 'committed', 'receipt_replay'] as $flag) {
            if (property_exists($transition, $flag)) {
                self::require(is_bool($transition->{$flag}), 'Invalid transition Boolean: ' . $flag);
            }
        }
        self::require(
            ! ($transition->committed ?? false) || $transition->recorded,
            'Unrecorded result cannot commit progress'
        );
        if ($transition->kind === 'failure_repair_applied') {
            self::require($transition->writer_intent === 'failure_episode_reset', 'Repair lacks reset intent');
        } elseif (isset($transition->writer_intent)) {
            self::require($transition->writer_intent === 'delivery_recovery'
                && $transition->emitted_event === 'RepairRequested', 'Recovery intent differs');
        }
        self::require(
            $transition->kind !== 'repair_command_accepted' || ! $transition->applied,
            'Admission is not applied repair'
        );
        self::require(
            $transition->kind !== 'repair_not_needed' || ! $transition->state_changed,
            'No-op cannot change state'
        );

        return self::micros($transition->at);
    }

    private static function apply(stdClass $before, stdClass $transition): stdClass
    {
        $after = clone $before;
        if (! $transition->recorded) {
            return $after;
        }
        self::require($after->open, 'Closed run cannot accept a transition');
        if (in_array($transition->kind, ['ordinary_failure', 'replay_block'], true) && ! $after->episode_active) {
            $after->episode_active = true;
            $after->first_failure_at = $transition->at;
        }
        $reset = match ($transition->kind) {
            'failure_repair_applied', 'run_closure', 'deadline_closure' => true,
            'task_completion' => $transition->committed && ! ($transition->receipt_replay ?? false),
            'progress_checkpoint' => $transition->committed && $transition->state_changed,
            'waiting_for_history_ack' => ($transition->committed ?? false) && $transition->task_completed,
            default => false,
        };
        if ($reset) {
            $after->episode_active = false;
            $after->first_failure_at = null;
            unset($after->age_authority);
        }
        if (in_array($transition->kind, ['run_closure', 'deadline_closure'], true)) {
            $after->open = false;
        }

        return $after;
    }

    /** @param list<stdClass> $runs
     * @return array<string, int|string|null>
     */
    private static function metric(string $support, array $runs, int $sample): array
    {
        $metric = [
            'evaluation' => 'unavailable',
            'count' => null,
            'oldest_started_at' => null,
            'max_age_ms' => null,
            'age_unknown_count' => null,
        ];
        if ($support !== 'supported' || array_filter(
            $runs,
            static fn (stdClass $run): bool => isset($run->membership)
        )) {
            return $metric;
        }
        $active = array_filter($runs, static fn (stdClass $run): bool => $run->open && $run->episode_active);
        $unknown = 0;
        $oldest = null;
        foreach ($active as $run) {
            if ($run->first_failure_at === null || self::micros($run->first_failure_at) > $sample) {
                ++$unknown;
            } elseif ($oldest === null || $run->first_failure_at < $oldest) {
                $oldest = $run->first_failure_at;
            }
        }

        return [
            'evaluation' => $unknown > 0 ? 'partial' : 'complete',
            'count' => count($active),
            'oldest_started_at' => $unknown > 0 ? null : $oldest,
            'max_age_ms' => $unknown > 0 ? null : ($oldest === null ? 0 : intdiv(
                $sample - self::micros($oldest),
                1000
            )),
            'age_unknown_count' => $unknown,
        ];
    }

    private static function micros(mixed $value): int
    {
        self::require(
            is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $value) === 1,
            'Timestamp must be exact UTC microseconds'
        );
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
        self::require($date !== false && $date->format('Y-m-d\TH:i:s.u\Z') === $value, 'Invalid timestamp');

        return (int) $date->format('U') * 1000000 + (int) $date->format('u');
    }

    /** @param list<string> $required
     * @param list<string> $optional
     */
    private static function fields(mixed $value, array $required, array $optional = []): void
    {
        self::require($value instanceof stdClass, 'Expected a JSON object');
        $keys = array_keys(get_object_vars($value));
        self::require(array_diff($required, $keys) === []
            && array_diff($keys, array_merge($required, $optional)) === [], 'Missing or unknown object member');
    }

    private static function uniqueObjectKeys(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $matches);
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [
                    'object' => $token === '{',
                    'key' => true,
                    'seen' => [],
                ];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack !== []) {
                $index = array_key_last($stack);
                if ($token === ',') {
                    $stack[$index]['key'] = true;
                } elseif ($token === ':') {
                    $stack[$index]['key'] = false;
                } elseif ($token[0] === '"' && $stack[$index]['object'] && $stack[$index]['key']) {
                    $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                    self::require(! isset($stack[$index]['seen'][$key]), 'Duplicate JSON object member');
                    $stack[$index]['seen'][$key] = true;
                }
            }
        }
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
