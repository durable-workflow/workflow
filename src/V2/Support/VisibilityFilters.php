<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class VisibilityFilters
{
    public const VERSION = 1;

    private const FIELD_LABELS = [
        'instance_id' => 'Instance ID',
        'run_id' => 'Run ID',
        'workflow_type' => 'Workflow Type',
        'business_key' => 'Business Key',
        'compatibility' => 'Compatibility',
        'queue' => 'Queue',
        'connection' => 'Connection',
        'status' => 'Status',
        'status_bucket' => 'Status Bucket',
        'closed_reason' => 'Closed Reason',
        'wait_kind' => 'Wait Kind',
        'liveness_state' => 'Liveness State',
        'archived' => 'Archived',
        'is_terminal' => 'Terminal',
    ];

    private const STRING_FIELDS = [
        'instance_id',
        'run_id',
        'workflow_type',
        'business_key',
        'compatibility',
        'queue',
        'connection',
        'status',
        'status_bucket',
        'closed_reason',
        'wait_kind',
        'liveness_state',
    ];

    private const BOOLEAN_FIELDS = [
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
        return [
            ...self::STRING_FIELDS,
            ...self::BOOLEAN_FIELDS,
        ];
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
            'fields' => $fields,
            'labels' => [
                'label' => 'Labels',
                'type' => 'map<string,string>',
                'input' => 'key_value_textarea',
                'operator' => 'exact',
                'query_parameters' => ['label[key]', 'labels[key]'],
                'key_pattern' => self::LABEL_KEY_REGEX,
                'key_value_separator' => '=',
                'placeholder' => "tenant=acme\nregion=us-east",
                'help' => 'One exact-match label per line in key=value format.',
            ],
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
                $merged['labels'] = [
                    ...($merged['labels'] ?? []),
                    ...$normalized['labels'],
                ];
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
     * @param mixed $labels
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
        $definition = [
            'label' => self::FIELD_LABELS[$field] ?? $field,
            'type' => $type,
            'input' => $type === 'boolean' ? 'boolean_select' : 'text',
            'operator' => 'exact',
            'order' => $order,
            'query_parameter' => $field,
        ];

        if ($type === 'boolean') {
            $definition['options'] = [
                ['label' => 'Yes', 'value' => true],
                ['label' => 'No', 'value' => false],
            ];
        }

        return $definition;
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
        return [
            'completed',
            'failed',
            'cancelled',
            'terminated',
        ];
    }
}
