<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Atomic synthetic carrier ingestion order. Both callbacks execute inside the same provider-owned transaction. */
final readonly class CodCarrierContract
{
    public function __construct(
        private PDO $db,
        private Closure $clock
    ) {
    }

    public function install(): void
    {
        $this->transaction();
        if ((int) $this->db->query('SELECT COUNT(*) FROM cod_payment_references')->fetchColumn() !== 0) {
            throw new LogicException(
                'Physical COD support cannot be installed over existing unfenced carrier references.'
            );
        }
        $this->db->exec('CREATE TABLE cod_carrier_contracts (
            payment_id TEXT PRIMARY KEY, booking_id TEXT NOT NULL UNIQUE,
            descriptor TEXT NOT NULL, descriptor_sha256 TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE cod_carrier_collections (
            id TEXT PRIMARY KEY, payment_id TEXT NOT NULL, receipt_sha256 TEXT NOT NULL, phase TEXT NOT NULL)');
    }

    public function register(CodDescriptor $original, bool $fresh): void
    {
        $this->transaction();
        $row = $this->query('SELECT * FROM cod_carrier_contracts WHERE payment_id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($fresh) {
            if ($row !== false) {
                throw new LogicException('A new carrier reference cannot adopt a surviving physical binding.');
            }
            $this->query('INSERT INTO cod_carrier_contracts (payment_id, booking_id, descriptor, descriptor_sha256)
                VALUES (?, ?, ?, ?)', [$original->identity->key(), $original->shipment->booking->identity->key(),
                PortableDescriptor::encode($original->toArray()), $original->fingerprint()]);
        }
        $this->binding($original);
    }

    public function collect(CodDescriptor $original, CodReceipt $receipt): void
    {
        $this->transaction();
        $this->binding($original);
        $physical = new ShipmentLedger($this->db);
        $physical->assertDescriptor($original->shipment);
        $booking = $physical->observe($original->shipment->booking);
        $events = $physical->events($original->shipment->booking);
        $delivered = isset($events['delivered']);
        if ($booking->status !== Status::Confirmed
            || $booking->effectId !== 'effect-' . $original->shipment->booking->identity->key()
            || ! isset($events['handed-over'])
            || $delivered !== ($original->handoverPolicy === CodDescriptor::AFTER)) {
            throw new LogicException(
                'The original carrier refuses collection outside its pinned recipient-handover order.'
            );
        }
        $this->query('INSERT INTO cod_carrier_collections (id, payment_id, receipt_sha256, phase)
            VALUES (?, ?, ?, ?)', [$this->receiptId($original, $receipt->receipt), $original->identity->key(),
            hash('sha256', PortableDescriptor::encode($receipt->toArray())), $original->handoverPolicy]);
    }

    public function beforePhysical(ShipmentDescriptor $shipment, string $kind): void
    {
        $this->transaction();
        if ($kind !== 'delivered') {
            return;
        }
        $row = $this->query('SELECT * FROM cod_carrier_contracts WHERE booking_id = ?', [
            $shipment->booking->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $original = CodDescriptor::fromArray(json_decode($row['descriptor'], true, flags: JSON_THROW_ON_ERROR));
        $this->binding($original);
        if ($original->shipment->fingerprint() !== $shipment->fingerprint()) {
            throw new LogicException('Recipient handover substituted its original COD Shipment graph.');
        }
        if ($original->handoverPolicy === CodDescriptor::BEFORE) {
            [$receipts] = $this->collections($original);
            $now = ($this->clock)();
            if (! is_int($now) || $now < 0) {
                throw new LogicException('The original carrier observation clock is unavailable.');
            }
            $snapshot = new CodSnapshot($original, $now, $receipts);
            $onTime = 0;
            foreach ($receipts as $receipt) {
                if ($receipt->occurredAt < $original->collectionDeadline) {
                    $onTime += $receipt->amountMinor;
                }
            }
            if (! $snapshot->facts()['payer_collection_confirmed'] || $onTime < $original->amountMinor) {
                throw new LogicException('Recipient handover requires the original complete on-time payer collection.');
            }
        }
    }

    /**
     * Evidence for a recorded host observation; never a signal-supplied permission or a merchant-settlement claim.
     */
    public function inspect(CodDescriptor $original): array
    {
        $this->transaction();
        $this->binding($original);
        [, $proofs] = $this->collections($original);
        return [
            'originalFingerprint' => $original->fingerprint(),
            'handoverPolicy' => $original->handoverPolicy,
            'collections' => $proofs,
        ];
    }

    private function binding(CodDescriptor $original): void
    {
        $row = $this->query('SELECT * FROM cod_carrier_contracts WHERE payment_id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        $reference = $this->query('SELECT * FROM cod_payment_references WHERE id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        $payload = PortableDescriptor::encode($original->toArray());
        if ($row === false || $reference === false || $row['booking_id'] !== $original->shipment->booking->identity->key()
            || $row['descriptor'] !== $payload || $row['descriptor_sha256'] !== $original->fingerprint()
            || $reference['descriptor'] !== $payload || $reference['descriptor_sha256'] !== $original->fingerprint()
            || $reference['client'] !== $original->identity->client || $reference['reference'] !== $original->reference) {
            throw new LogicException('The original physical COD binding is missing or changed.');
        }
    }

    /**
     * @return array{list<CodReceipt>, list<array>}
     */
    private function collections(CodDescriptor $original): array
    {
        $rows = $this->query('SELECT r.*, p.receipt_sha256 AS phase_hash, p.phase, p.payment_id AS phase_payment,
            a.payload_sha256 AS anchor_hash, a.payment_id AS anchor_payment
            FROM cod_payment_receipts r LEFT JOIN cod_carrier_collections p ON p.id = r.id
            LEFT JOIN cod_receipt_anchors a ON a.id = r.id
            WHERE r.payment_id = ? AND r.kind = ? ORDER BY r.receipt_id LIMIT 129', [
            $original->identity->key(), CodReceipt::COLLECTION,
        ])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 128) {
            throw new LogicException('The original carrier collection proof inventory is incomplete.');
        }
        $anchored = (int) $this->query('SELECT COUNT(*) FROM cod_carrier_collections WHERE payment_id = ?', [
            $original->identity->key(),
        ])->fetchColumn();
        if ($anchored !== count($rows)) {
            throw new LogicException('The carrier collection inventory lost original physical admission evidence.');
        }
        $receipts = [];
        $proofs = [];
        foreach ($rows as $row) {
            $hash = hash('sha256', $row['payload']);
            $receipt = CodReceipt::fromArray(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR));
            if ($row['client'] !== $original->identity->client || $row['id'] !== $this->receiptId(
                $original,
                $receipt->receipt
            )
                || $row['receipt_id'] !== $receipt->receipt || $receipt->kind !== CodReceipt::COLLECTION
                || $receipt->payment !== $original->identity->key() || $receipt->currency !== $original->currency
                || $receipt->target !== $original->collectionPoint || $row['phase'] !== $original->handoverPolicy
                || $row['payload_sha256'] !== $hash || $row['phase_hash'] !== $hash || $row['anchor_hash'] !== $hash
                || $row['anchor_payment'] !== $original->identity->key()
                || $row['phase_payment'] !== $original->identity->key()) {
                throw new LogicException('The original collection lost its carrier order, owner or content evidence.');
            }
            $receipts[] = $receipt;
            $proofs[] = [
                'receipt' => $receipt->receipt,
                'sha256' => $hash,
                'phase' => $row['phase'],
            ];
        }
        return [$receipts, $proofs];
    }

    private function receiptId(CodDescriptor $original, string $receipt): string
    {
        return hash(
            'sha256',
            PortableDescriptor::encode([$original->identity->client, CodReceipt::COLLECTION, $receipt])
        );
    }

    private function query(string $sql, array $values): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query;
    }

    private function transaction(): void
    {
        if (! $this->db->inTransaction()) {
            throw new LogicException('Carrier ordering requires its original provider-owned transaction.');
        }
    }
}
