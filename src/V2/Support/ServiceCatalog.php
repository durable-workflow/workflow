<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

/**
 * Namespace-scoped finders and listings for the v2 service catalog.
 *
 * Mirrors the namespace-scoping pattern used by ScheduleManager and
 * SelectedRunLocator: when a namespace is configured (e.g. via
 * `waterline.namespace`), out-of-scope catalog objects resolve to null instead
 * of leaking across namespace boundaries. Service-call scopes only refine rows
 * that already belong to the configured durable namespace.
 */
final class ServiceCatalog
{
    public const SCOPE_RELEVANT = 'relevant';

    public const SCOPE_OWNED = 'owned';

    public const SCOPE_CALLER = 'caller';

    public const SCOPE_TARGET = 'target';

    public const SCOPES = [self::SCOPE_RELEVANT, self::SCOPE_OWNED, self::SCOPE_CALLER, self::SCOPE_TARGET];

    /**
     * @return Builder<WorkflowServiceEndpoint>
     */
    public static function endpointsQuery(?string $namespace = null): Builder
    {
        $model = ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class);

        $query = $model::query()
            ->orderBy('endpoint_name')
            ->orderBy('id');

        return self::applyNamespace($query, $namespace);
    }

    /**
     * @return Builder<WorkflowService>
     */
    public static function servicesQuery(?string $namespace = null): Builder
    {
        $model = ConfiguredV2Models::resolve('service_model', WorkflowService::class);

        $query = $model::query()
            ->orderBy('service_name')
            ->orderBy('id');

        return self::applyNamespace($query, $namespace);
    }

    /**
     * @return Builder<WorkflowServiceOperation>
     */
    public static function operationsQuery(?string $namespace = null): Builder
    {
        $model = ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class);

        $query = $model::query()
            ->orderBy('operation_name')
            ->orderBy('id');

        return self::applyNamespace($query, $namespace);
    }

    /**
     * Build a service-call query, optionally filtered to one of:
     *
     *   - SCOPE_RELEVANT (default) - calls whose durable `namespace` matches.
     *   - SCOPE_OWNED             - alias of the durable namespace view.
     *   - SCOPE_CALLER            - namespace-owned calls initiated from the namespace.
     *   - SCOPE_TARGET            - namespace-owned calls targeting the namespace.
     *
     * Status is the durable {@see \Workflow\V2\Enums\ServiceCallStatus}
     * value; a null status returns calls in any state.
     *
     * @return Builder<WorkflowServiceCall>
     */
    public static function serviceCallsQuery(
        ?string $namespace = null,
        string $scope = self::SCOPE_RELEVANT,
        ?string $status = null,
        ?string $outcome = null,
    ): Builder {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);

        $query = $model::query()
            ->orderByDesc('accepted_at')
            ->orderByDesc('created_at')
            ->orderBy('id');

        if ($namespace !== null) {
            $query->where('namespace', $namespace);

            $column = match ($scope) {
                self::SCOPE_CALLER => 'caller_namespace',
                self::SCOPE_TARGET => 'target_namespace',
                default => null,
            };

            if ($column !== null) {
                $query->where($column, $namespace);
            }
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($outcome !== null && $outcome !== '') {
            $query->where('outcome', $outcome);
        }

        return $query;
    }

    public static function findEndpoint(string $id, ?string $namespace = null): ?WorkflowServiceEndpoint
    {
        $model = ConfiguredV2Models::resolve('service_endpoint_model', WorkflowServiceEndpoint::class);
        $query = $model::query()->whereKey($id);
        $query = self::applyNamespace($query, $namespace);

        /** @var WorkflowServiceEndpoint|null $endpoint */
        $endpoint = $query->first();

        return $endpoint;
    }

    public static function findService(string $id, ?string $namespace = null): ?WorkflowService
    {
        $model = ConfiguredV2Models::resolve('service_model', WorkflowService::class);
        $query = $model::query()->whereKey($id);
        $query = self::applyNamespace($query, $namespace);

        /** @var WorkflowService|null $service */
        $service = $query->first();

        return $service;
    }

    public static function findOperation(string $id, ?string $namespace = null): ?WorkflowServiceOperation
    {
        $model = ConfiguredV2Models::resolve('service_operation_model', WorkflowServiceOperation::class);
        $query = $model::query()->whereKey($id);
        $query = self::applyNamespace($query, $namespace);

        /** @var WorkflowServiceOperation|null $operation */
        $operation = $query->first();

        return $operation;
    }

    /**
     * Look up a service call so that out-of-namespace records resolve as null.
     */
    public static function findServiceCall(string $id, ?string $namespace = null): ?WorkflowServiceCall
    {
        $model = ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class);
        $query = $model::query()->whereKey($id);
        $query = self::applyNamespace($query, $namespace);

        /** @var WorkflowServiceCall|null $call */
        $call = $query->first();

        return $call;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    private static function applyNamespace(Builder $query, ?string $namespace): Builder
    {
        if ($namespace !== null) {
            $query->where('namespace', $namespace);
        }

        return $query;
    }
}
