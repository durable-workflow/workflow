<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\StatusBucket;

final class VisibilityFilters
{
    public const VERSION = 3;

    /**
     * @return list<int>
     */
    public static function supportedVersions(): array
    {
        return [1, 2, self::VERSION];
    }

    private const FIELD_LABELS = [
        'instance_id' => 'Instance ID',
        'run_id' => 'Run ID',
        'is_current_run' => 'Current Run',
        'workflow_type' => 'Workflow Type',
        'business_key' => 'Business Key',
        'compatibility' => 'Compatibility',
        'declared_entry_mode' => 'Entry Mode',
        'declared_contract_source' => 'Command Contract Source',
        'queue' => 'Queue',
        'connection' => 'Connection',
        'status' => 'Status',
        'status_bucket' => 'Status Bucket',
        'closed_reason' => 'Closed Reason',
        'wait_kind' => 'Wait Kind',
        'liveness_state' => 'Liveness State',
        'repair_blocked_reason' => 'Repair Blocked Reason',
        'repair_attention' => 'Repair Attention',
        'task_problem' => 'Task Problem',
        'declared_contract_backfill_needed' => 'Command Contract Backfill Needed',
        'declared_contract_backfill_available' => 'Command Contract Backfill Available',
        'continue_as_new_recommended' => 'Continue As New Recommended',
        'archived' => 'Archived',
        'is_terminal' => 'Terminal',
    ];

    private const STRING_FIELDS = [
        'instance_id',
        'run_id',
        'workflow_type',
        'business_key',
        'compatibility',
        'declared_entry_mode',
        'declared_contract_source',
        'queue',
        'connection',
        'status',
        'status_bucket',
        'closed_reason',
        'wait_kind',
        'liveness_state',
        'repair_blocked_reason',
    ];

    private const BOOLEAN_FIELDS = [
        'is_current_run',
        'repair_attention',
        'task_problem',
        'declared_contract_backfill_needed',
        'declared_contract_backfill_available',
        'continue_as_new_recommended',
        'archived',
        'is_terminal',
    ];

    private const LABEL_KEY_REGEX = '^[A-Za-z0-9_.:-]{1,64}$';

    private const LABEL_KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * @return array<int, string>
     */
    public static function exactFields(): array
    {
        return [...self::STRING_FIELDS, ...self::BOOLEAN_FIELDS];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(): array
    {
        $fields = [];
        $order = 0;

        foreach (self::STRING_FIELDS as $field) {
            $fields[$field] = self::fieldDefinition($field, 'string', $order++);
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            $fields[$field] = self::fieldDefinition($field, 'boolean', $order++);
        }

        return [
            'version' => self::VERSION,
            'supported_versions' => self::supportedVersions(),
            'fields' => $fields,
            'labels' => [
                'label' => 'Labels',
                'type' => 'map<string,string>',
                'input' => 'key_value_textarea',
                'operator' => 'exact',
                'filterable' => true,
                'saved_view_compatible' => true,
                'query_parameters' => ['label[key]', 'labels[key]'],
                'key_pattern' => self::LABEL_KEY_REGEX,
                'key_value_separator' => '=',
                'placeholder' => "tenant=acme\nregion=us-east",
                'help' => 'One exact-match label per line in key=value format. Labels are indexed operator metadata and saved-view compatible.',
            ],
            'indexed_metadata' => self::indexedMetadataDefinition(),
            'detail_metadata' => self::detailMetadataDefinition(),
        ];
    }

    /**
     * @return array{
     *     version: int|null,
     *     current_version: int,
     *     supported_versions: list<int>,
     *     supported: bool,
     *     status: string,
     *     message: string|null
     * }
     */
    public static function versionMetadata(mixed $version): array
    {
        $normalizedVersion = self::normalizeVersion($version);
        $supportedVersions = self::supportedVersions();
        $supported = $normalizedVersion !== null && in_array($normalizedVersion, $supportedVersions, true);

        return [
            'version' => $normalizedVersion,
            'current_version' => self::VERSION,
            'supported_versions' => $supportedVersions,
            'supported' => $supported,
            'status' => $supported ? 'supported' : 'unsupported',
            'message' => $supported
                ? null
                : self::unsupportedVersionMessage($normalizedVersion, $supportedVersions),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalize(array $filters): array
    {
        $normalized = [];

        foreach (self::STRING_FIELDS as $field) {
            $value = self::stringValue($filters[$field] ?? null);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            $value = self::booleanValue($filters[$field] ?? null);

            if ($value !== null) {
                $normalized[$field] = $value;
            }
        }

        $labels = self::normalizeLabels($filters['labels'] ?? $filters['label'] ?? []);

        if ($labels !== []) {
            $normalized['labels'] = $labels;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRequest(Request $request): array
    {
        $filters = [];

        foreach (self::exactFields() as $field) {
            $filters[$field] = $request->query($field);
        }

        $filters['labels'] = $request->query('label', $request->query('labels', []));

        return self::normalize($filters);
    }

    /**
     * @param array<string, mixed> ...$filters
     * @return array<string, mixed>
     */
    public static function merge(array ...$filters): array
    {
        $merged = [];

        foreach ($filters as $filter) {
            $normalized = self::normalize($filter);

            foreach (self::exactFields() as $field) {
                if (array_key_exists($field, $normalized)) {
                    $merged[$field] = $normalized[$field];
                }
            }

            if (isset($normalized['labels']) && is_array($normalized['labels'])) {
                $merged['labels'] = [...($merged['labels'] ?? []), ...$normalized['labels']];
            }
        }

        if (isset($merged['labels'])) {
            ksort($merged['labels']);
        }

        return self::normalize($merged);
    }

    /**
     * @param Builder<\Illuminate\Database\Eloquent\Model> $query
     * @param array<string, mixed> $filters
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $normalized = self::normalize($filters);

        foreach (self::STRING_FIELDS as $field) {
            if (array_key_exists($field, $normalized)) {
                $query->where(self::columnForField($field), $normalized[$field]);
            }
        }

        foreach (self::BOOLEAN_FIELDS as $field) {
            if (in_array($field, ['archived', 'is_terminal'], true) || ! array_key_exists($field, $normalized)) {
                continue;
            }

            $query->where(self::columnForField($field), $normalized[$field]);
        }

        if (array_key_exists('archived', $normalized)) {
            $normalized['archived']
                ? $query->whereNotNull('archived_at')
                : $query->whereNull('archived_at');
        }

        if (array_key_exists('is_terminal', $normalized)) {
            $normalized['is_terminal']
                ? $query->whereIn('status', self::terminalStatuses())
                : $query->whereNotIn('status', self::terminalStatuses());
        }

        foreach ($normalized['labels'] ?? [] as $key => $value) {
            $query->where("visibility_labels->{$key}", $value);
        }

        return $query;
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 191) {
            return null;
        }

        return $value;
    }

    private static function normalizeVersion(mixed $version): ?int
    {
        if (is_int($version)) {
            return $version;
        }

        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);

        if ($version === '' || ! ctype_digit($version)) {
            return null;
        }

        return (int) $version;
    }

    /**
     * @param list<int> $supportedVersions
     */
    private static function unsupportedVersionMessage(?int $version, array $supportedVersions): string
    {
        $supportedVersionList = implode(', ', $supportedVersions);

        if ($version === null) {
            return sprintf(
                'This saved view does not declare a supported visibility filter version. This Waterline build supports version %s.',
                $supportedVersionList,
            );
        }

        return sprintf(
            'This saved view uses visibility filter version %d, but this Waterline build supports version %s.',
            $version,
            $supportedVersionList,
        );
    }

    private static function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeLabels(mixed $labels): array
    {
        if (! is_array($labels)) {
            return [];
        }

        $normalized = [];

        foreach ($labels as $key => $value) {
            if (! is_string($key) || preg_match(self::LABEL_KEY_PATTERN, $key) !== 1) {
                continue;
            }

            $stringValue = self::stringValue($value);

            if ($stringValue !== null) {
                $normalized[$key] = $stringValue;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldDefinition(string $field, string $type, int $order): array
    {
        $options = self::optionsForField($field, $type);

        $definition = [
            'label' => self::FIELD_LABELS[$field] ?? $field,
            'type' => $type,
            'input' => $type === 'boolean'
                ? 'boolean_select'
                : ($options === [] ? 'text' : 'select'),
            'operator' => 'exact',
            'filterable' => true,
            'saved_view_compatible' => true,
            'order' => $order,
            'query_parameter' => $field,
        ];

        $help = self::helpForField($field);

        if ($help !== null) {
            $definition['help'] = $help;
        }

        if ($options !== []) {
            $definition['options'] = $options;
        }

        return $definition;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function indexedMetadataDefinition(): array
    {
        return [
            'business_key' => [
                'label' => self::FIELD_LABELS['business_key'],
                'filter_field' => 'business_key',
                'query_parameter' => 'business_key',
                'indexed' => true,
                'filterable' => true,
                'saved_view_compatible' => true,
                'returned_in' => ['list', 'detail', 'history_export'],
                'description' => 'Exact-match searchable operator metadata copied onto the run-summary projection.',
            ],
            'labels' => [
                'label' => 'Labels',
                'filter_field' => 'labels',
                'query_parameters' => ['label[key]', 'labels[key]'],
                'indexed' => true,
                'filterable' => true,
                'saved_view_compatible' => true,
                'returned_in' => ['list', 'detail', 'history_export'],
                'description' => 'Exact-match searchable key/value operator metadata copied onto the run-summary projection.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function detailMetadataDefinition(): array
    {
        return [
            'memo' => [
                'label' => 'Memo',
                'indexed' => false,
                'filterable' => false,
                'saved_view_compatible' => false,
                'returned_in' => ['detail', 'history_export'],
                'description' => 'Returned-only per-run context copied onto the instance, run, typed start history, selected-run detail, and history export.',
            ],
        ];
    }

    private static function helpForField(string $field): ?string
    {
        return match ($field) {
            'business_key' => 'Exact-match indexed operator metadata copied onto the run summary and saved-view contract.',
            default => null,
        };
    }

    /**
     * @return array<int, array{label: string, value: string|bool}>
     */
    private static function optionsForField(string $field, string $type): array
    {
        if ($type === 'boolean') {
            return [
                [
                    'label' => 'Yes',
                    'value' => true,
                ],
                [
                    'label' => 'No',
                    'value' => false,
                ],
            ];
        }

        return match ($field) {
            'status' => array_map(
                static fn (RunStatus $status): array => [
                    'label' => ucfirst($status->value),
                    'value' => $status->value,
                ],
                RunStatus::cases(),
            ),
            'status_bucket' => array_map(
                static fn (StatusBucket $bucket): array => [
                    'label' => ucfirst($bucket->value),
                    'value' => $bucket->value,
                ],
                StatusBucket::cases(),
            ),
            'closed_reason' => [
                [
                    'label' => 'Completed',
                    'value' => 'completed',
                ],
                [
                    'label' => 'Failed',
                    'value' => 'failed',
                ],
                [
                    'label' => 'Cancelled',
                    'value' => 'cancelled',
                ],
                [
                    'label' => 'Terminated',
                    'value' => 'terminated',
                ],
                [
                    'label' => 'Continued',
                    'value' => 'continued',
                ],
            ],
            'wait_kind' => [
                [
                    'label' => 'Activity',
                    'value' => 'activity',
                ],
                [
                    'label' => 'Update',
                    'value' => 'update',
                ],
                [
                    'label' => 'Signal',
                    'value' => 'signal',
                ],
                [
                    'label' => 'Timer',
                    'value' => 'timer',
                ],
                [
                    'label' => 'Condition',
                    'value' => 'condition',
                ],
                [
                    'label' => 'Workflow Task',
                    'value' => 'workflow-task',
                ],
                [
                    'label' => 'Child',
                    'value' => 'child',
                ],
            ],
            'repair_blocked_reason' => [
                ...RepairBlockedReason::filterOptions(),
            ],
            'declared_entry_mode' => [
                [
                    'label' => 'Canonical',
                    'value' => 'canonical',
                ],
                [
                    'label' => 'Compatibility',
                    'value' => 'compatibility',
                ],
            ],
            'declared_contract_source' => [
                [
                    'label' => 'Durable History',
                    'value' => RunCommandContract::SOURCE_DURABLE_HISTORY,
                ],
                [
                    'label' => 'Live Definition',
                    'value' => RunCommandContract::SOURCE_LIVE_DEFINITION,
                ],
                [
                    'label' => 'Unavailable',
                    'value' => RunCommandContract::SOURCE_UNAVAILABLE,
                ],
            ],
            default => [],
        };
    }

    private static function columnForField(string $field): string
    {
        return match ($field) {
            'instance_id' => 'workflow_instance_id',
            'run_id' => 'id',
            default => $field,
        };
    }

    /**
     * @return array<int, string>
     */
    private static function terminalStatuses(): array
    {
        return ['completed', 'failed', 'cancelled', 'terminated'];
    }
}
