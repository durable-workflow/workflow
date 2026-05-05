<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Model;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Typed list/detail projection of a {@see WorkflowServiceOperation}.
 */
final class ServiceOperationView
{
    /**
     * @return array<string, mixed>
     */
    public static function listItem(WorkflowServiceOperation|Model $operation): array
    {
        return [
            'id' => $operation->id,
            'workflow_service_endpoint_id' => $operation->workflow_service_endpoint_id,
            'workflow_service_id' => $operation->workflow_service_id,
            'namespace' => $operation->namespace,
            'operation_name' => $operation->operation_name,
            'description' => $operation->description,
            'operation_mode' => $operation->operation_mode,
            'handler_binding_kind' => $operation->handler_binding_kind,
            'handler_target_reference' => $operation->handler_target_reference,
            'handler_binding' => self::array($operation->handler_binding),
            'deadline_policy' => self::array($operation->deadline_policy),
            'idempotency_policy' => self::array($operation->idempotency_policy),
            'cancellation_policy' => self::array($operation->cancellation_policy),
            'retry_policy' => self::array($operation->retry_policy),
            'boundary_policy' => self::array($operation->boundary_policy),
            'metadata' => self::array($operation->metadata),
            'created_at' => self::iso($operation->created_at),
            'updated_at' => self::iso($operation->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(WorkflowServiceOperation $operation, ?string $observerNamespace = null): array
    {
        $base = self::listItem($operation);

        $endpoint = $operation->endpoint()
            ->when(
                $observerNamespace !== null,
                static fn ($query) => $query->where('namespace', $observerNamespace),
            )
            ->first();

        $base['endpoint'] = $endpoint instanceof Model
            ? ServiceEndpointView::listItem($endpoint)
            : null;

        $service = $operation->service()
            ->when(
                $observerNamespace !== null,
                static fn ($query) => $query->where('namespace', $observerNamespace),
            )
            ->first();

        $base['service'] = $service instanceof Model
            ? ServiceView::listItem($service)
            : null;

        return $base;
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('c') : null;
    }
}
