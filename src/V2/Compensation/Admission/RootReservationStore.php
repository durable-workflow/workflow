<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;
use Workflow\Serializers\CodecRegistry;
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
 * Host-facing original root admission. Hosts call reserve(), load(), start(),
 * close() and closeIntent() from an authorized host boundary or a recorded
 * activity, never from workflow replay. The methods marked @internal are native
 * admission hooks that the engine calls. Root admission stays experimental after
 * the planned compensation-scope promotion (#1063).
 *
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
        $connection = self::connection();
        $candidate = new RootReservation($identity, (string) Str::ulid(), $version, $scopeVersion);
        return SqliteTransaction::run(
            static fn (): RootReservation => RootReservationRows::reserve($candidate),
            attempts: 3,
            admission: static fn () => self::acquireWriter($identity->instanceId()),
            connection: $connection
        );
    }

    /**
     * Recovery reads the historical descriptor as data; lookup absence is never a receipt.
     */
    public function load(RootIdentity $identity, string $principal): RootReservation
    {
        $this->authorize($identity, $principal, 'load');
        $root = self::instances()->findOrFail($identity->instanceId());
        return RootReservation::fromArray(RootReservationState::readOwned($root, $identity)['reservation']);
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
            static fn (): RootBinding|NeverAdmittedRoot => RootReservationRows::close($reservation),
            admission: static fn () => self::acquireWriter($reservation->identity->instanceId()),
            connection: self::connection()
        );
    }

    /**
     * Permanently close the authorized original intent even before its first reservation.
     * The retained definition stays data; no deployed class is required for non-admission.
     */
    public function closeIntent(RootIdentity $identity, string $principal): RootBinding|NeverAdmittedRoot
    {
        [$version, $scopeVersion] = $this->authorize($identity, $principal, 'close');
        $connection = self::connection();
        $candidate = new RootReservation($identity, (string) Str::ulid(), $version, $scopeVersion);
        return SqliteTransaction::run(
            static function () use ($candidate): RootBinding|NeverAdmittedRoot {
                $reservation = RootReservationRows::reserve($candidate);
                return RootReservationRows::close($reservation);
            },
            attempts: 3,
            admission: static fn () => self::acquireWriter($identity->instanceId()),
            connection: $connection
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
        $state = RootReservationState::matching($root, $reservation);
        if ($state['status'] === 'admitted') {
            return RootBinding::fromArray($state['admission']);
        }
        RootReservationRows::assertUnused($root);
        $scope = RootReservationRows::scope($reservation);
        if ($state['status'] === 'closed') {
            if ($scope['status'] !== 'root_never_admitted') {
                throw new LogicException('The root closure has no matching permanent scope fence.');
            }
            return new NeverAdmittedRoot($reservation, $state['closureId']);
        }
        RootReservationRows::assertReservedScope($scope);
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
        $state = RootReservationState::matching($root, $reservation);
        $scope = RootReservationRows::scope($reservation);
        RootReservationRows::assertReservedScope($scope);
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
        RootReservationState::write($root, $state);
        return $binding;
    }

    /**
     * @internal Ordinary start, retry, redrive, import and continue-as-new paths call this native fence check.
     */
    public static function assertOrdinaryStartAllowed(WorkflowInstance $instance): void
    {
        if ($instance->getRawOriginal('native_root_reservation') !== null) {
            throw new LogicException('A reserved root requires its original atomic admission boundary.');
        }
    }

    private function authorizeReservation(RootReservation $reservation, string $principal, string $operation): void
    {
        [$version, $scopeVersion] = $this->authorize($reservation->identity, $principal, $operation);
        RootReservationState::assertPolicies($reservation, $version, $scopeVersion);
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

    private static function connection(): \Illuminate\Database\Connection
    {
        $model = self::instances()->getModel();
        $connection = $model->getConnection();
        if (! $connection->getSchemaBuilder()->hasColumn($model->getTable(), 'native_root_reservation')) {
            throw new LogicException('Native root reservations require the reservation migration.');
        }
        return $connection;
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
