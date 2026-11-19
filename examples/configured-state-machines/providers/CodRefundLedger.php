<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Original COD customer receipt validation; allocation bounds share the same provider transaction. */
final readonly class CodRefundLedger extends RefundAllocationLedger
{
    protected const TABLE = 'cod_refund_allocations';

    protected const OWNER_COLUMN = 'payment_id';

    protected const SOURCE_COLUMN = 'collection_id';

    protected const SOURCE_FIELD = 'collection';

    protected const ACTION = 'refund-cod';

    protected const TERMS_CLASS = CodRefundTerms::class;

    protected const LABEL = 'COD customer';

    protected const ARTICLE = 'A COD customer';

    protected const MONEY = 'collected';

    protected const COMPLETION_LABEL = 'COD customer';

    protected const MISSING_RECEIPT = 'The original carrier collection is unavailable for refund completion.';

    /**
     * @return array{CodRefundTerms, ?CodReceipt}
     */
    protected function original(Request $request): array
    {
        $terms = CodRefundTerms::fromArray($request->terms);
        if (! self::handles($request) || $request->target?->toArray() !== $terms->original->identity->toArray()) {
            throw new LogicException('The COD refund substituted its original carrier payment.');
        }
        $original = $terms->original;
        $reference = $this->query('SELECT * FROM cod_payment_references WHERE id = ?', [
            $original->identity->key(),
        ])->fetch(PDO::FETCH_ASSOC);
        if ($reference === false) {
            return [$terms, null];
        }
        if ($reference['client'] !== $original->identity->client || $reference['reference'] !== $original->reference
            || $reference['descriptor'] !== PortableDescriptor::encode($original->toArray())
            || ! hash_equals($original->fingerprint(), $reference['descriptor_sha256'])) {
            throw new LogicException('The original COD refund reference changed owner or terms.');
        }
        $id = hash('sha256', PortableDescriptor::encode([
            $original->identity->client, CodReceipt::COLLECTION, $terms->collection,
        ]));
        $row = $this->query('SELECT * FROM cod_payment_receipts WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [$terms, null];
        }
        $anchor = $this->query('SELECT * FROM cod_receipt_anchors WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        $hash = hash('sha256', $row['payload']);
        if (strlen($row['payload']) > PortableDescriptor::MAX_BYTES || $anchor === false
            || $anchor['payment_id'] !== $original->identity->key() || $anchor['payload_sha256'] !== $hash
            || $row['payload_sha256'] !== $hash) {
            throw new LogicException('The original COD payer collection lost its refund evidence integrity.');
        }
        $receipt = CodReceipt::fromArray(json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR));
        $now = ($this->clock)();
        if (! is_int($now) || $now < 0 || $receipt->occurredAt > $now
            || $row['client'] !== $original->identity->client || $row['payment_id'] !== $original->identity->key()
            || $row['kind'] !== CodReceipt::COLLECTION || $receipt->kind !== CodReceipt::COLLECTION
            || $row['receipt_id'] !== $terms->collection || $receipt->receipt !== $terms->collection
            || $receipt->payment !== $original->identity->key() || $receipt->currency !== $original->currency
            || $receipt->target !== $original->collectionPoint) {
            throw new LogicException('The COD refund collection changed its original owner, point, currency or time.');
        }
        return [$terms, $receipt];
    }
}
