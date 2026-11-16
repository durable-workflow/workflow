<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Synthetic carrier facts; only the originally pinned atomic-close contract fences future collections. */
final readonly class CodJournal
{
    public const OPEN = 'cod-collection-open-v1';

    public const CLOSABLE = 'synthetic-cod-atomic-collection-close-v1';

    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $clock = null
    ) {
        PortableDescriptor::identifier($client, 'provider client');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->inTransaction()) {
            throw new LogicException('The synthetic carrier requires its own SQLite connection and transaction.');
        }
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
            $this->db->exec('CREATE TABLE IF NOT EXISTS cod_collection_contracts (
                payment_id TEXT PRIMARY KEY, descriptor_sha256 TEXT NOT NULL,
                contract TEXT NOT NULL, closed INTEGER NOT NULL DEFAULT 0)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS cod_collection_closures (
                payment_id TEXT PRIMARY KEY, descriptor_sha256 TEXT NOT NULL,
                contract TEXT NOT NULL, closed_at INTEGER NOT NULL, collections_sha256 TEXT NOT NULL)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS cod_receipts_by_payment ON cod_payment_receipts(payment_id)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS cod_anchors_by_payment ON cod_receipt_anchors(payment_id)');
        });
    }

    public function register(CodDescriptor $original, string $contract = self::OPEN): void
    {
        $this->authorize($original);
        $this->assertContract($contract);
        $this->transaction(fn () => $this->registerOriginal($original, $contract));
    }

    /**
     * A declared synthetic capability, including an original never-opened reference; ordinary expiry is no fence.
     * Remittance for an already collected payment remains admissible and visible after collection closes.
     */
    public function closeAdmission(CodDescriptor $original, string $contract): array
    {
        $this->authorize($original);
        if ($contract !== self::CLOSABLE) {
            throw new LogicException('The original carrier contract cannot fence future payer collections.');
        }
        return $this->transaction(function () use ($original, $contract): array {
            $this->registerOriginal($original, $contract);
            $this->closure($original);
            $collections = $this->collections($original);
            $hash = hash('sha256', PortableDescriptor::encode($collections));
            $this->query('INSERT INTO cod_collection_closures
                (payment_id, descriptor_sha256, contract, closed_at, collections_sha256)
                VALUES (?, ?, ?, ?, ?) ON CONFLICT(payment_id) DO NOTHING', [
                $original->identity->key(), $original->fingerprint(), $contract, $this->now(), $hash,
            ]);
            $this->query('UPDATE cod_collection_contracts SET closed = 1 WHERE payment_id = ?', [
                $original->identity->key(),
            ]);
            $closure = $this->closure($original);
            if ($closure === null || $closure['collections_sha256'] !== $hash) {
                throw new LogicException('The fenced original carrier collection inventory changed.');
            }
            return [
                'original' => $original->toArray(),
                'admissionContract' => $contract,
                'collectionAdmissionClosed' => true,
                'closedAt' => (int) $closure['closed_at'],
                'collections' => $collections,
                'collectionsSha256' => $hash,
                'evidence' => 'cod-collection-fenced:' . hash('sha256', PortableDescriptor::encode([
                    $original->identity->key(), $original->fingerprint(), $hash,
                ])),
            ];
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
            $closure = $this->closure($original);
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
            if ($receipt->kind === CodReceipt::COLLECTION && $closure !== null) {
                throw new LogicException('The original carrier reference rejects collection after its provider fence.');
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
            $closure = $this->closure($original);
            $snapshot = new CodSnapshot($original, $this->now(), $this->receipts($original));
            if ($closure !== null
                && $closure['collections_sha256'] !== hash(
                    'sha256',
                    PortableDescriptor::encode($this->collections($original))
                )) {
                throw new LogicException('The fenced original carrier collection inventory is incomplete.');
            }
            return [
                ...$snapshot->toArray(),
                'admissionContract' => $this->contract($original)['contract'],
                'collectionAdmissionClosed' => $closure !== null,
                'closedAt' => $closure === null ? null : (int) $closure['closed_at'],
            ];
        });
    }

    private function registerOriginal(CodDescriptor $original, string $contract): void
    {
        $key = $original->identity->key();
        $existing = $this->query(
            'SELECT * FROM cod_payment_references WHERE id = ? OR (client = ? AND reference = ?)',
            [$key, $this->client, $original->reference]
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($existing === []) {
            foreach ([
                'cod_payment_receipts',
                'cod_receipt_anchors',
                'cod_collection_contracts',
                'cod_collection_closures',
            ] as $table) {
                if ((int) $this->query(
                    'SELECT COUNT(*) FROM ' . $table . ' WHERE payment_id = ?',
                    [$key]
                )->fetchColumn() !== 0) {
                    throw new LogicException(
                        'A missing original carrier reference cannot adopt surviving provider facts.'
                    );
                }
            }
            $this->query('INSERT INTO cod_payment_references (id, client, reference, descriptor, descriptor_sha256)
                VALUES (?, ?, ?, ?, ?)', [$key, $this->client, $original->reference,
                PortableDescriptor::encode($original->toArray()), $original->fingerprint()]);
            $this->query('INSERT INTO cod_collection_contracts (payment_id, descriptor_sha256, contract)
                VALUES (?, ?, ?)', [$key, $original->fingerprint(), $contract]);
        } elseif (count($existing) !== 1) {
            throw new LogicException('The original carrier reference has conflicting owners.');
        }
        $this->original($original);
        if ($this->contract($original)['contract'] !== $contract) {
            throw new LogicException('The original carrier collection contract cannot be replaced.');
        }
        $this->closure($original);
    }

    private function assertContract(string $contract): void
    {
        if (! in_array($contract, [self::OPEN, self::CLOSABLE], true)) {
            throw new LogicException('The selected carrier collection contract is unavailable.');
        }
    }

    private function contract(CodDescriptor $original): array
    {
        $row = $this->query('SELECT * FROM cod_collection_contracts WHERE payment_id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['descriptor_sha256'] !== $original->fingerprint()
            || ! in_array($row['contract'], [self::OPEN, self::CLOSABLE], true)
            || ! in_array($row['closed'], [0, 1], true)) {
            throw new LogicException('The original carrier collection contract is unavailable or inconsistent.');
        }
        return $row;
    }

    private function closure(CodDescriptor $original): ?array
    {
        $contract = $this->contract($original);
        $row = $this->query('SELECT * FROM cod_collection_closures WHERE payment_id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            if ($contract['closed'] !== 0) {
                throw new LogicException('The original carrier collection fence is missing.');
            }
            return null;
        }
        if ($contract['closed'] !== 1 || $contract['contract'] !== self::CLOSABLE
            || $row['descriptor_sha256'] !== $original->fingerprint() || $row['contract'] !== self::CLOSABLE
            || ! is_int($row['closed_at']) || $row['closed_at'] < 0
            || preg_match('/\A[0-9a-f]{64}\z/D', $row['collections_sha256']) !== 1) {
            throw new LogicException('The original carrier collection fence is unavailable or inconsistent.');
        }
        return $row;
    }

    private function collections(CodDescriptor $original): array
    {
        $snapshot = new CodSnapshot($original, $this->now(), $this->receipts($original));
        return array_values(array_map(
            static fn (CodReceipt $receipt): array => $receipt->toArray(),
            array_filter(
                $snapshot->receipts,
                static fn (CodReceipt $receipt): bool => $receipt->kind === CodReceipt::COLLECTION
            )
        ));
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
