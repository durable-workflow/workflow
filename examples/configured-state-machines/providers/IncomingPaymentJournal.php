<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Throwable;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Synthetic bank facts. Only an originally pinned closable reference can reject future transfers. */
final readonly class IncomingPaymentJournal
{
    public const OPEN = 'incoming-unfenced-v1';

    public const CLOSABLE = 'synthetic-incoming-atomic-close-v1';

    public function __construct(
        private PDO $db,
        private string $client,
        private ?Closure $clock = null,
    ) {
        PortableDescriptor::identifier($client, 'provider client');
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || $db->inTransaction()) {
            throw new LogicException('The incoming-payment fixture requires its own SQLite transaction.');
        }
    }

    public function install(): void
    {
        $this->transaction(function (): void {
            $this->db->exec('CREATE TABLE IF NOT EXISTS incoming_payment_references (
                id TEXT PRIMARY KEY, client TEXT NOT NULL, reference TEXT NOT NULL,
                fingerprint TEXT NOT NULL, payload TEXT NOT NULL, UNIQUE(client, reference))');
            $this->db->exec('CREATE TABLE IF NOT EXISTS incoming_payment_receipts (
                id TEXT PRIMARY KEY, client TEXT NOT NULL, reference_id TEXT NOT NULL,
                transfer_id TEXT NOT NULL, received_at INTEGER NOT NULL,
                fingerprint TEXT NOT NULL, payload TEXT NOT NULL, UNIQUE(client, transfer_id))');
            $this->db->exec('CREATE TABLE IF NOT EXISTS incoming_payment_contracts (
                reference_id TEXT PRIMARY KEY, descriptor_sha256 TEXT NOT NULL,
                contract TEXT NOT NULL, closed INTEGER NOT NULL DEFAULT 0)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS incoming_payment_closures (
                reference_id TEXT PRIMARY KEY, descriptor_sha256 TEXT NOT NULL,
                contract TEXT NOT NULL, closed_at INTEGER NOT NULL, receipts_sha256 TEXT NOT NULL)');
        });
    }

    public function register(IncomingPaymentDescriptor $descriptor, string $contract = self::OPEN): void
    {
        $this->authorize($descriptor);
        $this->assertContract($contract);
        $this->transaction(fn () => $this->registerOriginal($descriptor, $contract));
    }

    /**
     * Atomic provider fence, including a never-opened reference. This is not ordinary bank expiry.
     */
    public function closeAdmission(IncomingPaymentDescriptor $descriptor, string $contract): array
    {
        $this->authorize($descriptor);
        if ($contract !== self::CLOSABLE) {
            throw new LogicException('The original incoming contract cannot fence future transfers.');
        }
        return $this->transaction(function () use ($descriptor, $contract): array {
            $this->registerOriginal($descriptor, $contract);
            // A retained closed contract may not reconstruct a lost authoritative fence.
            $this->closure($descriptor);
            $receipts = $this->receipts($descriptor);
            $hash = hash('sha256', PortableDescriptor::encode($receipts));
            $this->query('INSERT INTO incoming_payment_closures
                (reference_id, descriptor_sha256, contract, closed_at, receipts_sha256)
                VALUES (?, ?, ?, ?, ?) ON CONFLICT(reference_id) DO NOTHING', [
                $descriptor->identity->key(), $descriptor->fingerprint(), $contract, $this->now(), $hash,
            ]);
            $this->query('UPDATE incoming_payment_contracts SET closed = 1 WHERE reference_id = ?', [
                $descriptor->identity->key(),
            ]);
            $row = $this->closure($descriptor);
            if ($row === null || $row['receipts_sha256'] !== $hash) {
                throw new LogicException('The fenced incoming receipt inventory changed or is incomplete.');
            }
            return [
                'original' => $descriptor->toArray(),
                'admissionContract' => $contract,
                'admissionClosed' => true,
                'closedAt' => (int) $row['closed_at'],
                'receipts' => $receipts,
                'receiptsSha256' => $hash,
                'evidence' => 'incoming-fenced:' . hash('sha256', PortableDescriptor::encode([
                    $descriptor->identity->key(), $hash,
                ])),
            ];
        });
    }

    /**
     * Fixture-side bank input, not a workflow instruction; late original receipts must remain visible.
     */
    public function record(IncomingPaymentDescriptor $descriptor, IncomingPaymentReceipt $receipt): array
    {
        $this->authorize($descriptor);
        return $this->transaction(function () use ($descriptor, $receipt): array {
            $this->original($descriptor);
            $this->assertReceipt($descriptor, $receipt);
            if ($receipt->receivedAt > $this->now()) {
                throw new LogicException('An incoming receipt cannot claim a future provider observation.');
            }
            $id = hash('sha256', PortableDescriptor::encode([$this->client, $receipt->transfer]));
            $payload = PortableDescriptor::encode($receipt->toArray());
            $hash = hash('sha256', $payload);
            $existing = $this->query('SELECT * FROM incoming_payment_receipts WHERE id = ?', [$id])
                ->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                if ($existing['client'] !== $this->client || $existing['reference_id'] !== $receipt->receivable
                    || $existing['transfer_id'] !== $receipt->transfer || (int) $existing['received_at'] !== $receipt->receivedAt
                    || $existing['payload'] !== $payload || ! hash_equals($hash, $existing['fingerprint'])) {
                    throw new LogicException('The original incoming transfer changed its owner, money or evidence.');
                }
                return $receipt->toArray();
            }
            if ($this->closure($descriptor) !== null) {
                throw new LogicException(
                    'The original incoming reference rejects new transfers after its provider fence.'
                );
            }
            $this->query('INSERT INTO incoming_payment_receipts
                (id, client, reference_id, transfer_id, received_at, fingerprint, payload) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                    $id,
                $this->client,
                $receipt->receivable,
                $receipt->transfer,
                $receipt->receivedAt,
                $hash,
                $payload,
                ]);
            return $receipt->toArray();
        });
    }

    /**
     * A host must record this snapshot before pure workflow rules consume it; absence proves no non-execution.
     */
    public function inspect(IncomingPaymentDescriptor $descriptor): array
    {
        $this->authorize($descriptor);
        return $this->transaction(function () use ($descriptor): array {
            $this->original($descriptor);
            $receipts = $this->receipts($descriptor);
            $total = 0;
            $onTime = 0;
            foreach ($receipts as $value) {
                $receipt = IncomingPaymentReceipt::fromArray($value);
                if ($receipt->amountMinor > PHP_INT_MAX - $total) {
                    throw new LogicException('Incoming money exceeds the exact integer observation range.');
                }
                $total += $receipt->amountMinor;
                if ($receipt->receivedAt < $descriptor->deadline) {
                    $onTime += $receipt->amountMinor;
                }
            }
            $now = $this->now();
            return [
                'original' => $descriptor->toArray(),
                'observedAt' => $now,
                'deadlinePassed' => $now >= $descriptor->deadline,
                'receipts' => $receipts,
                'receivedAmountMinor' => $total,
                'receivedBeforeDeadlineMinor' => $onTime,
                'fullAmountReceived' => $total >= $descriptor->amountMinor,
                'requiredAmountReceivedBeforeDeadline' => $onTime >= $descriptor->amountMinor,
                'remainingAmountMinor' => max(0, $descriptor->amountMinor - $total),
                'overpaymentAmountMinor' => max(0, $total - $descriptor->amountMinor),
                // No expiry or lookup absence closes future incoming-payment admission.
                'nonExecutionEstablished' => false,
            ];
        });
    }

    private function registerOriginal(IncomingPaymentDescriptor $descriptor, string $contract): void
    {
        $existing = $this->query('SELECT * FROM incoming_payment_references
                WHERE id = ? OR (client = ? AND reference = ?)', [
            $descriptor->identity->key(), $this->client, $descriptor->reference,
        ])->fetchAll(PDO::FETCH_ASSOC);
        if ($existing !== []) {
            if (count($existing) !== 1) {
                throw new LogicException('The incoming reference has conflicting original owners.');
            }
            $this->assertDescriptor($descriptor, $existing[0]);
            $this->originalContract($descriptor, $contract);
            return;
        }
        $this->query('INSERT INTO incoming_payment_references
                (id, client, reference, fingerprint, payload) VALUES (?, ?, ?, ?, ?)', [
            $descriptor->identity->key(), $this->client, $descriptor->reference,
            $descriptor->fingerprint(), PortableDescriptor::encode($descriptor->toArray()),
        ]);
        $this->query('INSERT INTO incoming_payment_contracts
            (reference_id, descriptor_sha256, contract) VALUES (?, ?, ?)', [
            $descriptor->identity->key(), $descriptor->fingerprint(), $contract,
        ]);
    }

    private function receipts(IncomingPaymentDescriptor $descriptor): array
    {
        $rows = $this->query('SELECT * FROM incoming_payment_receipts
            WHERE reference_id = ? ORDER BY received_at, id LIMIT 65', [$descriptor->identity->key()])
            ->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 64) {
            throw new LogicException('The bounded incoming-payment snapshot is incomplete and remains unresolved.');
        }
        return array_map(fn (array $row): array => $this->readReceipt($descriptor, $row)->toArray(), $rows);
    }

    private function assertContract(string $contract): void
    {
        if (! in_array($contract, [self::OPEN, self::CLOSABLE], true)) {
            throw new LogicException('The incoming provider contract is unavailable.');
        }
    }

    private function originalContract(IncomingPaymentDescriptor $descriptor, string $contract): array
    {
        $row = $this->query('SELECT * FROM incoming_payment_contracts WHERE reference_id = ?', [
            $descriptor->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['descriptor_sha256'] !== $descriptor->fingerprint() || $row['contract'] !== $contract) {
            throw new LogicException('The original incoming admission contract cannot be replaced or reconstructed.');
        }
        return $row;
    }

    private function closure(IncomingPaymentDescriptor $descriptor): ?array
    {
        $contract = $this->query('SELECT * FROM incoming_payment_contracts WHERE reference_id = ?', [
            $descriptor->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($contract === false || $contract['descriptor_sha256'] !== $descriptor->fingerprint()
            || ! in_array($contract['contract'], [self::OPEN, self::CLOSABLE], true)
            || ! in_array($contract['closed'], [0, 1], true)) {
            throw new LogicException('The original incoming provider contract is unavailable or inconsistent.');
        }
        $row = $this->query('SELECT * FROM incoming_payment_closures WHERE reference_id = ?', [
            $descriptor->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            if ($contract['closed'] !== 0) {
                throw new LogicException('The original incoming provider fence is missing.');
            }
            return null;
        }
        $this->originalContract($descriptor, self::CLOSABLE);
        if ($contract['closed'] !== 1 || $row['descriptor_sha256'] !== $descriptor->fingerprint() || $row['contract'] !== self::CLOSABLE
            || (int) $row['closed_at'] < 0 || (int) $row['closed_at'] > $this->now()) {
            throw new LogicException('The original incoming provider fence was substituted.');
        }
        return $row;
    }

    private function authorize(IncomingPaymentDescriptor $descriptor): void
    {
        if ($descriptor->identity->client !== $this->client) {
            throw new LogicException('The incoming-payment credential belongs to another client.');
        }
    }

    private function original(IncomingPaymentDescriptor $descriptor): void
    {
        $row = $this->query('SELECT * FROM incoming_payment_references WHERE id = ?', [
            $descriptor->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new LogicException('The original incoming-payment reference is unavailable.');
        }
        $this->assertDescriptor($descriptor, $row);
    }

    private function assertDescriptor(IncomingPaymentDescriptor $descriptor, array $row): void
    {
        if ($row['id'] !== $descriptor->identity->key() || $row['client'] !== $this->client
            || $row['reference'] !== $descriptor->reference || ! hash_equals(
                $descriptor->fingerprint(),
                $row['fingerprint']
            )
            || $row['payload'] !== PortableDescriptor::encode($descriptor->toArray())) {
            throw new LogicException('The original incoming-payment identity, reference or terms were substituted.');
        }
    }

    private function assertReceipt(IncomingPaymentDescriptor $descriptor, IncomingPaymentReceipt $receipt): void
    {
        if ($receipt->receivable !== $descriptor->identity->key() || $receipt->currency !== $descriptor->currency) {
            throw new LogicException('The incoming receipt targets another original receivable or currency.');
        }
    }

    private function readReceipt(IncomingPaymentDescriptor $descriptor, array $row): IncomingPaymentReceipt
    {
        if (! hash_equals(hash('sha256', $row['payload']), $row['fingerprint'])) {
            throw new LogicException('The original incoming receipt lost its evidence integrity.');
        }
        $receipt = IncomingPaymentReceipt::fromArray(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertReceipt($descriptor, $receipt);
        $id = hash('sha256', PortableDescriptor::encode([$this->client, $receipt->transfer]));
        if ($row['id'] !== $id || $row['client'] !== $this->client || $row['reference_id'] !== $receipt->receivable
            || $row['transfer_id'] !== $receipt->transfer || (int) $row['received_at'] !== $receipt->receivedAt) {
            throw new LogicException('The original incoming transfer row lost its owner or receipt identity.');
        }
        return $receipt;
    }

    private function now(): int
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        if (! is_int($now) || $now < 0) {
            throw new LogicException('The provider observation clock must return a nonnegative integer timestamp.');
        }
        return $now;
    }

    private function query(string $sql, array $bindings): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($bindings);
        return $query;
    }

    private function transaction(Closure $action): mixed
    {
        if ($this->db->inTransaction()) {
            throw new LogicException('A caller transaction cannot contain the incoming provider transaction.');
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
