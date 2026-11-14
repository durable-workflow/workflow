<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Persistence;

use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;

/**
 * @internal Bounded sequential scope participants, not state-bound machine definitions.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final class ChildScopes
{
    public const TYPE = 'dw.child-scope';

    public const VERSION = '1';

    public const RESERVED_TYPE = 'dw.reserved-child-scope';

    public const MAX_CHILDREN = 16;

    public const MAX_DEPTH = 1;

    public function register(ScopeIdentity $parent, string $originalRunId, string $key, ScopeIdentity $child): string
    {
        return $this->registerParticipant(
            $parent,
            $originalRunId,
            $child,
            new Obligation($key, self::TYPE, self::VERSION, [
                'child' => $child->toArray(),
            ])
        );
    }

    public function registerReserved(ChildReservation $reservation): string
    {
        $participation = $reservation->participation
            ?? throw new LogicException('A reserved child participant needs its retained scope descriptor.');
        return $this->registerParticipant(
            $participation->parent,
            $reservation->identity->parentRunId,
            $participation->child,
            self::reservedObligation($reservation),
        );
    }

    /**
     * @internal Runs under the original native task and reserved-instance locks.
     */
    public static function assertReservedAdmission(ChildReservation $reservation): void
    {
        if (ConfiguredV2Models::connection()->transactionLevel() === 0) {
            throw new LogicException('Retained child admission requires its native transaction.');
        }
        $participation = $reservation->participation
            ?? throw new LogicException('The reserved child has no retained participant.');
        $scope = ScopeStorage::locked($participation->parent);
        $database = ConfiguredV2Models::connection();
        $obligation = self::reservedObligation($reservation);
        $id = hash('sha256', PortableDescriptor::encode([$participation->parent->id(), $obligation->key]));
        $row = $database->table(ScopeStorage::OBLIGATIONS)->where('scope_id', $scope['id'])->where('id', $id)->first();
        $edge = $database->table('workflow_compensation_children')
            ->where('child_id', $participation->child->id())
            ->first();
        if ($scope['status'] !== 'open' || $scope['original_run_id'] !== $reservation->identity->parentRunId
            || $row === null || $row->fingerprint !== $obligation->fingerprint()
            || $row->descriptor !== PortableDescriptor::encode($obligation->toArray())
            || $row->compensation_status !== 'pending'
            || $edge === null || $edge->parent_id !== $scope['id'] || $edge->obligation_id !== $id) {
            throw new LogicException('The exact retained participant has not admitted this child.');
        }
    }

    /**
     * @internal The genuine child scope is committed with its native run and receipt.
     */
    public static function recordReservedAdmission(ChildReservation $reservation, StartedChildWorkflow $receipt): void
    {
        self::assertReservedAdmission($reservation);
        $participation = $reservation->participation
            ?? throw new LogicException('The reserved child has no retained participant.');
        (new ScopeRepository())->open($participation->child, $receipt->childRunId);
        ScopeStorage::append(ScopeStorage::locked($participation->parent), 'child_admitted', [
            'childScopeId' => $participation->child->id(),
            'bindingFingerprint' => $reservation->identity->fingerprint(),
            'admission' => $receipt->toArray(),
        ]);
    }

    private static function reservedObligation(ChildReservation $reservation): Obligation
    {
        $participation = $reservation->participation
            ?? throw new LogicException('The reserved child has no retained participant.');
        return new Obligation($participation->obligationKey, self::RESERVED_TYPE, self::VERSION, [
            'reservation' => $reservation->toArray(),
        ]);
    }

    private function registerParticipant(
        ScopeIdentity $parent,
        string $originalRunId,
        ScopeIdentity $child,
        Obligation $obligation,
    ): string {
        if ($parent->application !== $child->application || $parent->namespace !== $child->namespace || $parent->id() === $child->id()) {
            throw new LogicException('A child scope must have another identity in the same application and namespace.');
        }
        $database = ConfiguredV2Models::connection();
        $graph = hash('sha256', PortableDescriptor::encode([$parent->application, $parent->namespace]));
        return SqliteTransaction::run(static function () use (
            $parent,
            $originalRunId,
            $obligation,
            $child,
            $database,
            $graph
        ): string {
            // Serialize edge mutations before scope locks. This is a graph-integrity lock, not executor ownership.
            $existingGraph = $database->table('workflow_compensation_graphs')
                ->where('id', $graph)
                ->lockForUpdate()
                ->first();
            if ($existingGraph === null) {
                $database->table('workflow_compensation_graphs')
                    ->insertOrIgnore([
                        'id' => $graph,
                    ]);
                $database->table('workflow_compensation_graphs')
                    ->where('id', $graph)
                    ->lockForUpdate()
                    ->first();
            }
            $existing = $database->table('workflow_compensation_children')
                ->where('child_id', $child->id())
                ->lockForUpdate()
                ->first();
            if (($existing !== null && $existing->parent_id !== $parent->id())
                || $database->table('workflow_compensation_children')
                    ->where('child_id', $parent->id())
                    ->lockForUpdate()
                    ->first() !== null
                || $database->table('workflow_compensation_children')
                    ->where('parent_id', $child->id())
                    ->lockForUpdate()
                    ->first() !== null) {
                throw new LogicException(
                    'A child scope cannot be shared, cyclic, or nested beyond the supported depth.'
                );
            }
            if ($existing === null && $database->table('workflow_compensation_children')->where(
                'parent_id',
                $parent->id()
            )->lockForUpdate()
                ->get()
                ->count() >= self::MAX_CHILDREN) {
                throw new LogicException('The child scope fan-out limit is exhausted.');
            }
            $repository = new ScopeRepository();
            $repository->open($parent, $originalRunId);
            $id = $repository->register($parent, $obligation);
            if ($existing !== null && $existing->obligation_id !== $id) {
                throw new LogicException('The child generation already belongs to another participant.');
            }
            if ($existing === null) {
                $database->table('workflow_compensation_children')
                    ->insert([
                        'child_id' => $child->id(),
                        'parent_id' => $parent->id(),
                        'obligation_id' => $id,
                    ]);
            }
            return $id;
        }, connection: $database);
    }
}
