<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Synthetic original carrier/merchant facts. This open journal supplies no cancellation or nonexecution fence. */
final readonly class CodJournal
{
    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $clock = null
    ) {
        PortableDescriptor::identifier($client, 'provider client');
    }

    public function install(): void
    {
        $this->transaction(function (): void {
            $this->db->exec('CREATE TABLE IF NOT EXISTS cod_payment_references (
                id TEXT PRIMARY KEY, client TEXT NOT NULL, reference TEXT NOT NULL,
                descriptor TEXT NOT NULL, descriptor_sha256 TEXT NOT NULL, UNIQUE(client, reference))');
            $this->db->exec('CREATE TABLE IF NOT EXISTS cod_payment_receipts (
                id TEXT PRIMARY KEY, client TEXT NOT NULL, payment_id TEXT NOT NULL, kind TEXT NOT NULL,
                receipt_id TEXT NOT NULL, payload TEXT NOT NULL, payload_sha256 TEXT NOT NULL,
                UNIQUE(client, kind, receipt_id))');
            $this->db->exec('CREATE TABLE IF NOT EXISTS cod_receipt_anchors (
                id TEXT PRIMARY KEY, payment_id TEXT NOT NULL, payload_sha256 TEXT NOT NULL)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS cod_receipts_by_payment ON cod_payment_receipts(payment_id)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS cod_anchors_by_payment ON cod_receipt_anchors(payment_id)');
        });
    }

    public function register(CodDescriptor $original): void
    {
        $this->authorize($original);
        $this->transaction(function () use ($original): void {
            $existing = $this->query(
                'SELECT * FROM cod_payment_references WHERE id = ? OR (client = ? AND reference = ?)',
                [$original->identity->key(), $this->client, $original->reference]
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($existing === []) {
                $this->query('INSERT INTO cod_payment_references (id, client, reference, descriptor, descriptor_sha256)
                    VALUES (?, ?, ?, ?, ?)', [$original->identity->key(), $this->client, $original->reference,
                    PortableDescriptor::encode($original->toArray()), $original->fingerprint()]);
            }
            $this->original($original);
        });
    }

    /**
     * Fixture-side authoritative observation, never a workflow signal or a command to collect money.
     */
    public function record(CodDescriptor $original, CodReceipt $receipt): array
    {
        $this->authorize($original);
        return $this->transaction(function () use ($original, $receipt): array {
            $this->original($original);
            $now = $this->now();
            if ($receipt->payment !== $original->identity->key() || $receipt->currency !== $original->currency
                || $receipt->occurredAt > $now) {
                throw new LogicException('The carrier fact is outside its original payment/currency/time boundary.');
            }
            $this->assertTarget($original, $receipt);
            $id = $this->id($receipt);
            $payload = PortableDescriptor::encode($receipt->toArray());
            $old = $this->query('SELECT * FROM cod_payment_receipts WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
            if ($old !== false) {
                if ($this->decode($old, $original)->toArray() !== $receipt->toArray()) {
                    throw new LogicException('An original carrier or merchant receipt changed content.');
                }
                return $receipt->toArray();
            }
            $known = $this->receipts($original);
            // Keep overflow facts, while inspect() refuses an incomplete bounded inventory.
            if (count($known) < 128) {
                new CodSnapshot($original, $now, [...$known, $receipt]);
            }
            $hash = hash('sha256', $payload);
            $this->query(
                'INSERT INTO cod_payment_receipts
                (id, client, payment_id, kind, receipt_id, payload, payload_sha256) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $this->client, $receipt->payment, $receipt->kind, $receipt->receipt, $payload, $hash]
            );
            $this->query(
                'INSERT INTO cod_receipt_anchors (id, payment_id, payload_sha256) VALUES (?, ?, ?)',
                [$id, $receipt->payment, $hash]
            );
            return $receipt->toArray();
        });
    }

    public function inspect(CodDescriptor $original): array
    {
        $this->authorize($original);
        return $this->transaction(function () use ($original): array {
            $this->original($original);
            return (new CodSnapshot($original, $this->now(), $this->receipts($original)))->toArray();
        });
    }

    private function receipts(CodDescriptor $original): array
    {
        $key = $original->identity->key();
        $rows = $this->query(
            'SELECT * FROM cod_payment_receipts WHERE payment_id = ? ORDER BY kind, receipt_id LIMIT 129',
            [$key]
        )->fetchAll(PDO::FETCH_ASSOC);
        $anchors = (int) $this->query(
            'SELECT COUNT(*) FROM cod_receipt_anchors WHERE payment_id = ?',
            [$key]
        )->fetchColumn();
        if ($anchors !== count($rows) && count($rows) <= 128) {
            throw new LogicException('The original COD receipt inventory is missing anchored facts.');
        }
        return array_map(fn (array $row): CodReceipt => $this->decode($row, $original), $rows);
    }

    private function decode(array $row, CodDescriptor $original): CodReceipt
    {
        $anchor = $this->query('SELECT * FROM cod_receipt_anchors WHERE id = ?', [$row['id']])->fetch(PDO::FETCH_ASSOC);
        $hash = hash('sha256', $row['payload']);
        if ($anchor === false || $anchor['payment_id'] !== $original->identity->key()
            || $anchor['payload_sha256'] !== $hash || $row['payload_sha256'] !== $hash) {
            throw new LogicException('The original COD receipt lost its immutable content anchor.');
        }
        $receipt = CodReceipt::fromArray(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR));
        if ($row['id'] !== $this->id($receipt) || $row['client'] !== $this->client
            || $row['payment_id'] !== $original->identity->key() || $receipt->payment !== $row['payment_id']
            || $row['kind'] !== $receipt->kind || $row['receipt_id'] !== $receipt->receipt) {
            throw new LogicException('The original COD receipt owner or indexed identity was substituted.');
        }
        return $receipt;
    }

    private function original(CodDescriptor $original): void
    {
        $row = $this->query('SELECT * FROM cod_payment_references WHERE id = ?', [$original->identity->key()])->fetch(
            PDO::FETCH_ASSOC
        );
        if ($row === false || $row['client'] !== $this->client || $row['reference'] !== $original->reference
            || $row['descriptor_sha256'] !== $original->fingerprint()
            || hash('sha256', $row['descriptor']) !== $original->fingerprint()) {
            throw new LogicException('The original COD reference is unavailable or changed.');
        }
    }

    private function assertTarget(CodDescriptor $original, CodReceipt $receipt): void
    {
        if ($receipt->kind === CodReceipt::COLLECTION) {
            if ($receipt->target !== $original->collectionPoint) {
                throw new LogicException('Carrier collection belongs to another original collection point.');
            }
        } else {
            $id = hash('sha256', PortableDescriptor::encode([$this->client, CodReceipt::COLLECTION, $receipt->target]));
            $row = $this->query('SELECT * FROM cod_payment_receipts WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $this->decode($row, $original)->occurredAt > $receipt->occurredAt) {
                throw new LogicException('Merchant remittance has no original preceding payer collection.');
            }
        }
    }

    private function authorize(CodDescriptor $original): void
    {
        if ($original->identity->client !== $this->client) {
            throw new LogicException('The carrier provider credential belongs to another client.');
        }
    }

    private function id(CodReceipt $receipt): string
    {
        return hash('sha256', PortableDescriptor::encode([$this->client, $receipt->kind, $receipt->receipt]));
    }

    private function now(): int
    {
        $now = $this->clock?->__invoke() ?? time();
        if (! is_int($now) || $now < 0) {
            throw new LogicException('The carrier provider clock must return an exact nonnegative Unix timestamp.');
        }
        return $now;
    }

    private function query(string $sql, array $values): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query;
    }

    private function transaction(Closure $action): mixed
    {
        if ($this->db->inTransaction()) {
            throw new LogicException('A caller transaction cannot contain the carrier provider transaction.');
        }
        $this->db->beginTransaction();
        try {
            $value = $action();
            $this->db->commit();
            return $value;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
