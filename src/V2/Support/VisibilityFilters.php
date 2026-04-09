<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class VisibilityFilters
{
    public const VERSION = 1;

    private const EXACT_FIELDS = [
        'workflow_type',
        'business_key',
        'compatibility',
        'queue',
        'connection',
    ];

    private const LABEL_KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    /**
     * @return array<int, string>
     */
    public static function exactFields(): array
    {
        return self::EXACT_FIELDS;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public static function normalize(array $filters): array
    {
        $normalized = [];

        foreach (self::EXACT_FIELDS as $field) {
            $value = self::stringValue($filters[$field] ?? null);

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

        foreach (self::EXACT_FIELDS as $field) {
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

            foreach (self::EXACT_FIELDS as $field) {
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

        foreach (self::EXACT_FIELDS as $field) {
            if (array_key_exists($field, $normalized)) {
                $query->where($field, $normalized[$field]);
            }
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
}
