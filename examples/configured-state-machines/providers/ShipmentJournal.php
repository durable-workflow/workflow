<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Throwable;

/** Optional physical provider capability; each call owns its provider transaction. */
final readonly class ShipmentJournal
{
    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $beforeEvent = null
    ) {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->inTransaction()) {
            throw new LogicException('The physical fixture requires its own SQLite connection and transaction.');
        }
    }

    public function install(): void
    {
        $this->transaction(fn () => (new ShipmentLedger($this->db))->install());
    }

    /**
     * Pin the complete original graph before admitting any of its physical operations.
     */
    public function register(ShipmentDescriptor $descriptor): void
    {
        $this->authorize($descriptor);
        $this->transaction(fn () => (new ShipmentLedger($this->db))->register($descriptor));
    }

    /**
     * Synthetic carrier/warehouse fixture input, never an application command or workflow signal.
     */
    public function record(
        ShipmentDescriptor $descriptor,
        string $eventId,
        string $kind,
        int $quantity,
        int $accepted = 0,
        int $saleable = 0
    ): array {
        $this->authorize($descriptor);
        return $this->transaction(function () use (
            $descriptor,
            $eventId,
            $kind,
            $quantity,
            $accepted,
            $saleable
        ): array {
            ($this->beforeEvent)?->__invoke($descriptor, $kind);
            return (new ShipmentLedger($this->db))->record(
                $descriptor,
                $eventId,
                $kind,
                $quantity,
                $accepted,
                $saleable
            );
        });
    }

    /**
     * Invoke through a recorded, original-owner-authorized host activity before exposing facts to workflow code.
     */
    public function inspect(ShipmentDescriptor $descriptor): ShipmentSnapshot
    {
        $this->authorize($descriptor);
        return $this->transaction(function () use ($descriptor): ShipmentSnapshot {
            $ledger = new ShipmentLedger($this->db);
            $ledger->assertDescriptor($descriptor);
            $observations = [];
            foreach ($descriptor->requests() as $key => $request) {
                $observations[$key] = $ledger->observe($request);
            }
            $events = $ledger->events($descriptor->booking);
            $request = $this->db->prepare('INSERT INTO provider_requests (operation_id, kind) VALUES (?, ?)');
            $request->execute([$descriptor->booking->identity->key(), 'shipment-inspection']);
            return new ShipmentSnapshot($descriptor, $observations, $events);
        });
    }

    /**
     * Synthetic warehouse receipt/inspection of one customer unit return; never an application command.
     */
    public function recordUnitReturn(
        Request $return,
        string $eventId,
        string $kind,
        array $units,
        array $accepted = [],
        array $saleable = []
    ): array {
        $this->authorizeRequest($return);
        return $this->transaction(fn (): array => (new ShipmentLedger($this->db))->units()->record(
            $return,
            $eventId,
            $kind,
            $units,
            $accepted,
            $saleable
        ));
    }

    /**
     * Invoke through a recorded, return-owner-authorized host activity before exposing facts to workflow code.
     */
    public function inspectUnitReturn(Request $return, ?Request $restock = null): array
    {
        $this->authorizeRequest($return);
        if ($restock !== null) {
            $this->authorizeRequest($restock);
        }
        return $this->transaction(function () use ($return, $restock): array {
            $ledger = new ShipmentLedger($this->db);
            if ($restock !== null && $restock->target?->toArray() !== $return->identity->toArray()) {
                throw new LogicException('Unit restocking evidence belongs to another return.');
            }
            $observations = [
                'return' => $ledger->observe($return),
            ];
            if ($restock !== null) {
                $observations['restock'] = $ledger->observe($restock);
            }
            $confirmed = static fn (string $key): bool => isset($observations[$key])
                && $observations[$key]->status === Status::Confirmed && $observations[$key]->effectId !== null;
            $events = $confirmed('return') ? $ledger->units()
                ->events($return) : [];
            $request = $this->db->prepare('INSERT INTO provider_requests (operation_id, kind) VALUES (?, ?)');
            $request->execute([$return->identity->key(), 'unit-return-inspection']);
            return [
                'observations' => array_map(static fn (Observation $value): array => [
                    'status' => $value->status->value,
                    'effectId' => $value->effectId,
                    'admissionClosed' => $value->admissionClosed,
                ], $observations),
                'events' => $events,
                'facts' => [
                    'return_requested' => $confirmed('return'),
                    'requested_units' => UnitReturnTerms::fromArray($return->terms)->units,
                    'received_units' => $events['received']['units'] ?? [],
                    'warehouse_inspected' => isset($events['inspected']),
                    'accepted_units' => $events['inspected']['accepted'] ?? [],
                    'saleable_units' => $events['inspected']['saleable'] ?? [],
                    'restocked_units' => $confirmed('restock') ? UnitReturnTerms::fromArray(
                        $restock->terms
                    )->units : [],
                ],
            ];
        });
    }

    /**
     * Synthetic carrier decision on a filed claim; never an application command or workflow signal.
     */
    public function recordClaimOutcome(Request $claim, string $outcome, int $amountMinor): array
    {
        $this->authorizeRequest($claim);
        return $this->transaction(fn (): array => (new ShipmentLedger($this->db))->recordClaimOutcome(
            $claim,
            $outcome,
            $amountMinor
        ));
    }

    /**
     * Invoke through an authorized host boundary; the carrier decision may arrive at any later time.
     */
    public function claimOutcome(Request $claim): ?array
    {
        $this->authorizeRequest($claim);
        return $this->transaction(fn (): ?array => (new ShipmentLedger($this->db))->claimOutcome($claim));
    }

    private function authorizeRequest(Request $request): void
    {
        if ($request->identity->client !== $this->client) {
            throw new LogicException('The provider credential belongs to another client.');
        }
    }

    private function authorize(ShipmentDescriptor $descriptor): void
    {
        foreach ($descriptor->requests() as $request) {
            if ($request->identity->client !== $this->client) {
                throw new LogicException('The provider credential belongs to another client.');
            }
        }
    }

    private function transaction(Closure $action): mixed
    {
        if ($this->db->inTransaction()) {
            throw new LogicException('A caller transaction cannot contain the physical provider transaction.');
        }
        $this->db->beginTransaction();
        try {
            $result = $action();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
