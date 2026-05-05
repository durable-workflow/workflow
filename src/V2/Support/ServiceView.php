<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Models\WorkflowService;

/**
 * Typed list/detail projection of a {@see WorkflowService}.
 */
final class ServiceView
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(WorkflowService|Model $service): array
    {
        return [
            'id' => $service->id,
            'workflow_service_endpoint_id' => $service->workflow_service_endpoint_id,
            'namespace' => $service->namespace,
            'service_name' => $service->service_name,
            'description' => $service->description,
            'metadata' => is_array($service->metadata) ? $service->metadata : [],
            'created_at' => self::iso($service->created_at),
            'updated_at' => self::iso($service->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(WorkflowService $service, ?string $observerNamespace = null): array
    {
        $base = self::listItem($service);

        $endpoint = $service->endpoint()
            ->when(
                $observerNamespace !== null,
                static fn ($query) => $query->where('namespace', $observerNamespace),
            )
            ->first();

        $base['endpoint'] = $endpoint instanceof Model
            ? ServiceEndpointView::listItem($endpoint)
            : null;

        $base['operations'] = $service->operations()
            ->when(
                $observerNamespace !== null,
                static fn ($query) => $query->where('namespace', $observerNamespace),
            )
            ->get()
            ->map(static fn (Model $op): array => ServiceOperationView::listItem($op))
            ->values()
            ->all();

        return $base;
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('c') : null;
    }
}
