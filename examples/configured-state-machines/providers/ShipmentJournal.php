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
        private string $client
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
        return $this->transaction(fn (): array => (new ShipmentLedger($this->db))->record(
            $descriptor,
            $eventId,
            $kind,
            $quantity,
            $accepted,
            $saleable
        ));
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
