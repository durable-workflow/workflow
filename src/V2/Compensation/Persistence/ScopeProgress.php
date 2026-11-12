<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Persistence;

use Closure;
use Illuminate\Database\Connection;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\Resolution;

/**
 * @internal Mutations run inside ScopeBoundary's native-attempt transaction. No lease is owned here.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class ScopeProgress
{
    /**
     * @param (Closure(array): void)|null $beforeDispatch Pure validation before recording dispatch uncertainty.
     */
    public function admitForward(
        ScopeIdentity $identity,
        string $id,
        string $attemptId,
        ?string $activityId = null,
        ?Closure $beforeDispatch = null
    ): array {
        return ScopeStorage::mutate($identity, function (array $scope, Connection $database) use (
            $id,
            $attemptId,
            $activityId,
            $beforeDispatch
        ): array {
            $row = $this->obligation($scope, $database, $id);
            if ($scope['status'] !== 'open') {
                throw new LogicException('The scope has closed forward admission.');
            }
            if ($row['forward_status'] === 'observed') {
                return [
                    'cached' => json_decode($row['forward_result'], true, flags: JSON_THROW_ON_ERROR),
                    'uncertain' => false,
                    'row' => $row,
                ];
            }
            $beforeDispatch?->__invoke($row);
            $uncertain = $row['forward_status'] !== 'registered';
            $database->table(ScopeStorage::OBLIGATIONS)->where('id', $id)->update([
                'forward_status' => 'in_flight',
                'forward_activity_id' => $activityId,
                'forward_attempt_id' => $attemptId,
            ]);
            ScopeStorage::append($scope, 'forward_admitted', [
                'obligationId' => $id,
                'attemptId' => $attemptId,
                'uncertain' => $uncertain,
            ]);
            return [
                'cached' => null,
                'uncertain' => $uncertain,
                'row' => $row,
            ];
        });
    }

    public function forwardResult(ScopeIdentity $identity, string $id, string $attemptId, ForwardResult $result): void
    {
        ScopeStorage::mutate($identity, function (array $scope, Connection $database) use (
            $id,
            $attemptId,
            $result
        ): void {
            $row = $this->obligation($scope, $database, $id);
            $payload = PortableDescriptor::encode($result->toArray());
            if ($row['forward_attempt_id'] !== $attemptId) {
                throw new LogicException('The forward result belongs to another native attempt.');
            }
            if ($row['forward_result'] === $payload && $row['forward_status'] === ($result->observed ? 'observed' : 'unknown')) {
                return;
            }
            if ($row['forward_status'] === 'observed' || $row['compensation_status'] === 'verified') {
                throw new LogicException('A terminal scope outcome cannot be replaced.');
            }
            $database->table(ScopeStorage::OBLIGATIONS)->where('id', $id)->update([
                'forward_status' => $result->observed ? 'observed' : 'unknown',
                'forward_result' => $payload,
            ]);
            ScopeStorage::append($scope, 'forward_outcome', [
                'obligationId' => $id,
                'attemptId' => $attemptId,
                'observed' => $result->observed,
                'reason' => $result->reason,
                'evidence' => $result->evidence,
            ]);
        });
    }

    public function beginCompensation(ScopeIdentity $identity, string $id, string $attemptId): ?array
    {
        return ScopeStorage::mutate($identity, function (array $scope, Connection $database) use (
            $id,
            $attemptId
        ): ?array {
            $row = $this->obligation($scope, $database, $id);
            if (! in_array($scope['status'], ['requested', 'verified'], true)) {
                throw new LogicException('Compensation requires an accepted request.');
            }
            if ($row['compensation_status'] === 'verified') {
                return null;
            }
            $next = $database->table(ScopeStorage::OBLIGATIONS)->where('scope_id', $scope['id'])
                ->where('compensation_status', '<>', 'verified')
                ->orderByDesc('position')
                ->value('id');
            if ($next !== $id) {
                throw new LogicException('Sequential compensation must resolve later obligations first.');
            }
            $database->table(ScopeStorage::OBLIGATIONS)->where('id', $id)->update([
                'compensation_status' => 'executing',
                'compensation_attempt_id' => $attemptId,
            ]);
            ScopeStorage::append($scope, 'compensation_started', [
                'obligationId' => $id,
                'attemptId' => $attemptId,
            ]);
            return $row;
        });
    }

    public function resolve(ScopeIdentity $identity, string $id, string $attemptId, Resolution $result): void
    {
        ScopeStorage::mutate($identity, function (array $scope, Connection $database) use (
            $id,
            $attemptId,
            $result
        ): void {
            $row = $this->obligation($scope, $database, $id);
            $payload = PortableDescriptor::encode($result->toArray());
            if ($row['compensation_attempt_id'] !== $attemptId) {
                throw new LogicException('The compensation outcome belongs to another native attempt.');
            }
            if ($row['evidence'] === $payload && $row['compensation_status'] === ($result->verified ? 'verified' : 'unknown')) {
                return;
            }
            if ($row['compensation_status'] === 'verified' || ($result->verified && ! $result->forwardSettled)) {
                throw new LogicException('Compensation is not verified while a late forward effect is still possible.');
            }
            $database->table(ScopeStorage::OBLIGATIONS)->where('id', $id)->update([
                'compensation_status' => $result->verified ? 'verified' : 'unknown',
                'evidence' => $payload,
            ]);
            ScopeStorage::append($scope, 'compensation_outcome', [
                'obligationId' => $id,
                'attemptId' => $attemptId,
                'verified' => $result->verified,
                'reason' => $result->reason,
                'evidence' => $result->evidence,
            ]);
        });
    }

    public function finish(ScopeIdentity $identity): array
    {
        return ScopeStorage::mutate($identity, static function (array $scope, Connection $database): array {
            if (! in_array($scope['status'], ['requested', 'verified'], true)
                || $database->table(ScopeStorage::OBLIGATIONS)->where('scope_id', $scope['id'])
                    ->where('compensation_status', '<>', 'verified')
                    ->exists()) {
                throw new LogicException('The scope retains unresolved compensation obligations.');
            }
            if ($scope['status'] !== 'verified') {
                $database->table(ScopeStorage::SCOPES)->where('id', $scope['id'])->update([
                    'status' => 'verified',
                ]);
                ScopeStorage::append($scope, 'verified', []);
            }
            return [
                'scopeId' => $scope['id'],
                'status' => 'verified',
            ];
        });
    }

    private function obligation(array $scope, Connection $database, string $id): array
    {
        $row = $database->table(ScopeStorage::OBLIGATIONS)->where('scope_id', $scope['id'])->where('id', $id)->first();
        if ($row === null) {
            throw new LogicException('The obligation is unavailable in the exact scope generation.');
        }
        return (array) $row;
    }
}
