<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use PDOStatement;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Provider-side stock receipts and read-only observations, separate from DW history. */
final readonly class CartInventory
{
    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $clock = null,
    ) {
        PortableDescriptor::identifier($client, 'provider client');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->inTransaction()) {
            throw new LogicException('Cart stock requires its own SQLite provider connection.');
        }
    }

    public function install(): void
    {
        $this->transaction(function (): void {
            $this->db->exec('CREATE TABLE provider_cart_stock (
                id TEXT PRIMARY KEY, client TEXT NOT NULL, sku TEXT NOT NULL, available INTEGER NOT NULL)');
            $this->db->exec('CREATE TABLE provider_cart_stock_receipts (
                id TEXT PRIMARY KEY, sku TEXT NOT NULL, quantity INTEGER NOT NULL)');
            $this->db->exec('CREATE TABLE provider_cart_roots (
                id TEXT PRIMARY KEY, owner TEXT NOT NULL, original_key TEXT NOT NULL)');
            $this->db->exec('CREATE TABLE provider_cart_reservations (
                id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, held INTEGER NOT NULL,
                released INTEGER NOT NULL, admission_closed INTEGER NOT NULL, successor TEXT NULL,
                fulfillment_key TEXT NULL)');
            $this->db->exec('CREATE TABLE provider_cart_fulfillments (
                reservation_id TEXT PRIMARY KEY, booking_id TEXT NOT NULL UNIQUE,
                descriptor TEXT NOT NULL, descriptor_sha256 TEXT NOT NULL)');
        });
    }

    /**
     * A zero receipt establishes known zero stock; a missing SKU establishes no availability fact.
     */
    public function receive(string $sku, string $receipt, int $quantity): void
    {
        PortableDescriptor::identifier($sku, 'stock SKU');
        PortableDescriptor::identifier($receipt, 'stock receipt');
        if ($quantity < 0) {
            throw new LogicException('A stock receipt cannot have negative quantity.');
        }
        $this->transaction(function () use ($sku, $receipt, $quantity): void {
            $id = hash('sha256', PortableDescriptor::encode([$this->client, $receipt]));
            $old = $this->query('SELECT * FROM provider_cart_stock_receipts WHERE id = ?', [$id])->fetch(
                PDO::FETCH_ASSOC
            );
            if ($old !== false) {
                if ($old['sku'] !== $sku || (int) $old['quantity'] !== $quantity) {
                    throw new LogicException('The original warehouse receipt changed its stock or quantity.');
                }
                return;
            }
            $stock = $this->query('SELECT available FROM provider_cart_stock WHERE id = ?', [
                self::stockKey($this->client, $sku),
            ])->fetchColumn();
            if ($stock !== false && (int) $stock > PHP_INT_MAX - $quantity) {
                throw new LogicException('The warehouse receipt exceeds the stock quantity range.');
            }
            $this->query('INSERT INTO provider_cart_stock_receipts (id, sku, quantity) VALUES (?, ?, ?)', [
                $id, $sku, $quantity,
            ]);
            $this->query('INSERT INTO provider_cart_stock (id, client, sku, available) VALUES (?, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET available = available + excluded.available', [
                self::stockKey($this->client, $sku), $this->client, $sku, $quantity,
            ]);
        });
    }

    public function inspect(CartReservationDescriptor $original): array
    {
        if ($original->reserve->identity->client !== $this->client) {
            throw new LogicException('The cart belongs to another provider client.');
        }
        return $this->transaction(fn (): array => (new CartReservationLedger(
            $this->db,
            $this->clock ?? static fn (): int => time()
        ))->inspect($original));
    }

    /**
     * Bind the original physical graph at checkout; the recorded host must authorize the paid purchase first.
     */
    public function bindFulfillment(CartReservationDescriptor $reservation, ShipmentDescriptor $shipment): void
    {
        if ($reservation->reserve->identity->client !== $this->client) {
            throw new LogicException('The cart belongs to another provider client.');
        }
        $this->transaction(function () use ($reservation, $shipment): void {
            (new CartReservationLedger($this->db, $this->clock ?? static fn (): int => time()))
                ->bindFulfillment($reservation, $shipment);
        });
    }

    public static function stockKey(string $client, string $sku): string
    {
        return hash('sha256', PortableDescriptor::encode([$client, $sku]));
    }

    private function query(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($bindings);
        return $statement;
    }

    private function transaction(Closure $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new LogicException('A caller transaction cannot contain provider stock work.');
        }
        $this->db->beginTransaction();
        try {
            $result = $operation();
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
