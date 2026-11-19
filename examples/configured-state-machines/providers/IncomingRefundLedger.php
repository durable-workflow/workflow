<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Original incoming receipt validation; allocation bounds share the same provider transaction. */
final readonly class IncomingRefundLedger extends RefundAllocationLedger
{
    protected const TABLE = 'incoming_refund_allocations';

    protected const OWNER_COLUMN = 'reference_id';

    protected const SOURCE_COLUMN = 'transfer_id';

    protected const SOURCE_FIELD = 'transfer';

    protected const ACTION = 'refund-incoming';

    protected const TERMS_CLASS = IncomingRefundTerms::class;

    protected const LABEL = 'incoming';

    protected const ARTICLE = 'An incoming';

    protected const MONEY = 'received';

    protected const COMPLETION_LABEL = 'Incoming';

    protected const MISSING_RECEIPT = 'The original incoming transfer is unavailable for refund completion.';

    /**
     * @return array{IncomingRefundTerms, ?IncomingPaymentReceipt}
     */
    protected function original(Request $request): array
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
}
