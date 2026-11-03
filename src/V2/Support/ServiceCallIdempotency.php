<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowServiceCall;

/** Canonical keyed calls use a portable unique digest, including null scope. */
final class ServiceCallIdempotency
{
    /**
     * @param array{?string, string, string, string, string} $scope
     */
    public static function digest(array $scope): string
    {
        return hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param array{?string, string, string, string, string} $scope
     */
    public static function find(array $scope, bool $lock = true): ?WorkflowServiceCall
    {
        $query = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->where('idempotency_scope_hash', self::digest($scope));
        $call = ($lock ? $query->lockForUpdate() : $query)
            ->first();
        if ($call !== null) {
            self::assertScope($call, $scope);
            return $call;
        }
        [$namespace, $endpoint, $service, $operation, $key] = $scope;
        $legacy = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->whereNull('idempotency_scope_hash')
            ->where('target_namespace', $namespace)
            ->where('endpoint_name', $endpoint)
            ->where('service_name', $service)
            ->where('operation_name', $operation)
            ->where('idempotency_key', $key)
            ->oldest('created_at')
            ->oldest('id');
        $call = ($lock ? $legacy->lockForUpdate() : $legacy)
            ->first();
        if ($call !== null) {
            self::assertScope($call, $scope);
        }
        return $call;
    }

    /**
     * Claim only after caller/catalog authorization. The savepoint keeps a
     * PostgreSQL uniqueness loser usable for locked refetch in the outer turn.
     * @param array{?string, string, string, string, string} $scope
     */
    public static function save(WorkflowServiceCall $call, array $scope): WorkflowServiceCall
    {
        self::assertScope($call, $scope);
        if ($call->exists && $call->idempotency_scope_hash === self::digest($scope)) {
            return $call;
        }
        $connection = $call->getConnection();
        try {
            $connection->transaction(static function () use ($call, $scope): void {
                $call->idempotency_scope_hash = self::digest($scope);
                $call->save();
            });
            return $call;
        } catch (UniqueConstraintViolationException $failure) {
            $query = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                ->where('idempotency_scope_hash', self::digest($scope))->lockForUpdate();
            $winner = $query->first();
            if ($winner === null) {
                throw $failure;
            }
            self::assertScope($winner, $scope);
            return $winner;
        }
    }

    /**
     * @param array{?string, string, string, string, string} $scope
     */
    private static function assertScope(WorkflowServiceCall $call, array $scope): void
    {
        $actual = [$call->target_namespace, $call->endpoint_name, $call->service_name,
            $call->operation_name, $call->idempotency_key];
        if ($call->namespace !== $scope[0] || $actual !== $scope) {
            throw new ServiceOperationAuthorizationException('service_call_idempotency_scope_conflict');
        }
    }
}
