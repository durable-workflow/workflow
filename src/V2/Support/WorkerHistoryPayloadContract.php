<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Current worker-history response contract shared by bridge implementations.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 */
final class WorkerHistoryPayloadContract
{
    public const SCHEMA = 'durable-workflow.v2.worker-history-payload.contract';

    public const VERSION = 1;

    /** @var list<string> */
    public const BUDGET_FIELDS = [
        'total_history_events',
        'history_size_bytes',
        'history_fan_out',
        'continue_as_new_recommended',
        'history_budget_pressure',
        'history_budget_pressure_dimensions',
    ];

    /** @var list<string> */
    public const FULL_RESPONSE_REQUIRED_FIELDS = [
        'task_id',
        'workflow_run_id',
        'workflow_instance_id',
        'namespace',
        'workflow_type',
        'workflow_class',
        'payload_codec',
        'arguments',
        'arguments_envelope',
        'run_status',
        'sticky_worker_id',
        'sticky_until',
        'sticky_replay_mode',
        'last_history_sequence',
        'total_history_events',
        'history_size_bytes',
        'history_fan_out',
        'continue_as_new_recommended',
        'history_budget_pressure',
        'history_budget_pressure_dimensions',
        'history_events',
    ];

    /** @var list<string> */
    public const PAGINATED_RESPONSE_REQUIRED_FIELDS = [
        'task_id',
        'workflow_run_id',
        'workflow_instance_id',
        'namespace',
        'workflow_type',
        'workflow_class',
        'payload_codec',
        'arguments',
        'arguments_envelope',
        'run_status',
        'last_history_sequence',
        'sticky_worker_id',
        'sticky_until',
        'sticky_replay_mode',
        'total_history_events',
        'history_size_bytes',
        'history_fan_out',
        'continue_as_new_recommended',
        'history_budget_pressure',
        'history_budget_pressure_dimensions',
        'after_sequence',
        'page_size',
        'has_more',
        'next_after_sequence',
        'history_events',
    ];

    /**
     * Project the complete worker-facing budget from one canonical result.
     *
     * @param array{
     *     history_event_count: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     pressure: string,
     *     pressure_dimensions: list<string>
     * } $budget
     * @return array{
     *     total_history_events: int,
     *     history_size_bytes: int,
     *     history_fan_out: int,
     *     continue_as_new_recommended: bool,
     *     history_budget_pressure: string,
     *     history_budget_pressure_dimensions: list<string>
     * }
     */
    public static function fromBudget(array $budget): array
    {
        return [
            'total_history_events' => $budget['history_event_count'],
            'history_size_bytes' => $budget['history_size_bytes'],
            'history_fan_out' => $budget['history_fan_out'],
            'continue_as_new_recommended' => $budget['continue_as_new_recommended'],
            'history_budget_pressure' => $budget['pressure'],
            'history_budget_pressure_dimensions' => $budget['pressure_dimensions'],
        ];
    }

    /**
     * Machine-readable response schema included in worker capabilities.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'full_response_required_fields' => self::FULL_RESPONSE_REQUIRED_FIELDS,
            'paginated_response_required_fields' => self::PAGINATED_RESPONSE_REQUIRED_FIELDS,
            'fields' => self::BUDGET_FIELDS,
            'field_types' => [
                'total_history_events' => 'int',
                'history_size_bytes' => 'int',
                'history_fan_out' => 'int',
                'continue_as_new_recommended' => 'bool',
                'history_budget_pressure' => 'string',
                'history_budget_pressure_dimensions' => 'list<string>',
            ],
            'pressure_values' => [
                HistoryBudget::PRESSURE_OK,
                HistoryBudget::PRESSURE_APPROACHING,
                HistoryBudget::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED,
            ],
            'pressure_dimension_values' => [
                HistoryBudget::DIMENSION_EVENT_COUNT,
                HistoryBudget::DIMENSION_SIZE_BYTES,
                HistoryBudget::DIMENSION_FAN_OUT,
            ],
        ];
    }
}
