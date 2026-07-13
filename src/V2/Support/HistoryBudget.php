<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use JsonException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

final class HistoryBudget
{
    public const PRESSURE_OK = 'ok';

    public const PRESSURE_APPROACHING = 'approaching';

    public const PRESSURE_CONTINUE_AS_NEW_RECOMMENDED = 'continue_as_new_recommended';

    public const DIMENSION_EVENT_COUNT = 'event_count';

    public const DIMENSION_SIZE_BYTES = 'size_bytes';

    public const DIMENSION_FAN_OUT = 'fan_out';

    private const DEFAULT_EVENT_HARD_THRESHOLD = 10000;

    private const DEFAULT_EVENT_WARNING_THRESHOLD = 8000;

    private const DEFAULT_SIZE_BYTES_HARD_THRESHOLD = 5242880;

    private const DEFAULT_SIZE_BYTES_WARNING_THRESHOLD = 4194304;

    private const DEFAULT_FAN_OUT_HARD_THRESHOLD = 200;

    private const DEFAULT_FAN_OUT_WARNING_THRESHOLD = 160;

    /**
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    public static function forRun(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        $historyEventCount = $run->historyEvents->count();
        $historySizeBytes = $run->historyEvents
            ->sum(static fn (WorkflowHistoryEvent $event): int => self::eventSizeBytes($event));
        $historyFanOut = self::maxParallelFanOut($run);

        return self::summarize($historyEventCount, $historySizeBytes, $historyFanOut);
    }

    /**
     * Resolve the canonical history budget without hydrating the run's
     * history relation. Current, complete projection counters are the fast
     * path; a single aggregate over the configured history-event model is
     * the bounded fallback for missing or incomplete projections.
     *
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    public static function forRunBounded(WorkflowRun $run): array
    {
        /** @var WorkflowRunSummary|null $summary */
        $summary = ConfiguredV2Models::query('run_summary_model', WorkflowRunSummary::class)
            ->select([
                'id',
                'history_event_count',
                'history_size_bytes',
                'history_fan_out',
            ])
            ->find($run->id);

        if ($summary instanceof WorkflowRunSummary && self::summaryIsComplete($run, $summary)) {
            return self::fromCounters(
                (int) $summary->history_event_count,
                (int) $summary->history_size_bytes,
                (int) $summary->history_fan_out,
            );
        }

        $counters = self::aggregateCounters($run);

        return self::fromCounters(
            $counters['history_event_count'],
            $counters['history_size_bytes'],
            $counters['history_fan_out'],
        );
    }

    /**
     * Build the budget payload from already-aggregated counters (e.g. cached
     * on a projection table) without re-loading history events.
     *
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    public static function fromCounters(
        int $historyEventCount,
        int $historySizeBytes,
        int $historyFanOut,
    ): array {
        return self::summarize(
            max(0, $historyEventCount),
            max(0, $historySizeBytes),
            max(0, $historyFanOut),
        );
    }

    public static function eventHardThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_event_threshold',
            self::DEFAULT_EVENT_HARD_THRESHOLD,
        );
    }

    public static function eventWarningThreshold(): int
    {
        return self::warningThreshold(
            'workflows.v2.history_budget.event_warning_threshold',
            self::DEFAULT_EVENT_WARNING_THRESHOLD,
            self::eventHardThreshold(),
        );
    }

    /**
     * Backwards-compatible alias for {@see eventHardThreshold()}. Existing
     * callers that asked for "the event threshold" want the continue-as-new
     * boundary (the hard threshold).
     */
    public static function eventThreshold(): int
    {
        return self::eventHardThreshold();
    }

    public static function sizeBytesHardThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_size_bytes_threshold',
            self::DEFAULT_SIZE_BYTES_HARD_THRESHOLD,
        );
    }

    public static function sizeBytesWarningThreshold(): int
    {
        return self::warningThreshold(
            'workflows.v2.history_budget.size_bytes_warning_threshold',
            self::DEFAULT_SIZE_BYTES_WARNING_THRESHOLD,
            self::sizeBytesHardThreshold(),
        );
    }

    /**
     * Backwards-compatible alias for {@see sizeBytesHardThreshold()}.
     */
    public static function sizeBytesThreshold(): int
    {
        return self::sizeBytesHardThreshold();
    }

    public static function fanOutHardThreshold(): int
    {
        return self::positiveIntegerConfig(
            'workflows.v2.history_budget.continue_as_new_fan_out_threshold',
            self::DEFAULT_FAN_OUT_HARD_THRESHOLD,
        );
    }

    public static function fanOutWarningThreshold(): int
    {
        return self::warningThreshold(
            'workflows.v2.history_budget.fan_out_warning_threshold',
            self::DEFAULT_FAN_OUT_WARNING_THRESHOLD,
            self::fanOutHardThreshold(),
        );
    }

    /**
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * }
     */
    private static function summarize(
        int $historyEventCount,
        int $historySizeBytes,
        int $historyFanOut,
    ): array {
        $eventHard = self::eventHardThreshold();
        $sizeHard = self::sizeBytesHardThreshold();
        $fanOutHard = self::fanOutHardThreshold();

        $continueAsNew = ($eventHard > 0 && $historyEventCount >= $eventHard)
            || ($sizeHard > 0 && $historySizeBytes >= $sizeHard)
            || ($fanOutHard > 0 && $historyFanOut >= $fanOutHard);

        $pressure = self::PRESSURE_OK;
        $dimensions = [];

        if ($continueAsNew) {
            $pressure = self::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED;

            if ($eventHard > 0 && $historyEventCount >= $eventHard) {
                $dimensions[] = self::DIMENSION_EVENT_COUNT;
            }
            if ($sizeHard > 0 && $historySizeBytes >= $sizeHard) {
                $dimensions[] = self::DIMENSION_SIZE_BYTES;
            }
            if ($fanOutHard > 0 && $historyFanOut >= $fanOutHard) {
                $dimensions[] = self::DIMENSION_FAN_OUT;
            }
        } else {
            $eventWarn = self::eventWarningThreshold();
            $sizeWarn = self::sizeBytesWarningThreshold();
            $fanOutWarn = self::fanOutWarningThreshold();

            if ($eventWarn > 0 && $historyEventCount >= $eventWarn) {
                $dimensions[] = self::DIMENSION_EVENT_COUNT;
            }
            if ($sizeWarn > 0 && $historySizeBytes >= $sizeWarn) {
                $dimensions[] = self::DIMENSION_SIZE_BYTES;
            }
            if ($fanOutWarn > 0 && $historyFanOut >= $fanOutWarn) {
                $dimensions[] = self::DIMENSION_FAN_OUT;
            }

            if ($dimensions !== []) {
                $pressure = self::PRESSURE_APPROACHING;
            }
        }

        return [
            'history_event_count' => $historyEventCount,
            'history_size_bytes' => $historySizeBytes,
            'history_fan_out' => $historyFanOut,
            'continue_as_new_recommended' => $continueAsNew,
            'pressure' => $pressure,
            'pressure_dimensions' => $dimensions,
        ];
    }

    /**
     * Maximum parallel-group breadth observed in this run's history.
     *
     * Counts each `parallel_group_size` payload value once per distinct
     * `parallel_group_id`, then returns the largest. Replay-stable because
     * the values come straight from frozen history payloads.
     */
    private static function maxParallelFanOut(WorkflowRun $run): int
    {
        $maxSize = 0;
        $seenGroups = [];

        foreach ($run->historyEvents as $event) {
            $payload = $event->payload ?? [];

            if (! is_array($payload)) {
                continue;
            }

            $groupId = $payload['parallel_group_id'] ?? null;
            $groupSize = $payload['parallel_group_size'] ?? null;

            if (! is_string($groupId) || $groupId === '') {
                continue;
            }
            if (! is_numeric($groupSize)) {
                continue;
            }
            if (isset($seenGroups[$groupId])) {
                continue;
            }

            $seenGroups[$groupId] = true;
            $size = (int) $groupSize;

            if ($size > $maxSize) {
                $maxSize = $size;
            }
        }

        return $maxSize;
    }

    private static function summaryIsComplete(WorkflowRun $run, WorkflowRunSummary $summary): bool
    {
        $eventCount = (int) $summary->history_event_count;
        $sizeBytes = (int) $summary->history_size_bytes;
        $fanOut = (int) $summary->history_fan_out;
        $lastHistorySequence = max(0, (int) ($run->last_history_sequence ?? 0));

        if (
            $eventCount < 0
            || $sizeBytes < 0
            || $fanOut < 0
            || $eventCount !== $lastHistorySequence
        ) {
            return false;
        }

        if ($eventCount === 0) {
            return $sizeBytes === 0 && $fanOut === 0;
        }

        if ($sizeBytes === 0) {
            return false;
        }

        // Zero is a complete, authoritative fan-out for histories without a
        // parallel group; negative fan-out was rejected above.
        return true;
    }

    /**
     * @return array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int
     * }
     */
    private static function aggregateCounters(WorkflowRun $run): array
    {
        $query = ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
            ->where('workflow_run_id', $run->id);
        $model = $query->getModel();
        $connection = $model->getConnection();
        $grammar = $connection->getQueryGrammar();
        $eventType = $grammar->wrap($model->qualifyColumn('event_type'));
        $payload = $grammar->wrap($model->qualifyColumn('payload'));
        $sequence = $grammar->wrap($model->qualifyColumn('sequence'));

        $driver = $connection->getDriverName();
        $isMariaDb = self::isMariaDbConnection($connection);

        [
            $sizeExpression,
            $groupIdExpression,
            $groupIdIsValid,
            $groupSizeIsNumeric,
            $fanOutExpression,
        ] = match ($driver) {
            'mysql' => $isMariaDb
                ? self::mariaDbAggregateExpressions($eventType, $payload)
                : self::mysqlAggregateExpressions($eventType, $payload),
            'mariadb' => self::mariaDbAggregateExpressions($eventType, $payload),
            'pgsql' => self::postgresAggregateExpressions($eventType, $payload),
            'sqlsrv' => self::sqlServerAggregateExpressions($eventType, $payload),
            default => self::sqliteAggregateExpressions($eventType, $payload),
        };

        $groupPartition = "CASE WHEN {$groupIdIsValid} AND {$groupSizeIsNumeric} "
            ."THEN {$groupIdExpression} ELSE NULL END";
        $perEvent = $query->toBase()->selectRaw(sprintf(
            '%s AS history_event_size_bytes, %s AS history_event_fan_out, '
            .'ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s) AS history_group_position',
            $sizeExpression,
            $fanOutExpression,
            $groupPartition,
            $sequence,
        ));

        /**
         * @var object{
         *     history_event_count: int|string,
         *     history_size_bytes: int|string,
         *     history_fan_out: int|string
         * }|null $aggregates
         */
        $aggregates = $connection->query()
            ->fromSub($perEvent, 'history_budget_events')
            ->selectRaw(
                'COUNT(*) AS history_event_count, '
                .'COALESCE(SUM(history_event_size_bytes), 0) AS history_size_bytes, '
                .'COALESCE(MAX(CASE WHEN history_group_position = 1 '
                .'THEN history_event_fan_out ELSE 0 END), 0) AS history_fan_out'
            )
            ->first();

        return [
            'history_event_count' => max(0, (int) ($aggregates->history_event_count ?? 0)),
            'history_size_bytes' => max(0, (int) ($aggregates->history_size_bytes ?? 0)),
            'history_fan_out' => max(0, (int) ($aggregates->history_fan_out ?? 0)),
        ];
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private static function mysqlAggregateExpressions(string $eventType, string $payload): array
    {
        $json = "COALESCE(CAST({$payload} AS CHAR CHARACTER SET utf8mb4), '[]')";
        $jsonWithoutStrings = "REGEXP_REPLACE({$json}, CONVERT(0x22285B5E225C5C5D7C5C5C2E292A22 USING utf8mb4), '\"\"')";
        $structuralSpaces = "((CHAR_LENGTH({$jsonWithoutStrings}) - CHAR_LENGTH(REPLACE({$jsonWithoutStrings}, ':', ''))) + (CHAR_LENGTH({$jsonWithoutStrings}) - CHAR_LENGTH(REPLACE({$jsonWithoutStrings}, ',', ''))))";
        $canonicalJson = "REPLACE({$json}, CONVERT(0x5C2F USING utf8mb4), '/')";
        $groupId = "JSON_UNQUOTE(JSON_EXTRACT({$payload}, '$.parallel_group_id'))";
        $groupIdIsValid = "JSON_TYPE(JSON_EXTRACT({$payload}, '$.parallel_group_id')) = 'STRING' AND COALESCE({$groupId}, '') <> ''";
        $groupSize = "JSON_UNQUOTE(JSON_EXTRACT({$payload}, '$.parallel_group_size'))";
        $groupSizeIsNumeric = "JSON_TYPE(JSON_EXTRACT({$payload}, '$.parallel_group_size')) IN ('INTEGER', 'DOUBLE', 'STRING') AND REGEXP_LIKE({$groupSize}, '^[[:space:]]*[+-]?(([0-9]+([.][0-9]*)?)|([.][0-9]+))([eE][+-]?[0-9]+)?[[:space:]]*$')";

        return [
            "OCTET_LENGTH({$eventType}) + OCTET_LENGTH({$canonicalJson}) - {$structuralSpaces}",
            $groupId,
            $groupIdIsValid,
            $groupSizeIsNumeric,
            "CASE WHEN {$groupIdIsValid} AND {$groupSizeIsNumeric} THEN CAST(CAST({$groupSize} AS DECIMAL(65, 20)) AS SIGNED) ELSE 0 END",
        ];
    }

    /**
     * MariaDB exposes JSON columns as verbatim LONGTEXT and reports its
     * Laravel driver as mysql on older framework versions. Compact the text
     * without applying MySQL binary-JSON separator accounting, then replace
     * semantic JSON escapes with placeholders of the byte width produced by
     * eventSizeBytes(). The escape-aware prefix avoids treating a literal
     * `\\uXXXX` string as an encoded Unicode character.
     *
     * @return array{string, string, string, string, string}
     */
    private static function mariaDbAggregateExpressions(string $eventType, string $payload): array
    {
        $canonicalJson = "JSON_COMPACT(COALESCE({$payload}, '[]'))";

        foreach ([
            [
                self::mariaDbJsonEscapePattern(
                    'u[d][89ab][0-9a-f]{2}\\\\u[d][c-f][0-9a-f]{2}',
                ),
                'xxxx',
            ],
            [
                self::mariaDbJsonEscapePattern(
                    'u(?:00[89a-f][0-9a-f]|0[1-7][0-9a-f]{2})',
                ),
                'xx',
            ],
            [
                self::mariaDbJsonEscapePattern(
                    'u(?!202[89])(?:0[89a-f][0-9a-f]{2}|[1-9a-c][0-9a-f]{3}'
                    .'|d[0-7][0-9a-f]{2}|[e-f][0-9a-f]{3})',
                ),
                'xxx',
            ],
            [self::mariaDbJsonEscapePattern('u(?:000[89acd]|0022|005c)'), 'xx'],
            [self::mariaDbJsonEscapePattern('u(?!0022|005c)00[2-7][0-9a-f]'), 'x'],
            [self::mariaDbJsonEscapePattern('/'), '/'],
        ] as [$pattern, $replacement]) {
            $canonicalJson = sprintf(
                "REGEXP_REPLACE(%s, CONVERT(0x%s USING utf8mb4), '%s')",
                $canonicalJson,
                bin2hex($pattern),
                $replacement,
            );
        }

        $groupId = "JSON_UNQUOTE(JSON_EXTRACT({$payload}, '$.parallel_group_id'))";
        $groupIdIsValid = "JSON_TYPE(JSON_EXTRACT({$payload}, '$.parallel_group_id')) = 'STRING' AND COALESCE({$groupId}, '') <> ''";
        $groupSize = "JSON_UNQUOTE(JSON_EXTRACT({$payload}, '$.parallel_group_size'))";
        $groupSizeIsNumeric = "JSON_TYPE(JSON_EXTRACT({$payload}, '$.parallel_group_size')) IN ('INTEGER', 'DOUBLE', 'STRING') AND {$groupSize} REGEXP '^[[:space:]]*[+-]?(([0-9]+([.][0-9]*)?)|([.][0-9]+))([eE][+-]?[0-9]+)?[[:space:]]*$'";

        return [
            "OCTET_LENGTH({$eventType}) + OCTET_LENGTH({$canonicalJson})",
            $groupId,
            $groupIdIsValid,
            $groupSizeIsNumeric,
            "CASE WHEN {$groupIdIsValid} AND {$groupSizeIsNumeric} THEN CAST(CAST({$groupSize} AS DECIMAL(65, 20)) AS SIGNED) ELSE 0 END",
        ];
    }

    private static function mariaDbJsonEscapePattern(string $suffix): string
    {
        return '(?i)(?<!\\\\)(?:\\\\\\\\)*\K\\\\'.$suffix;
    }

    private static function isMariaDbConnection(Connection $connection): bool
    {
        if ($connection->getDriverName() === 'mariadb') {
            return true;
        }

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        $pdo = $connection->getPdo();

        if (! $pdo instanceof \PDO) {
            return false;
        }

        $serverVersion = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return is_string($serverVersion) && stripos($serverVersion, 'mariadb') !== false;
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private static function postgresAggregateExpressions(string $eventType, string $payload): array
    {
        $json = "COALESCE({$payload}::jsonb::text, '[]')";
        $jsonWithoutStrings = "regexp_replace({$json}, convert_from(decode('22285B5E225C5C5D7C5C5C2E292A22', 'hex'), 'UTF8'), '\"\"', 'g')";
        $structuralSpaces = "((CHAR_LENGTH({$jsonWithoutStrings}) - CHAR_LENGTH(REPLACE({$jsonWithoutStrings}, ':', ''))) + (CHAR_LENGTH({$jsonWithoutStrings}) - CHAR_LENGTH(REPLACE({$jsonWithoutStrings}, ',', ''))))";
        $canonicalJson = "REPLACE({$json}, E'\\\\/', '/')";
        $groupId = "{$payload}->>'parallel_group_id'";
        $groupIdIsValid = "jsonb_typeof({$payload}::jsonb->'parallel_group_id') = 'string' AND COALESCE({$groupId}, '') <> ''";
        $groupSize = "{$payload}->>'parallel_group_size'";
        $groupSizeIsNumeric = "jsonb_typeof({$payload}::jsonb->'parallel_group_size') IN ('number', 'string') AND COALESCE({$groupSize}, '') ~ '^[[:space:]]*[+-]?(([0-9]+([.][0-9]*)?)|([.][0-9]+))([eE][+-]?[0-9]+)?[[:space:]]*$'";

        return [
            "OCTET_LENGTH({$eventType}::text) + OCTET_LENGTH({$canonicalJson}) - {$structuralSpaces}",
            $groupId,
            $groupIdIsValid,
            $groupSizeIsNumeric,
            "CASE WHEN {$groupIdIsValid} AND {$groupSizeIsNumeric} THEN TRUNC(({$groupSize})::numeric)::bigint ELSE 0 END",
        ];
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private static function sqlServerAggregateExpressions(string $eventType, string $payload): array
    {
        $json = "COALESCE({$payload}, '[]')";
        $groupId = "JSON_VALUE({$payload}, '$.parallel_group_id')";
        $groupIdIsValid = "COALESCE({$groupId}, '') <> ''";
        $groupSize = "JSON_VALUE({$payload}, '$.parallel_group_size')";
        $groupSizeIsNumeric = "TRY_CONVERT(float, {$groupSize}) IS NOT NULL";

        return [
            "DATALENGTH(CONVERT(varchar(max), {$eventType})) + DATALENGTH(REPLACE(CONVERT(varchar(max), {$json}), CHAR(92) + '/', '/'))",
            $groupId,
            $groupIdIsValid,
            $groupSizeIsNumeric,
            "CASE WHEN {$groupIdIsValid} AND {$groupSizeIsNumeric} THEN COALESCE(TRY_CONVERT(bigint, {$groupSize}), 0) ELSE 0 END",
        ];
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private static function sqliteAggregateExpressions(string $eventType, string $payload): array
    {
        $canonicalJsonSize = <<<SQL
            (SELECT COALESCE(SUM(
                CASE
                    WHEN history_json.type IN ('object', 'array') THEN 2
                    WHEN history_json.type = 'text' THEN LENGTH(CAST(json_quote(history_json.atom) AS BLOB))
                    WHEN history_json.type = 'null' THEN 4
                    WHEN history_json.type = 'true' THEN 4
                    WHEN history_json.type = 'false' THEN 5
                    ELSE LENGTH(CAST(history_json.atom AS TEXT))
                END
                + CASE WHEN history_json.parent IS NULL THEN 0 ELSE 1 END
                + CASE WHEN typeof(history_json.key) = 'text'
                    THEN LENGTH(CAST(json_quote(history_json.key) AS BLOB)) + 1 ELSE 0 END
            ), 2) - COUNT(DISTINCT history_json.parent)
            FROM json_tree(COALESCE({$payload}, '[]')) AS history_json)
            SQL;
        $groupId = "json_extract({$payload}, '$.parallel_group_id')";
        $groupIdIsValid = "json_valid({$payload}) AND json_type({$payload}, '$.parallel_group_id') = 'text' AND COALESCE({$groupId}, '') <> ''";
        $groupSize = "json_extract({$payload}, '$.parallel_group_size')";
        $numericWhitespace = "CHAR(9) || CHAR(10) || CHAR(11) || CHAR(12) || CHAR(13) || CHAR(32)";
        $groupSizeText = "TRIM(CAST({$groupSize} AS TEXT), {$numericWhitespace})";
        $normalizedGroupSizeText = "REPLACE({$groupSizeText}, 'E', 'e')";
        $exponentPosition = "INSTR({$normalizedGroupSizeText}, 'e')";
        $mantissa = "CASE WHEN {$exponentPosition} > 0 "
            ."THEN SUBSTR({$normalizedGroupSizeText}, 1, {$exponentPosition} - 1) "
            ."ELSE {$normalizedGroupSizeText} END";
        $exponent = "CASE WHEN {$exponentPosition} > 0 "
            ."THEN SUBSTR({$normalizedGroupSizeText}, {$exponentPosition} + 1) ELSE '' END";
        $unsignedMantissa = "CASE WHEN SUBSTR({$mantissa}, 1, 1) IN ('+', '-') "
            ."THEN SUBSTR({$mantissa}, 2) ELSE {$mantissa} END";
        $unsignedExponent = "CASE WHEN SUBSTR({$exponent}, 1, 1) IN ('+', '-') "
            ."THEN SUBSTR({$exponent}, 2) ELSE {$exponent} END";
        $numericString = "{$groupSizeText} <> '' "
            ."AND (LENGTH({$normalizedGroupSizeText}) - "
            ."LENGTH(REPLACE({$normalizedGroupSizeText}, 'e', ''))) <= 1 "
            ."AND {$unsignedMantissa} <> '' "
            ."AND {$unsignedMantissa} NOT GLOB '*[^0-9.]*' "
            ."AND {$unsignedMantissa} GLOB '*[0-9]*' "
            ."AND (LENGTH({$unsignedMantissa}) - "
            ."LENGTH(REPLACE({$unsignedMantissa}, '.', ''))) <= 1 "
            ."AND ({$exponentPosition} = 0 OR ("
            ."{$unsignedExponent} <> '' "
            ."AND {$unsignedExponent} NOT GLOB '*[^0-9]*'))";
        $groupSizeIsNumeric = "(json_type({$payload}, '$.parallel_group_size') IN ('integer', 'real') "
            ."OR (json_type({$payload}, '$.parallel_group_size') = 'text' AND {$numericString}))";

        return [
            "LENGTH(CAST({$eventType} AS BLOB)) + {$canonicalJsonSize}",
            $groupId,
            $groupIdIsValid,
            $groupSizeIsNumeric,
            "CASE WHEN {$groupIdIsValid} AND {$groupSizeIsNumeric} THEN CAST({$groupSize} AS INTEGER) ELSE 0 END",
        ];
    }

    private static function eventSizeBytes(WorkflowHistoryEvent $event): int
    {
        $eventType = $event->event_type instanceof \BackedEnum
            ? (string) $event->event_type->value
            : (string) $event->event_type;

        try {
            $payload = json_encode(
                $event->payload ?? [],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $payload = serialize($event->payload ?? []);
        }

        return strlen($eventType) + strlen($payload);
    }

    private static function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return $default;
    }

    /**
     * Resolve a warning threshold, clamping it so it never exceeds the hard
     * threshold (a warning above the action boundary cannot fire before
     * continue-as-new is already recommended) and disabling it entirely when
     * the hard threshold is disabled (hard=0 means the dimension is off).
     */
    private static function warningThreshold(string $key, int $default, int $hardThreshold): int
    {
        if ($hardThreshold === 0) {
            return 0;
        }

        $configured = config($key);

        if (is_numeric($configured)) {
            $value = max(0, (int) $configured);
        } else {
            $value = $default;
        }

        if ($value > 0 && $value > $hardThreshold) {
            return $hardThreshold;
        }

        return $value;
    }
}
