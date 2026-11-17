<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeStorage;
use Workflow\V2\Compensation\Security\ScopeAuthorization;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowFiberContext;
use Workflow\V2\WorkflowStub;

/**
 * @experimental Host/activity boundary, never a replay API. The permanent native
 * instance fence and reserved scope serialize original admission against closure.
 */
final readonly class RootReservationStore
{
    public function __construct(
        private RootAuthorization $authorization,
        private ScopeAuthorization $scopeAuthorization
    ) {
    }

    public function reserve(RootIdentity $identity, string $principal): RootReservation
    {
        [$version, $scopeVersion] = $this->authorize($identity, $principal, 'reserve');
        self::assertDefinition($identity);
        $connection = self::instances()->getModel()->getConnection();
        if (! $connection->getSchemaBuilder()->hasColumn('workflow_instances', 'native_root_reservation')) {
            throw new LogicException('Native root reservations require the reservation migration.');
        }
        $candidate = new RootReservation($identity, (string) Str::ulid(), $version, $scopeVersion);
        return SqliteTransaction::run(static function () use ($candidate, $identity): RootReservation {
            $root = self::instances()->lockForUpdate()->find($identity->instanceId());
            if ($root !== null) {
                $state = self::readOwned($root, $identity);
                $stored = RootReservation::fromArray($state['reservation']);
                if ($stored->authorizationVersion !== $candidate->authorizationVersion
                    || $stored->scopeAuthorizationVersion !== $candidate->scopeAuthorizationVersion) {
                    throw new LogicException('The original root authorization policies must remain pinned.');
                }
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
                'native_root_reservation' => json_encode([
                    'version' => 1,
                    'reservation' => $candidate->toArray(),
                    'status' => 'reserved',
                    'admission' => null,
                    'closureId' => null,
                ], JSON_THROW_ON_ERROR),
            ]);
            $root = self::instances()->lockForUpdate()->findOrFail($identity->instanceId());
            $state = self::readOwned($root, $identity);
            $stored = RootReservation::fromArray($state['reservation']);
            if ($stored->fingerprint() !== $candidate->fingerprint()) {
                if ($stored->authorizationVersion !== $candidate->authorizationVersion
                    || $stored->scopeAuthorizationVersion !== $candidate->scopeAuthorizationVersion) {
                    throw new LogicException('The original root authorization policies must remain pinned.');
                }
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
        }, attempts: 3, admission: static fn () => self::acquireWriter(
            $identity->instanceId()
        ), connection: $connection);
    }

    /**
     * Recovery reads the historical descriptor as data; lookup absence is never a receipt.
     */
    public function load(RootIdentity $identity, string $principal): RootReservation
    {
        $this->authorize($identity, $principal, 'load');
        $root = self::instances()->findOrFail($identity->instanceId());
        return RootReservation::fromArray(self::readOwned($root, $identity)['reservation']);
    }

    public function start(
        RootReservation $reservation,
        string $principal,
        array $arguments
    ): RootBinding|NeverAdmittedRoot {
        return WorkflowStub::startReservedRoot($this, $reservation, $principal, $arguments);
    }

    /**
     * Every close rereads the locked permanent fence, including after a lost acknowledgement.
     */
    public function close(RootReservation $reservation, string $principal): RootBinding|NeverAdmittedRoot
    {
        $this->authorizeReservation($reservation, $principal, 'close');
        return SqliteTransaction::run(
            static function () use ($reservation): RootBinding|NeverAdmittedRoot {
                $root = self::instances()->lockForUpdate()->findOrFail($reservation->identity->instanceId());
                $state = self::matching($root, $reservation);
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
                    self::write($root, $state);
                } elseif ($scope['status'] !== 'root_never_admitted') {
                    throw new LogicException('The root closure has no matching permanent scope fence.');
                }
                return new NeverAdmittedRoot($reservation, $state['closureId']);
            },
            admission: static fn () => self::acquireWriter($reservation->identity->instanceId()),
            connection: self::instances()->getModel()->getConnection()
        );
    }

    /**
     * @internal Native entrypoint must authorize before loading any protected row.
     */
    public function authorizeStart(RootReservation $reservation, string $principal, array $arguments): void
    {
        $this->authorizeReservation($reservation, $principal, 'start');
        if (ChildReservation::inputFingerprint($arguments) !== $reservation->identity->inputFingerprint) {
            throw new LogicException('The root start input differs from its original reservation.');
        }
    }

    /**
     * @internal Called only under native admission's existing instance transaction.
     */
    public static function prepareAdmission(
        WorkflowInstance $root,
        RootReservation $reservation
    ): RootBinding|NeverAdmittedRoot|null {
        self::assertNativeTransaction($root);
        $state = self::matching($root, $reservation);
        if ($state['status'] === 'admitted') {
            return RootBinding::fromArray($state['admission']);
        }
        self::assertUnused($root);
        $scope = self::scope($reservation);
        if ($state['status'] === 'closed') {
            if ($scope['status'] !== 'root_never_admitted') {
                throw new LogicException('The root closure has no matching permanent scope fence.');
            }
            return new NeverAdmittedRoot($reservation, $state['closureId']);
        }
        self::assertReservedScope($scope);
        self::assertDefinition($reservation->identity);
        if (CodecRegistry::defaultCodec() !== $reservation->identity->profile->codec
            || \Workflow\V2\Support\WorkerCompatibility::current() !== $reservation->identity->profile->compatibility) {
            throw new LogicException('The root codec or worker compatibility differs from its original reservation.');
        }
        return null;
    }

    /**
     * @internal Commit the genuine scope and native start receipt together, never in an after-commit callback.
     */
    public static function recordAdmission(
        WorkflowInstance $root,
        RootReservation $reservation,
        WorkflowRun $run,
        WorkflowCommand $command,
    ): RootBinding {
        self::assertNativeTransaction($root);
        $state = self::matching($root, $reservation);
        $scope = self::scope($reservation);
        self::assertReservedScope($scope);
        if ($state['status'] !== 'reserved' || $run->id !== $reservation->originalRunId
            || $run->workflow_instance_id !== $root->id || $root->current_run_id !== $run->id
            || (int) $root->run_count !== 1 || $command->workflow_run_id !== $run->id
            || $command->workflow_instance_id !== $root->id
            || $command->command_type !== \Workflow\V2\Enums\CommandType::Start
            || $command->status !== \Workflow\V2\Enums\CommandStatus::Accepted
            || $run->namespace !== $reservation->identity->scope->namespace
            || $run->workflow_class !== $reservation->identity->workflowClass
            || $run->workflow_type !== $reservation->identity->workflowType
            || $run->compatibility !== $reservation->identity->profile->compatibility
            || $run->payload_codec !== $reservation->identity->profile->codec
            || $run->connection !== $reservation->identity->profile->connection
            || $run->queue !== $reservation->identity->profile->queue) {
            throw new LogicException('Root scope opening requires its exact original native start.');
        }
        ConfiguredV2Models::connection()->table(ScopeStorage::SCOPES)->where('id', $scope['id'])
            ->update([
                'status' => 'open',
            ]);
        ScopeStorage::append($scope, 'opened', [
            'originalRunId' => $run->id,
        ]);
        $binding = new RootBinding($reservation, $command->id);
        $state['status'] = 'admitted';
        $state['admission'] = $binding->toArray();
        self::write($root, $state);
        return $binding;
    }

    public static function assertOrdinaryStartAllowed(WorkflowInstance $instance): void
    {
        if ($instance->getRawOriginal('native_root_reservation') !== null) {
            throw new LogicException('A reserved root requires its original atomic admission boundary.');
        }
    }

    private function authorizeReservation(RootReservation $reservation, string $principal, string $operation): void
    {
        [$version, $scopeVersion] = $this->authorize($reservation->identity, $principal, $operation);
        if ($version !== $reservation->authorizationVersion || $scopeVersion !== $reservation->scopeAuthorizationVersion) {
            throw new LogicException('The original root authorization policies must remain pinned.');
        }
    }

    private function authorize(RootIdentity $identity, string $principal, string $operation): array
    {
        $database = ConfiguredV2Models::connection();
        $assert = static function () use ($database): void {
            if (WorkflowFiberContext::active() || $database !== ConfiguredV2Models::connection()
                || $database !== self::instances()->getModel()->getConnection() || $database->transactionLevel() !== 0) {
                throw new LogicException('Root authorization requires its own unlocked host boundary outside replay.');
            }
        };
        $assert();
        $version = $this->authorization->assertAllowed($identity, $principal, $operation);
        $assert();
        $scopeVersion = $this->scopeAuthorization->assertAllowed($identity->scope, $principal, 'root_' . $operation);
        $assert();
        foreach ([$version, $scopeVersion] as $policy) {
            if (trim($policy) === '' || strlen($policy) > 128) {
                throw new LogicException('Root authorization requires bounded immutable policy versions.');
            }
        }
        return [$version, $scopeVersion];
    }

    private static function assertDefinition(RootIdentity $identity): void
    {
        if (! is_a($identity->workflowClass, \Workflow\V2\Workflow::class, true)
            || TypeRegistry::for($identity->workflowClass) !== $identity->workflowType
            || WorkflowDefinition::fingerprint($identity->workflowClass) !== $identity->definitionFingerprint) {
            throw new LogicException('The root definition differs from its original immutable descriptor.');
        }
    }

    private static function assertNativeTransaction(WorkflowInstance $root): void
    {
        if ($root->getConnection() !== ConfiguredV2Models::connection() || $root->getConnection()->transactionLevel() !== 1) {
            throw new LogicException('Root admission must own the native storage transaction.');
        }
    }

    private static function assertUnused(WorkflowInstance $root): void
    {
        if ((int) $root->run_count !== 0 || $root->current_run_id !== null || $root->runs()->exists()
            || $root->getRawOriginal('native_child_reservation') !== null) {
            throw new LogicException('An uncertain root cannot be certified never admitted.');
        }
    }

    private static function scope(RootReservation $reservation): array
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

    private static function assertReservedScope(array $scope): void
    {
        if ($scope['status'] !== 'root_reserved' || (int) $scope['obligation_count'] !== 0
            || (int) $scope['request_count'] !== 0 || (int) $scope['event_sequence'] !== 1) {
            throw new LogicException('The original root scope is no longer reserved and empty.');
        }
    }

    private static function matching(WorkflowInstance $root, RootReservation $reservation): array
    {
        $state = self::readOwned($root, $reservation->identity);
        if (PortableDescriptor::encode($state['reservation']) !== PortableDescriptor::encode($reservation->toArray())) {
            throw new LogicException('The root descriptor differs from its original reservation.');
        }
        return $state;
    }

    private static function readOwned(WorkflowInstance $root, RootIdentity $identity): array
    {
        // Owner check precedes decoding protected JSON or resolving any stored class.
        if ($root->id !== $identity->instanceId() || $root->namespace !== $identity->scope->namespace
            || $root->workflow_class !== $identity->workflowClass || $root->workflow_type !== $identity->workflowType) {
            throw new LogicException('The native root reservation does not match the original owner.');
        }
        $raw = $root->getRawOriginal('native_root_reservation');
        if (! is_string($raw) || strlen($raw) > 65_536) {
            throw new LogicException('The native root has no valid reservation record.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new LogicException('Malformed native root reservation state.');
        }
        RootFormat::keys($state, ['version', 'reservation', 'status', 'admission', 'closureId']);
        if ($state['version'] !== 1 || ! is_array($state['reservation'])
            || ! in_array($state['status'], ['reserved', 'admitted', 'closed'], true)) {
            throw new LogicException('Malformed native root reservation state.');
        }
        $reservation = RootReservation::fromArray($state['reservation']);
        if ($reservation->identity->fingerprint() !== $identity->fingerprint()) {
            throw new LogicException('The stored root has a different immutable descriptor.');
        }
        $state['reservation'] = $reservation->toArray();
        if ($state['status'] === 'admitted') {
            if (! is_array($state['admission']) || $state['closureId'] !== null) {
                throw new LogicException('Malformed native root admission state.');
            }
            $binding = RootBinding::fromArray($state['admission']);
            if ($binding->reservation->fingerprint() !== $reservation->fingerprint()) {
                throw new LogicException('The root receipt belongs to a different reservation.');
            }
            $state['admission'] = $binding->toArray();
        } elseif ($state['admission'] !== null || ($state['status'] === 'reserved' ? $state['closureId'] !== null
            : ! is_string($state['closureId']) || trim($state['closureId']) === '')) {
            throw new LogicException('Malformed native root closure state.');
        }
        return $state;
    }

    private static function write(WorkflowInstance $root, array $state): void
    {
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        self::instances()->whereKey($root->id)->toBase()->update([
            'native_root_reservation' => $encoded,
        ]);
        $root->setRawAttributes([
            ...$root->getAttributes(),
            'native_root_reservation' => $encoded,
        ], true);
    }

    /**
     * @return Builder<WorkflowInstance>
     */
    private static function instances(): Builder
    {
        return ConfiguredV2Models::query('instance_model', WorkflowInstance::class);
    }

    private static function acquireWriter(string $id): void
    {
        self::instances()->whereKey($id)->toBase()->update([
            'id' => $id,
        ]);
    }
}
