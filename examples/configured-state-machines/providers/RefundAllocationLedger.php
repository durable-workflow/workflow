<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Provider-owned refund admission. Unknown/processing portions continue to reserve the original money. */
abstract readonly class RefundAllocationLedger
{
    protected const TABLE = '';

    protected const OWNER_COLUMN = '';

    protected const SOURCE_COLUMN = '';

    protected const SOURCE_FIELD = '';

    protected const ACTION = '';

    protected const TERMS_CLASS = IncomingRefundTerms::class;

    protected const LABEL = '';

    protected const ARTICLE = '';

    protected const MONEY = '';

    protected const COMPLETION_LABEL = '';

    protected const MISSING_RECEIPT = '';

    public function __construct(
        protected PDO $db,
        protected Closure $clock
    ) {
    }

    public static function handles(Request $request): bool
    {
        return $request->action === static::ACTION;
    }

    public function install(): void
    {
        $this->assertTransaction();
        $this->query('CREATE TABLE {allocations} (
            operation_id TEXT PRIMARY KEY, {owner} TEXT NOT NULL, {source} TEXT NOT NULL,
            amount_minor INTEGER NOT NULL, request_sha256 TEXT NOT NULL, receipt_sha256 TEXT NOT NULL)', []);
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
            throw new LogicException('The bounded original ' . static::LABEL . ' refund inventory is full.');
        }
        if ($terms->amountMinor > $receipt->amountMinor - $budget['amount']) {
            return Status::Refused;
        }
        if (in_array($planned, [Status::Confirmed, Status::Processing, Status::Unknown], true)) {
            $this->query('INSERT INTO {allocations}
                (operation_id, {owner}, {source}, amount_minor, request_sha256, receipt_sha256)
                VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(operation_id) DO NOTHING', [
                $request->identity->key(), $terms->original->identity->key(), $terms->{static::SOURCE_FIELD},
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
            throw new LogicException(static::MISSING_RECEIPT);
        }
        $slot = $this->query('SELECT * FROM {allocations} WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($slot === false) {
            throw new LogicException(static::ARTICLE . ' refund lost its original money admission.');
        }
        $this->assertAllocation($slot, $request, $terms, $receipt);
        $budget = $this->reserved($terms, $receipt, $request->identity->key());
        if ($terms->amountMinor > $receipt->amountMinor - $budget['amount']) {
            throw new LogicException(
                static::COMPLETION_LABEL . ' refund completion exceeds the original ' . static::MONEY . ' money.'
            );
        }
    }

    /**
     * @return array{IncomingRefundTerms|CodRefundTerms, IncomingPaymentReceipt|CodReceipt|null}
     */
    abstract protected function original(Request $request): array;

    protected function query(string $sql, array $values): \PDOStatement
    {
        $sql = strtr($sql, [
            '{allocations}' => static::TABLE,
            '{owner}' => static::OWNER_COLUMN,
            '{source}' => static::SOURCE_COLUMN,
            '{source_field}' => static::SOURCE_FIELD,
            '{action}' => static::ACTION,
        ]);
        $query = $this->db->prepare($sql);
        $query->execute($values);
        return $query;
    }

    /**
     * @return array{amount: int, count: int, current: bool}
     */
    private function reserved(
        IncomingRefundTerms|CodRefundTerms $terms,
        IncomingPaymentReceipt|CodReceipt $receipt,
        string $current
    ): array {
        $orphan = $this->query('SELECT a.operation_id FROM {allocations} a
            LEFT JOIN provider_operations o ON o.id = a.operation_id
            WHERE a.{owner} = ? AND a.{source} = ? AND o.id IS NULL LIMIT 1', [
            $terms->original->identity->key(), $terms->{static::SOURCE_FIELD},
        ])->fetchColumn();
        if ($orphan !== false) {
            throw new LogicException(static::ARTICLE . ' refund allocation lost its original provider outcome.');
        }
        $rows = $this->query("SELECT o.*, a.operation_id AS allocation_id, a.{owner},
            a.{source}, a.amount_minor, a.request_sha256, a.receipt_sha256
            FROM provider_operations o LEFT JOIN {allocations} a ON a.operation_id = o.id
            WHERE (a.{owner} = ? AND a.{source} = ?)
            OR (json_extract(o.request, '$.action') = '{action}'
                AND json_extract(o.request, '$.target') = ? AND json_extract(o.request, '$.terms.{source_field}') = ?)
            ORDER BY o.id LIMIT 65", [
            $terms->original->identity->key(), $terms->{static::SOURCE_FIELD},
            PortableDescriptor::encode($terms->original->identity->toArray()), $terms->{static::SOURCE_FIELD},
        ])->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 64) {
            throw new LogicException('The bounded ' . static::LABEL . ' refund inventory is incomplete.');
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
                throw new LogicException('An unresolved ' . static::LABEL . ' refund has no original money admission.');
            }
            $hasCurrent = $hasCurrent || $row['id'] === $current;
            if (! $active || $row['id'] === $current) {
                continue;
            }
            if ($other->amountMinor > $receipt->amountMinor - $reserved) {
                throw new LogicException(
                    'Stored ' . static::LABEL . ' refunds exceed the original ' . static::MONEY . ' money.'
                );
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
     * @return array{Request, IncomingRefundTerms|CodRefundTerms}
     */
    private function readOriginalRefund(array $row, IncomingRefundTerms|CodRefundTerms $expected): array
    {
        if (strlen($row['request']) > PortableDescriptor::MAX_BYTES
            || ! hash_equals(hash('sha256', $row['request']), $row['fingerprint'])) {
            throw new LogicException('An original ' . static::LABEL . ' refund command lost its evidence integrity.');
        }
        $request = Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR));
        $codec = static::TERMS_CLASS;
        $terms = $codec::fromArray($request->terms);
        if (! static::handles($request) || $request->target?->toArray() !== $terms->original->identity->toArray()
            || $row['id'] !== $request->identity->key() || $request->fingerprint() !== $row['fingerprint']
            || $terms->original->fingerprint() !== $expected->original->fingerprint()
            || $terms->{static::SOURCE_FIELD} !== $expected->{static::SOURCE_FIELD}) {
            throw new LogicException(
                'The ' . static::LABEL . ' refund inventory substituted its original terms or owner.'
            );
        }
        return [$request, $terms];
    }

    private function assertAllocation(
        array $row,
        Request $request,
        IncomingRefundTerms|CodRefundTerms $terms,
        IncomingPaymentReceipt|CodReceipt $receipt
    ): void {
        if (($row['operation_id'] ?? $row['allocation_id'] ?? null) !== $request->identity->key()
            || $row[static::OWNER_COLUMN] !== $terms->original->identity->key() || $row[static::SOURCE_COLUMN] !== $terms->{static::SOURCE_FIELD}
            || (string) $row['amount_minor'] !== (string) $terms->amountMinor
            || $row['request_sha256'] !== $request->fingerprint()
            || $row['receipt_sha256'] !== $this->receiptHash($receipt)) {
            throw new LogicException(
                'The original ' . static::LABEL . ' refund allocation or ' . static::MONEY . ' money was substituted.'
            );
        }
    }

    private function receiptHash(IncomingPaymentReceipt|CodReceipt $receipt): string
    {
        return hash('sha256', PortableDescriptor::encode($receipt->toArray()));
    }

    private function assertTransaction(): void
    {
        if (! $this->db->inTransaction()) {
            throw new LogicException(
                static::COMPLETION_LABEL . ' refund admission requires the provider-owned transaction.'
            );
        }
    }
}
