<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Support\ConfiguredV2Models;

/** @internal SQL lifecycle shared by original root reservation and intent closure. */
final class RootReservationRows
{
    public static function reserve(RootReservation $candidate): RootReservation
    {
        self::assertTransaction();
        $identity = $candidate->identity;
        $root = self::instances()->lockForUpdate()->find($identity->instanceId());
        if ($root !== null) {
            $state = RootReservationState::readOwned($root, $identity);
            $stored = RootReservation::fromArray($state['reservation']);
            RootReservationState::assertPolicies(
                $stored,
                $candidate->authorizationVersion,
                $candidate->scopeAuthorizationVersion
            );
            return $stored;
        }
        // Unique insertion chooses one original run even when the first two
        // reservations overlap. A database deadlock retries this pure SQL body.
        self::instances()->insertOrIgnore([
            'id' => $identity->instanceId(),
            'workflow_class' => $identity->workflowClass,
            'workflow_type' => $identity->workflowType,
            'namespace' => $identity->scope->namespace,
            'reserved_at' => now(),
            'run_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            'native_root_reservation' => RootReservationState::encode([
                'version' => 1,
                'reservation' => $candidate->toArray(),
                'status' => 'reserved',
                'admission' => null,
                'closureId' => null,
            ]),
        ]);
        $root = self::instances()->lockForUpdate()->findOrFail($identity->instanceId());
        $state = RootReservationState::readOwned($root, $identity);
        $stored = RootReservation::fromArray($state['reservation']);
        if ($stored->fingerprint() !== $candidate->fingerprint()) {
            RootReservationState::assertPolicies(
                $stored,
                $candidate->authorizationVersion,
                $candidate->scopeAuthorizationVersion
            );
            return $stored;
        }
        $database = ConfiguredV2Models::connection();
        $existing = $database->table(ScopeStorage::SCOPES)->where('id', $identity->scope->id())
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            throw new LogicException('An existing scope cannot become a new root reservation.');
        }
        $created = $database->table(ScopeStorage::SCOPES)->insertOrIgnore([
            'id' => $identity->scope->id(),
            'identity' => PortableDescriptor::encode($identity->scope->toArray()),
            'recovery_instance_id' => $identity->scope->recoveryInstanceId(),
            'original_run_id' => $stored->originalRunId,
            'status' => 'root_reserved',
        ]);
        if ($created !== 1) {
            throw new LogicException('An ordinary scope opening won the root reservation race.');
        }
        ScopeStorage::append(self::scope($stored), 'root_reserved', [
            'reservationFingerprint' => $stored->fingerprint(),
        ]);
        return $stored;
    }

    public static function close(RootReservation $reservation): RootBinding|NeverAdmittedRoot
    {
        self::assertTransaction();
        $root = self::instances()->lockForUpdate()->findOrFail($reservation->identity->instanceId());
        $state = RootReservationState::matching($root, $reservation);
        if ($state['status'] === 'admitted') {
            return RootBinding::fromArray($state['admission']);
        }
        self::assertUnused($root);
        $scope = self::scope($reservation);
        if ($state['status'] === 'reserved') {
            self::assertReservedScope($scope);
            $state['status'] = 'closed';
            $state['closureId'] = (string) Str::ulid();
            ConfiguredV2Models::connection()->table(ScopeStorage::SCOPES)
                ->where('id', $scope['id'])->update([
                    'status' => 'root_never_admitted',
                ]);
            ScopeStorage::append($scope, 'root_never_admitted', [
                'closureId' => $state['closureId'],
            ]);
            RootReservationState::write($root, $state);
        } elseif ($scope['status'] !== 'root_never_admitted') {
            throw new LogicException('The root closure has no matching permanent scope fence.');
        }
        return new NeverAdmittedRoot($reservation, $state['closureId']);
    }

    public static function assertUnused(WorkflowInstance $root): void
    {
        if ((int) $root->run_count !== 0 || $root->current_run_id !== null || $root->runs()->exists()
            || $root->getRawOriginal('native_child_reservation') !== null) {
            throw new LogicException('An uncertain root cannot be certified never admitted.');
        }
    }

    public static function scope(RootReservation $reservation): array
    {
        $identity = $reservation->identity->scope;
        $scope = ConfiguredV2Models::connection()->table(ScopeStorage::SCOPES)->where('id', $identity->id())
            ->lockForUpdate()
            ->first();
        if ($scope === null || $scope->identity !== PortableDescriptor::encode($identity->toArray())
            || $scope->original_run_id !== $reservation->originalRunId
            || $scope->recovery_instance_id !== $identity->recoveryInstanceId()) {
            throw new LogicException('The exact original root scope fence is unavailable.');
        }
        return (array) $scope;
    }

    public static function assertReservedScope(array $scope): void
    {
        if ($scope['status'] !== 'root_reserved' || (int) $scope['obligation_count'] !== 0
            || (int) $scope['request_count'] !== 0 || (int) $scope['event_sequence'] !== 1) {
            throw new LogicException('The original root scope is no longer reserved and empty.');
        }
    }

    private static function assertTransaction(): void
    {
        if (self::instances()->getModel()->getConnection() !== ConfiguredV2Models::connection()
            || ConfiguredV2Models::connection()->transactionLevel() !== 1) {
            throw new LogicException('Root reservation rows require their owned native transaction.');
        }
    }

    /**
     * @return Builder<WorkflowInstance>
     */
    private static function instances(): Builder
    {
        return ConfiguredV2Models::query('instance_model', WorkflowInstance::class);
    }
}
