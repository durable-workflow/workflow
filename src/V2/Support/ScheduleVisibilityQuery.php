<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Models\WorkflowSchedule;

/**
 * Schedule-only system predicates. Workflow-run queries and registered search
 * attributes have separate contracts and are not accepted by this planner.
 */
final class ScheduleVisibilityQuery
{
    /**
     * @var array<string, array{column: string, type: string}>
     */
    private const SYSTEM_FIELDS = [
        'ScheduleId' => [
            'column' => 'schedule_id',
            'type' => 'keyword',
        ],
        'Status' => [
            'column' => 'status',
            'type' => 'schedule_status',
        ],
        'WorkflowType' => [
            'column' => 'action->workflow_type',
            'type' => 'keyword',
        ],
        'TaskQueue' => [
            'column' => 'action->task_queue',
            'type' => 'keyword',
        ],
        'Note' => [
            'column' => 'note',
            'type' => 'text',
        ],
    ];

    /**
     * Parse the documented schedule visibility grammar.
     *
     * The grammar deliberately supports only equality predicates joined by
     * AND. Keeping the accepted surface small makes unsupported predicates a
     * typed refusal instead of an expression that is silently ignored.
     *
     * @return list<array{field: string, column: string, type: string, literal: bool|float|int|string}>
     */
    public function parse(string $query): array
    {
        // Match the native request normalization before its 1..2048 rule:
        // Unicode White_Space plus Laravel's BOM/zero-width edge characters.
        // Quoted literal contents are untouched.
        $edge = '\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}'
            . '\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}'
            . '\x{FEFF}\x{200B}\x{200E}';
        $normalized = preg_replace('/\A[' . $edge . ']+|[' . $edge . ']+\z/u', '', $query);
        if ($normalized === null) {
            throw new ScheduleVisibilityQueryException('Schedule visibility query must be valid UTF-8.');
        }
        $length = preg_match_all('/./us', $normalized);
        if ($length === false || $length > 2048) {
            throw new ScheduleVisibilityQueryException(
                'Schedule visibility query must contain at most 2048 UTF-8 characters.'
            );
        }

        $query = trim($normalized);

        if ($query === '') {
            throw new ScheduleVisibilityQueryException('Schedule visibility query must not be empty.');
        }

        $parts = $this->splitAndPredicates($query);

        if ($parts === []) {
            throw new ScheduleVisibilityQueryException(
                'Schedule visibility query contains an unterminated string or empty predicate.',
            );
        }

        $predicates = [];

        foreach ($parts as $part) {
            $this->rejectUnsupportedPredicate($part);

            if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.+)$/s', $part, $matches) !== 1) {
                throw new ScheduleVisibilityQueryException(
                    'Schedule visibility predicates must use: Field = literal.',
                );
            }

            $field = $matches[1];
            $literal = $this->parseLiteral(trim($matches[2]));
            $system = self::SYSTEM_FIELDS[$field] ?? null;

            if ($system !== null) {
                $type = $system['type'];
                $column = $system['column'];
            } else {
                throw new ScheduleVisibilityQueryException(
                    sprintf(
                        'Schedule visibility field [%s] is not searchable. Use ScheduleId, Status, '
                            . 'WorkflowType, TaskQueue, or Note.',
                        $field,
                    ),
                    'unsupported_schedule_visibility_field',
                );
            }

            $this->validateLiteralType($field, $type, $literal);

            $predicates[] = [
                'field' => $field,
                'column' => $column,
                'type' => $type,
                'literal' => $literal,
            ];
        }

        return $predicates;
    }

    /**
     * Build a fresh namespace-bound schedule query. Call counts/order/pagination
     * on the returned builder; never filter an already selected page.
     *
     * @return Builder<WorkflowSchedule>
     */
    public function query(string $namespace, string $query): Builder
    {
        if (trim($namespace) === '') {
            throw new ScheduleVisibilityQueryException('Schedule visibility requires an explicit namespace.');
        }

        $predicates = $this->parse($query);
        $model = ConfiguredV2Models::resolve('schedule_model', WorkflowSchedule::class);
        $builder = NamespaceIdentity::constrain($model::query(), $namespace)
            ->where('status', '!=', 'deleted');

        foreach ($predicates as $predicate) {
            $builder->where($predicate['column'], '=', $predicate['literal']);
        }

        return $builder;
    }

    private function rejectUnsupportedPredicate(string $predicate): void
    {
        $unquoted = $this->withoutQuotedLiterals($predicate);

        if (preg_match('/(?:!=|<>|>=|<=|>|<)/', $unquoted) === 1
            || preg_match('/\b(?:OR|NOT|IN|LIKE|CONTAINS|STARTS_WITH|ENDS_WITH)\b/i', $unquoted) === 1
        ) {
            throw new ScheduleVisibilityQueryException(
                'Schedule visibility supports only equality predicates joined by AND.',
                'unsupported_schedule_visibility_predicate',
            );
        }
    }

    private function withoutQuotedLiterals(string $value): string
    {
        $result = '';
        $quote = null;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($character === '\\' && $index + 1 < $length) {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                $result .= ' ';

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $result .= ' ';

                continue;
            }

            $result .= $character;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function splitAndPredicates(string $query): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $length = strlen($query);

        for ($index = 0; $index < $length; $index++) {
            $character = $query[$index];

            if ($quote !== null) {
                $current .= $character;

                if ($character === '\\' && $index + 1 < $length) {
                    $current .= $query[++$index];

                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $current .= $character;

                continue;
            }

            if ($this->startsAndAt($query, $index)) {
                $part = trim($current);

                if ($part === '') {
                    return [];
                }

                $parts[] = $part;
                $current = '';
                $index += 2;

                continue;
            }

            $current .= $character;
        }

        $last = trim($current);

        if ($quote !== null || $last === '') {
            return [];
        }

        $parts[] = $last;

        return $parts;
    }

    private function startsAndAt(string $query, int $index): bool
    {
        if (strncasecmp(substr($query, $index, 3), 'AND', 3) !== 0) {
            return false;
        }

        $before = $index === 0 ? ' ' : $query[$index - 1];
        $after = $query[$index + 3] ?? ' ';

        return ctype_space($before) && ctype_space($after);
    }

    private function parseLiteral(string $literal): bool|float|int|string
    {
        if ($literal === '') {
            throw new ScheduleVisibilityQueryException('Schedule visibility predicate literal must not be empty.');
        }

        if ($literal[0] === '"') {
            $decoded = json_decode($literal, true);

            if (! is_string($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                throw new ScheduleVisibilityQueryException(
                    sprintf('Schedule visibility literal [%s] is not a valid JSON string.', $literal),
                );
            }

            return $decoded;
        }

        if ($literal[0] === "'") {
            if (strlen($literal) < 2 || ! str_ends_with($literal, "'")) {
                throw new ScheduleVisibilityQueryException(
                    sprintf('Schedule visibility literal [%s] is not a valid quoted string.', $literal),
                );
            }

            return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($literal, 1, -1));
        }

        if (strcasecmp($literal, 'true') === 0) {
            return true;
        }

        if (strcasecmp($literal, 'false') === 0) {
            return false;
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/', $literal) === 1) {
            return $this->parseIntegerLiteral($literal);
        }

        if (preg_match('/^-?(?:0|[1-9][0-9]*)\.[0-9]+$/', $literal) === 1) {
            return $this->parseDecimalLiteral($literal);
        }

        throw new ScheduleVisibilityQueryException(
            sprintf('Schedule visibility literal [%s] must be a quoted string, number, or boolean.', $literal),
        );
    }

    private function parseIntegerLiteral(string $literal): int
    {
        $value = filter_var($literal, FILTER_VALIDATE_INT);

        if ($value === false) {
            throw new ScheduleVisibilityQueryException(
                'Schedule visibility integer literal is outside the supported integer range.',
            );
        }

        return $value;
    }

    private function parseDecimalLiteral(string $literal): float
    {
        $value = (float) $literal;

        if (! is_finite($value)) {
            throw new ScheduleVisibilityQueryException('Schedule visibility numeric literal must be finite.');
        }

        return $value;
    }

    private function validateLiteralType(string $field, string $type, bool|float|int|string $literal): void
    {
        $valid = is_string($literal) && ($type !== 'schedule_status' || in_array($literal, ['active', 'paused'], true));

        if ($valid) {
            return;
        }

        throw new ScheduleVisibilityQueryException(sprintf(
            'Schedule visibility field [%s] requires a %s literal.',
            $field,
            $type === 'schedule_status' ? 'quoted active or paused' : 'quoted string',
        ));
    }
}
