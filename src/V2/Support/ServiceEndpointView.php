<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Models\WorkflowServiceEndpoint;

/**
 * Typed list/detail projection of a {@see WorkflowServiceEndpoint}.
 *
 * Used by Waterline's namespace-scoped service catalog surface and by any
 * v2-aware operator integration that needs a stable shape for endpoints.
 */
final class ServiceEndpointView
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(WorkflowServiceEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'namespace' => $endpoint->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'description' => $endpoint->description,
            'metadata' => self::metadata($endpoint),
            'created_at' => self::iso($endpoint->created_at),
            'updated_at' => self::iso($endpoint->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(WorkflowServiceEndpoint $endpoint, ?string $observerNamespace = null): array
    {
        $base = self::listItem($endpoint);

        $base['services'] = $endpoint->services()
            ->when(
                $observerNamespace !== null,
                static fn ($query) => $query->where('namespace', $observerNamespace),
            )
            ->get()
            ->map(static fn (Model $service): array => ServiceView::listItem($service))
            ->values()
            ->all();

        $base['operations'] = $endpoint->operations()
            ->when(
                $observerNamespace !== null,
                static fn ($query) => $query->where('namespace', $observerNamespace),
            )
            ->get()
            ->map(static fn (Model $operation): array => ServiceOperationView::listItem($operation))
            ->values()
            ->all();

        return $base;
    }

    private static function metadata(WorkflowServiceEndpoint $endpoint): array
    {
        return is_array($endpoint->metadata) ? $endpoint->metadata : [];
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('c') : null;
    }
}
