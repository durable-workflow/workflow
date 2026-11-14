<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Persistence;

use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\WorkflowFiberContext;

/**
 * @internal Live storage boundary. Call from activities, never from workflow replay.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class ScopeRepository
{
    public const MAX_OBLIGATIONS = 256;

    private const SCOPES = 'workflow_compensation_scopes';

    private const OBLIGATIONS = 'workflow_compensation_obligations';

    private const JOURNAL = 'workflow_compensation_journal';

    public function open(ScopeIdentity $identity, string $originalRunId): void
    {
        if (WorkflowFiberContext::active()) {
            throw new LogicException(
                'Scope storage belongs at a recorded activity boundary, never in workflow replay.'
            );
        }
        PortableDescriptor::identifier($originalRunId, 'original run');
        $database = ConfiguredV2Models::connection();
        SqliteTransaction::run(static function () use ($database, $identity, $originalRunId): void {
            // Lock an existing row directly. INSERT IGNORE first would retain
            // a shared duplicate-key lock and deadlock concurrent adopters
            // when both then upgrade it to an exclusive lock on InnoDB.
            $existing = $database->table(self::SCOPES)->where('id', $identity->id())->lockForUpdate()->first();
            $created = $existing === null ? $database->table(self::SCOPES)->insertOrIgnore([
                'id' => $identity->id(),
                'identity' => PortableDescriptor::encode($identity->toArray()),
                'recovery_instance_id' => $identity->recoveryInstanceId(),
                'original_run_id' => $originalRunId,
                'status' => 'open',
            ]) : 0;
            $scope = ScopeStorage::locked($identity);
            if ($scope['original_run_id'] !== $originalRunId) {
                throw new NonRetryableException('scope_execution_owner_mismatch');
            }
            if ($created === 1) {
                ScopeStorage::append($scope, 'opened', [
                    'originalRunId' => $originalRunId,
                ]);
            }
        }, connection: $database);
    }

    public function register(ScopeIdentity $identity, Obligation $obligation): string
    {
        return ScopeStorage::mutate($identity, static function (array $scope, Connection $database) use (
            $identity,
            $obligation
        ): string {
            if ($scope['status'] === 'released') {
                throw new LogicException('A released compensation scope cannot be reused.');
            }
            $id = hash('sha256', PortableDescriptor::encode([$identity->id(), $obligation->key]));
            $existing = $database->table(self::OBLIGATIONS)->where('id', $id)->first();
            if ($existing !== null) {
                if ($existing->fingerprint !== $obligation->fingerprint()) {
                    throw new LogicException('A compensation operation key was reused with different content.');
                }
                return $id;
            }
            if ($scope['status'] !== 'open' || $scope['obligation_count'] >= self::MAX_OBLIGATIONS) {
                throw new LogicException('The compensation scope does not admit new obligations.');
            }
            $position = (int) $scope['obligation_count'] + 1;
            $database->table(self::OBLIGATIONS)->insert([
                'id' => $id,
                'scope_id' => $identity->id(),
                'position' => $position,
                'fingerprint' => $obligation->fingerprint(),
                'descriptor' => PortableDescriptor::encode($obligation->toArray()),
                'forward_status' => 'registered',
                'compensation_status' => 'pending',
            ]);
            $database->table(self::SCOPES)->where('id', $scope['id'])->update([
                'obligation_count' => $position,
            ]);
            ScopeStorage::append($scope, 'registered', [
                'obligationId' => $id,
                'fingerprint' => $obligation->fingerprint(),
            ]);
            return $id;
        });
    }

    public function request(
        ScopeIdentity $identity,
        string $key,
        string $principal,
        string $reason,
        ?string $policyVersion = null
    ): void {
        foreach ([
            'key' => $key,
            'principal' => $principal,
            'reason' => $reason,
        ] as $name => $value) {
            PortableDescriptor::identifier($value, $name);
        }
        $descriptor = PortableDescriptor::encode([
            'key' => $key,
            'principal' => $principal,
            'reason' => $reason,
        ]);
        $fingerprint = hash('sha256', $descriptor);
        ScopeStorage::mutate($identity, static function (array $scope, Connection $database) use (
            $identity,
            $key,
            $descriptor,
            $fingerprint,
            $policyVersion
        ): void {
            $id = hash('sha256', PortableDescriptor::encode([$identity->id(), $key]));
            $existing = $database->table('workflow_compensation_requests')
                ->where('id', $id)
                ->first();
            if ($existing !== null) {
                if ($existing->fingerprint !== $fingerprint) {
                    throw new LogicException(
                        'The compensation request identity was reused with different authority or content.'
                    );
                }
                return;
            }
            if ($scope['status'] === 'released' || $scope['request_count'] >= 128) {
                throw new LogicException('The compensation scope does not admit another request.');
            }
            $position = (int) $scope['request_count'] + 1;
            $database->table('workflow_compensation_requests')
                ->insert([
                    'id' => $id,
                    'scope_id' => $scope['id'],
                    'position' => $position,
                    'fingerprint' => $fingerprint,
                    'descriptor' => $descriptor,
                    'authorization_policy_version' => $policyVersion,
                ]);
            $database->table(self::SCOPES)->where('id', $scope['id'])->update([
                'request_count' => $position,
                'status' => $scope['status'] === 'verified' ? 'verified' : 'requested',
            ]);
            ScopeStorage::append($scope, 'requested', [
                'requestId' => $id,
                'fingerprint' => $fingerprint,
                'policyVersion' => $policyVersion,
            ]);
        });
    }

    public function primaryRequest(ScopeIdentity $identity): array
    {
        return ScopeStorage::mutate($identity, static function (array $scope, Connection $database): array {
            $row = $database->table('workflow_compensation_requests')
                ->where('scope_id', $scope['id'])->orderBy('position')->first();
            if ($row === null) {
                throw new LogicException('The compensation scope has no authorized request.');
            }
            return json_decode($row->descriptor, true, flags: JSON_THROW_ON_ERROR);
        });
    }

    /**
     * Bind the first native recovery target before an admission can lose its response.
     */
    public function recoveryRoute(
        ScopeIdentity $identity,
        string $requestKey,
        string $profileFingerprint,
        ?Closure $select = null,
    ): ?array {
        return ScopeStorage::mutate($identity, static function (array $scope, Connection $database) use (
            $identity,
            $requestKey,
            $profileFingerprint,
            $select,
        ): ?array {
            $id = hash('sha256', PortableDescriptor::encode([$identity->id(), $requestKey]));
            $row = $database->table('workflow_compensation_requests')
                ->where('scope_id', $scope['id'])
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                throw new LogicException('Recovery admission requires an authorized retained request.');
            }
            if ($row->recovery_route === null) {
                if ($select === null) {
                    return null;
                }
                $route = [
                    'version' => 1,
                    'profileFingerprint' => $profileFingerprint,
                    ...$select(),
                ];
            } else {
                $route = json_decode($row->recovery_route, true, flags: JSON_THROW_ON_ERROR);
            }
            $keys = is_array($route) ? array_keys($route) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['kind', 'predecessorRunId', 'profileFingerprint', 'version']
                || $route['version'] !== 1
                || ! in_array($route['kind'], ['start', 'redrive'], true)
                || $route['profileFingerprint'] !== $profileFingerprint
                || ($route['predecessorRunId'] !== null && (! is_string($route['predecessorRunId'])
                    || $route['predecessorRunId'] === ''))
                || ($route['kind'] === 'redrive' && $route['predecessorRunId'] === null)) {
                throw new LogicException('The retained recovery route is invalid or its request profile changed.');
            }
            if ($row->recovery_route === null) {
                $database->table('workflow_compensation_requests')
                    ->where('id', $id)
                    ->update([
                        'recovery_route' => PortableDescriptor::encode($route),
                    ]);
                ScopeStorage::append($scope, 'recovery_route_bound', [
                    'requestId' => $id,
                    'route' => $route,
                ]);
            }
            return $route;
        });
    }

    public function metadata(ScopeIdentity $identity): array
    {
        return ScopeStorage::mutate($identity, static fn (array $scope): array => $scope);
    }

    public function obligation(ScopeIdentity $identity, string $id): array
    {
        return ScopeStorage::mutate($identity, static function (array $scope, Connection $database) use ($id): array {
            $row = $database->table(self::OBLIGATIONS)->where('scope_id', $scope['id'])->where('id', $id)->first();
            if ($row === null) {
                throw new NonRetryableException('scope_obligation_unavailable');
            }
            return (array) $row;
        });
    }

    public function inspect(ScopeIdentity $identity): array
    {
        return ScopeStorage::mutate($identity, static function (array $scope, Connection $database): array {
            $scope['identity'] = json_decode($scope['identity'], true, flags: JSON_THROW_ON_ERROR);
            $scope['obligations'] = $database->table(self::OBLIGATIONS)->where('scope_id', $scope['id'])
                ->orderBy('position')
                ->get()
                ->map(static function (object $row): array {
                    $item = (array) $row;
                    $item['descriptor'] = json_decode($item['descriptor'], true, flags: JSON_THROW_ON_ERROR);
                    return $item;
                })->all();
            return $scope;
        });
    }

    public function journal(ScopeIdentity $identity): array
    {
        return ScopeStorage::mutate($identity, static fn (array $scope, Connection $database): array =>
            $database->table(self::JOURNAL)->where('scope_id', $scope['id'])->orderBy('sequence')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all());
    }
}
