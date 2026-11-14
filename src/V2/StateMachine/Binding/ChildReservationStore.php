<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LogicException;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowDefinition;

/**
 * @experimental Recorded activities call this host boundary. Guards/replay must
 * not call it. Reservation, native admission and closure share one instance row.
 * Closed unused rows are permanent fences, not run-retention candidates.
 */
final class ChildReservationStore
{
    public function __construct(
        private readonly BindingAuthorization $authorization
    ) {
    }

    public function reserve(BindingIdentity $identity, string $workflowClass, string $principal): ChildReservation
    {
        $version = $this->authorize($identity, $principal, 'reserve');
        if (TypeRegistry::for($workflowClass) !== $identity->childWorkflowType) {
            throw new LogicException('The binding child type does not match its definition.');
        }
        $fingerprint = WorkflowDefinition::fingerprint($workflowClass);
        if ($fingerprint === null) {
            throw new LogicException('The child definition has no immutable fingerprint.');
        }
        $candidate = new ChildReservation($identity, $workflowClass, $fingerprint, $version);
        $query = self::instances();
        $connection = $query->getModel()
            ->getConnection();
        if (! $connection->getSchemaBuilder()->hasColumn($query->getModel()->getTable(), 'native_child_reservation')) {
            throw new LogicException('Native child reservations require the reservation migration.');
        }

        return SqliteTransaction::run(static function () use ($candidate, $identity): ChildReservation {
            // The parent row serializes first creation of this deterministic child.
            $parent = self::instances()->lockForUpdate()->find($identity->parentInstanceId);
            $child = self::instances()->lockForUpdate()->find($candidate->childInstanceId());
            if ($child !== null) {
                $state = self::read($child);
                $stored = ChildReservation::fromArray($state['reservation']);
                self::matching($child, $stored);
                if ($stored->identity->toArray() !== $identity->toArray()
                    || $stored->workflowClass !== $candidate->workflowClass
                    || $stored->definitionFingerprint !== $candidate->definitionFingerprint) {
                    throw new LogicException('An existing binding generation has a different immutable descriptor.');
                }
                return $stored;
            }
            $parentRun = ConfiguredV2Models::query('run_model', WorkflowRun::class)
                ->whereKey($identity->parentRunId)
                ->first();
            if ($parent === null || $parent->namespace !== $identity->namespace
                || $parent->current_run_id !== $identity->parentRunId
                || $parentRun === null || $parentRun->workflow_instance_id !== $parent->id
                || $parentRun->namespace !== $identity->namespace
                || $parentRun->status->isTerminal() || $parentRun->cancellation_requested_at !== null) {
                throw new LogicException('A new binding requires its active original parent run.');
            }
            /** @var WorkflowInstance $child */
            $child = self::instances()->create([
                'id' => $candidate->childInstanceId(),
                'workflow_class' => $candidate->workflowClass,
                'workflow_type' => $identity->childWorkflowType,
                'namespace' => $identity->namespace,
                'reserved_at' => now(),
                'run_count' => 0,
            ]);
            self::write($child, [
                'version' => 1,
                'reservation' => $candidate->toArray(),
                'status' => 'reserved',
                'admission' => null,
                'closureId' => null,
            ]);
            return $candidate;
        }, admission: static function () use ($identity): void {
            self::acquireWriter($identity->parentInstanceId);
        }, connection: $connection);
    }

    /**
     * Linearize against native admission. A lost acknowledgement returns the
     * same closed receipt or the exact admitted child, never lookup absence.
     */
    public function close(ChildReservation $reservation, string $principal): ChildBinding|NeverAdmittedChild
    {
        $this->authorize($reservation->identity, $principal, 'close');
        return SqliteTransaction::run(static function () use ($reservation): ChildBinding|NeverAdmittedChild {
            $child = self::instances()->lockForUpdate()->findOrFail($reservation->childInstanceId());
            $state = self::matching($child, $reservation);
            if ($state['status'] === 'admitted') {
                return ChildBinding::fromArray($state['admission']);
            }
            if ($state['status'] === 'reserved') {
                if ((int) $child->run_count !== 0 || $child->current_run_id !== null || $child->runs()->exists()) {
                    throw new LogicException('An uncertain child cannot be certified never admitted.');
                }
                $state['status'] = 'closed';
                $state['closureId'] = (string) Str::ulid();
                self::write($child, $state);
            }
            return new NeverAdmittedChild($reservation, $state['closureId']);
        }, admission: static function () use ($reservation): void {
            self::acquireWriter($reservation->childInstanceId());
        }, connection: self::instances()->getModel()->getConnection());
    }

    /**
     * @internal Native scheduler only, inside its existing root task transaction.
     */
    public static function lockForAdmission(WorkflowRun $parent, ChildWorkflowCall $call): WorkflowInstance
    {
        $reservation = $call->reservation;
        if ($reservation === null || $parent->getConnection()->transactionLevel() === 0
            || $parent->getConnection() !== self::instances()->getModel()->getConnection()) {
            throw new LogicException('A reserved child needs native transactional admission.');
        }
        $identity = $reservation->identity;
        // Check the actual executing parent before looking up the protected child.
        if ($parent->namespace !== $identity->namespace || $parent->workflow_instance_id !== $identity->parentInstanceId
            || $parent->id !== $identity->parentRunId || $call->workflow !== $reservation->workflowClass
            || TypeRegistry::for($call->workflow) !== $identity->childWorkflowType
            || WorkflowDefinition::fingerprint($call->workflow) !== $reservation->definitionFingerprint
            || ChildReservation::inputFingerprint($call->arguments) !== $identity->inputFingerprint
            || $call->options?->toSnapshot() !== $identity->childOptions->toSnapshot()) {
            throw new LogicException('The native child command differs from its reserved owner, definition or input.');
        }
        $child = self::instances()->lockForUpdate()->findOrFail($reservation->childInstanceId());
        $state = self::matching($child, $reservation);
        if ($state['status'] !== 'reserved' || (int) $child->run_count !== 0 || $child->current_run_id !== null
            || $child->runs()
                ->exists() || $parent->status->isTerminal()
            || $parent->cancellation_requested_at !== null) {
            throw new LogicException('The native child reservation is closed, already admitted or no longer eligible.');
        }
        return $child;
    }

    /**
     * @internal Store together with the child's native run, command and paired parent history.
     */
    public static function recordAdmission(
        WorkflowInstance $child,
        ChildReservation $reservation,
        StartedChildWorkflow $receipt
    ): void {
        if ($child->getConnection()->transactionLevel() === 0
            || $child->current_run_id !== $receipt->childRunId || (int) $child->run_count !== 1) {
            throw new LogicException('A native admission receipt must be committed with its original child run.');
        }
        $state = self::matching($child, $reservation);
        if ($state['status'] !== 'reserved' || $receipt->childInstanceId !== $child->id) {
            throw new LogicException('The reservation cannot record this native admission.');
        }
        $binding = new ChildBinding($reservation->identity, $receipt);
        $state['status'] = 'admitted';
        $state['admission'] = $binding->toArray();
        self::write($child, $state);
    }

    /**
     * @internal Ordinary start/redrive/signal-with-start must never bypass the native fence.
     */
    public static function assertOrdinaryStartAllowed(WorkflowInstance $instance): void
    {
        if ($instance->getRawOriginal('native_child_reservation') !== null) {
            throw new LogicException('A reserved native child can only start through its original parent command.');
        }
    }

    /**
     * @internal Symmetric replay check: adding or removing a reservation changes the command.
     */
    public static function assertRecordedCommand(?array $recorded, ?ChildReservation $reservation): void
    {
        $descriptor = $recorded === null ? null : ChildReservation::fromArray($recorded)->toArray();
        if ($descriptor !== $reservation?->toArray()) {
            throw new LogicException('The recorded native child reservation differs from this command.');
        }
    }

    private function authorize(BindingIdentity $identity, string $principal, string $operation): string
    {
        $version = $this->authorization->assertAllowed($identity, $principal, $operation);
        if (trim($version) === '' || strlen($version) > 128) {
            throw new LogicException('Binding authorization must identify its policy version.');
        }
        return $version;
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
        $query = self::instances();
        $query->whereKey($id)
            ->toBase()
            ->update([
                $query->getModel()
                    ->getKeyName() => $id,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function matching(WorkflowInstance $child, ChildReservation $reservation): array
    {
        $state = self::read($child);
        if ($state['reservation'] !== $reservation->toArray()
            || $child->id !== $reservation->childInstanceId()
            || $child->namespace !== $reservation->identity->namespace
            || $child->workflow_class !== $reservation->workflowClass
            || $child->workflow_type !== $reservation->identity->childWorkflowType) {
            throw new LogicException('The stored native child reservation does not match the original descriptor.');
        }
        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(WorkflowInstance $child): array
    {
        $raw = $child->getRawOriginal('native_child_reservation');
        if (! is_string($raw) || strlen($raw) > 32_768) {
            throw new LogicException('The native child has no valid reservation record.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($state) || count($state) !== 5 || ($state['version'] ?? null) !== 1
            || ! is_array($state['reservation'] ?? null)
            || ! array_key_exists('admission', $state) || ! array_key_exists('closureId', $state)
            || ! in_array($state['status'] ?? null, ['reserved', 'admitted', 'closed'], true)) {
            throw new LogicException('Malformed native child reservation state.');
        }
        $reservation = ChildReservation::fromArray($state['reservation']);
        // SQL JSON types may reorder object keys. Reconstruct the closed value
        // before comparing its immutable descriptor on every backend.
        $state['reservation'] = $reservation->toArray();
        if ($state['status'] === 'admitted') {
            if (! is_array($state['admission']) || $state['closureId'] !== null) {
                throw new LogicException('Malformed native child admission state.');
            }
            $binding = ChildBinding::fromArray($state['admission']);
            if (! $binding->matches($reservation->identity)
                || $binding->admission->childInstanceId !== $reservation->childInstanceId()) {
                throw new LogicException('Stored child admission belongs to another reservation.');
            }
            $state['admission'] = $binding->toArray();
        } elseif ($state['admission'] !== null || ($state['status'] === 'reserved' ? $state['closureId'] !== null
            : ! is_string($state['closureId']) || $state['closureId'] === '')) {
            throw new LogicException('Malformed native child closure state.');
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function write(WorkflowInstance $child, array $state): void
    {
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        self::instances()->whereKey($child->id)->toBase()->update([
            'native_child_reservation' => $encoded,
        ]);
        $child->setRawAttributes([
            ...$child->getAttributes(),
            'native_child_reservation' => $encoded,
        ], true);
    }
}
