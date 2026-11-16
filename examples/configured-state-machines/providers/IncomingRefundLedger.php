<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Provider-owned refund admission. Unknown/processing portions continue to reserve the original money. */
final readonly class IncomingRefundLedger
{
    public function __construct(
        private PDO $db,
        private Closure $clock
    ) {
    }

    public static function handles(Request $request): bool
    {
        return $request->action === 'refund-incoming';
    }

    public function install(): void
    {
        $this->assertTransaction();
        $this->db->exec('CREATE TABLE incoming_refund_allocations (
            operation_id TEXT PRIMARY KEY, reference_id TEXT NOT NULL, transfer_id TEXT NOT NULL,
            amount_minor INTEGER NOT NULL, request_sha256 TEXT NOT NULL, receipt_sha256 TEXT NOT NULL)');
    }

    public function admit(Request $request, Status $planned): Status
    {
        $this->assertTransaction();
        [$terms, $receipt] = $this->original($request);
        if ($receipt === null) {
            return Status::Unknown;
        }
        $budget = $this->reserved($terms, $receipt, $request->identity->key());
        if ($budget['count'] >= 64 && ! $budget['current']) {
            throw new LogicException('The bounded original incoming refund inventory is full.');
        }
        if ($terms->amountMinor > $receipt->amountMinor - $budget['amount']) {
            return Status::Refused;
        }
        if (in_array($planned, [Status::Confirmed, Status::Processing, Status::Unknown], true)) {
            $this->query('INSERT INTO incoming_refund_allocations
                (operation_id, reference_id, transfer_id, amount_minor, request_sha256, receipt_sha256)
                VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(operation_id) DO NOTHING', [
                $request->identity->key(), $terms->original->identity->key(), $terms->transfer,
                $terms->amountMinor, $request->fingerprint(), $this->receiptHash($receipt),
            ]);
        }
        return $planned;
    }

    public function assertCompletion(Request $request): void
    {
        $this->assertTransaction();
        [$terms, $receipt] = $this->original($request);
        if ($receipt === null) {
            throw new LogicException('The original incoming transfer is unavailable for refund completion.');
        }
        $slot = $this->query('SELECT * FROM incoming_refund_allocations WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($slot === false) {
            throw new LogicException('An incoming refund lost its original money admission.');
        }
        $this->assertAllocation($slot, $request, $terms, $receipt);
        $budget = $this->reserved($terms, $receipt, $request->identity->key());
        if ($terms->amountMinor > $receipt->amountMinor - $budget['amount']) {
            throw new LogicException('Incoming refund completion exceeds the original received money.');
        }
    }

    /**
     * @return array{IncomingRefundTerms, ?IncomingPaymentReceipt}
     */
    private function original(Request $request): array
    {
        $terms = IncomingRefundTerms::fromArray($request->terms);
        if (! self::handles($request) || $request->target?->toArray() !== $terms->original->identity->toArray()) {
            throw new LogicException('The incoming refund substituted its original receivable.');
        }
        $original = $terms->original;
        $reference = $this->query('SELECT * FROM incoming_payment_references WHERE id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($reference === false) {
            return [$terms, null];
        }
        if ($reference['client'] !== $original->identity->client || $reference['reference'] !== $original->reference
            || $reference['payload'] !== PortableDescriptor::encode($original->toArray())
            || ! hash_equals($original->fingerprint(), $reference['fingerprint'])) {
            throw new LogicException('Incoming refund reference evidence targets another original owner or terms.');
        }
        $id = hash('sha256', PortableDescriptor::encode([$original->identity->client, $terms->transfer]));
        $row = $this->query('SELECT * FROM incoming_payment_receipts WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [$terms, null];
        }
        if (strlen($row['payload']) > PortableDescriptor::MAX_BYTES
            || ! hash_equals(hash('sha256', $row['payload']), $row['fingerprint'])) {
            throw new LogicException('The original incoming transfer lost its refund evidence integrity.');
        }
        $receipt = IncomingPaymentReceipt::fromArray(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR));
        $now = ($this->clock)();
        if (! is_int($now) || $now < 0 || $receipt->receivedAt > $now
            || $row['client'] !== $original->identity->client || $row['reference_id'] !== $original->identity->key()
            || $row['transfer_id'] !== $terms->transfer || $receipt->transfer !== $terms->transfer
            || $receipt->receivable !== $original->identity->key() || $receipt->currency !== $original->currency
            || (string) $row['received_at'] !== (string) $receipt->receivedAt) {
            throw new LogicException('The incoming refund receipt targets another original owner, currency or time.');
        }
        return [$terms, $receipt];
    }

    /**
     * @return array{amount: int, count: int, current: bool}
     */
    private function reserved(IncomingRefundTerms $terms, IncomingPaymentReceipt $receipt, string $current): array
    {
        $orphan = $this->query('SELECT a.operation_id FROM incoming_refund_allocations a
            LEFT JOIN provider_operations o ON o.id = a.operation_id
            WHERE a.reference_id = ? AND a.transfer_id = ? AND o.id IS NULL LIMIT 1', [
            $terms->original->identity->key(), $terms->transfer,
        ])->fetchColumn();
        if ($orphan !== false) {
            throw new LogicException('An incoming refund allocation lost its original provider outcome.');
        }
        $rows = $this->query("SELECT o.*, a.operation_id AS allocation_id, a.reference_id,
            a.transfer_id, a.amount_minor, a.request_sha256, a.receipt_sha256
            FROM provider_operations o LEFT JOIN incoming_refund_allocations a ON a.operation_id = o.id
            WHERE (a.reference_id = ? AND a.transfer_id = ?)
            OR (json_extract(o.request, '$.action') = 'refund-incoming'
                AND json_extract(o.request, '$.target') = ? AND json_extract(o.request, '$.terms.transfer') = ?)
            ORDER BY o.id LIMIT 65", [
            $terms->original->identity->key(), $terms->transfer,
            PortableDescriptor::encode($terms->original->identity->toArray()), $terms->transfer,
        ])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 64) {
            throw new LogicException('The bounded incoming refund inventory is incomplete.');
        }
        $reserved = 0;
        $hasCurrent = false;
        foreach ($rows as $row) {
            [$request, $other] = $this->readOriginalRefund($row, $terms);
            $active = in_array(
                Status::from($row['status']),
                [Status::Confirmed, Status::Processing, Status::Unknown],
                true
            );
            if ($row['allocation_id'] !== null) {
                $this->assertAllocation($row, $request, $other, $receipt);
            } elseif ($active) {
                // An absent target was never admitted; only its definitive NotExecuted
                // completion can permit a later attempt. Lookup absence grants no money.
                throw new LogicException('An unresolved incoming refund has no original money admission.');
            }
            $hasCurrent = $hasCurrent || $row['id'] === $current;
            if (! $active || $row['id'] === $current) {
                continue;
            }
            if ($other->amountMinor > $receipt->amountMinor - $reserved) {
                throw new LogicException('Stored incoming refunds exceed the original received money.');
            }
            $reserved += $other->amountMinor;
        }
        return [
            'amount' => $reserved,
            'count' => count($rows),
            'current' => $hasCurrent,
        ];
    }

    /**
     * @return array{Request, IncomingRefundTerms}
     */
    private function readOriginalRefund(array $row, IncomingRefundTerms $expected): array
    {
        if (strlen($row['request']) > PortableDescriptor::MAX_BYTES
            || ! hash_equals(hash('sha256', $row['request']), $row['fingerprint'])) {
            throw new LogicException('An original incoming refund command lost its evidence integrity.');
        }
        $request = Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR));
        $terms = IncomingRefundTerms::fromArray($request->terms);
        if ($row['id'] !== $request->identity->key() || $request->fingerprint() !== $row['fingerprint']
            || $terms->original->fingerprint() !== $expected->original->fingerprint()
            || $terms->transfer !== $expected->transfer) {
            throw new LogicException('The incoming refund inventory substituted its original terms or owner.');
        }
        return [$request, $terms];
    }

    private function assertAllocation(
        array $row,
        Request $request,
        IncomingRefundTerms $terms,
        IncomingPaymentReceipt $receipt
    ): void {
        if (($row['operation_id'] ?? $row['allocation_id'] ?? null) !== $request->identity->key()
            || $row['reference_id'] !== $terms->original->identity->key() || $row['transfer_id'] !== $terms->transfer
            || (string) $row['amount_minor'] !== (string) $terms->amountMinor
            || $row['request_sha256'] !== $request->fingerprint()
            || $row['receipt_sha256'] !== $this->receiptHash($receipt)) {
            throw new LogicException('The original incoming refund allocation or received money was substituted.');
        }
    }

    private function receiptHash(IncomingPaymentReceipt $receipt): string
    {
        return hash('sha256', PortableDescriptor::encode($receipt->toArray()));
    }

    private function query(string $sql, array $values): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query;
    }

    private function assertTransaction(): void
    {
        if (! $this->db->inTransaction()) {
            throw new LogicException('Incoming refund admission requires the provider-owned transaction.');
        }
    }
}
