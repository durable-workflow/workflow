<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;

/** Pure projection of an authoritative original provider inventory, never signal-supplied payment proof. */
final readonly class CodSnapshot
{
    private array $totals;

    /**
     * @param list<CodReceipt> $receipts
     */
    public function __construct(
        public CodDescriptor $descriptor,
        public int $observedAt,
        public array $receipts
    ) {
        if ($observedAt < 0 || ! array_is_list($receipts) || count($receipts) > 128) {
            throw new InvalidArgumentException(
                'COD requires a bounded complete receipt inventory and exact observation time.'
            );
        }
        $this->totals = $this->measure();
    }

    public function facts(): array
    {
        [$collected, $remitted] = $this->totals;
        return [
            'payer_collection_confirmed' => $collected >= $this->descriptor->amountMinor,
            'merchant_receipt_confirmed' => $collected >= $this->descriptor->amountMinor
                && $remitted >= $this->descriptor->amountMinor,
            'collected_amount_minor' => $collected,
            'merchant_received_amount_minor' => $remitted,
            'outstanding_remittance_minor' => $collected - $remitted,
            'collection_remaining_minor' => max(0, $this->descriptor->amountMinor - $collected),
            'overcollection_amount_minor' => max(0, $collected - $this->descriptor->amountMinor),
            'collection_deadline_passed' => $this->observedAt >= $this->descriptor->collectionDeadline,
            'remittance_deadline_passed' => $this->observedAt >= $this->descriptor->remittanceDeadline,
            'non_execution_established' => false,
        ];
    }

    public function toArray(): array
    {
        return [
            'original' => $this->descriptor->toArray(),
            'observedAt' => $this->observedAt,
            'receipts' => array_map(static fn (CodReceipt $receipt): array => $receipt->toArray(), $this->receipts),
            'facts' => $this->facts(),
        ];
    }

    private function measure(): array
    {
        $collections = [];
        $remittances = [];
        $collected = 0;
        foreach ($this->receipts as $receipt) {
            $this->assertOriginal($receipt);
            if ($receipt->kind === CodReceipt::COLLECTION) {
                if ($receipt->target !== $this->descriptor->collectionPoint || isset($collections[$receipt->receipt])) {
                    throw new InvalidArgumentException(
                        'The original carrier collection point or receipt inventory changed.'
                    );
                }
                $collections[$receipt->receipt] = $receipt;
                $collected = self::add($collected, $receipt->amountMinor);
            } else {
                if (isset($remittances[$receipt->receipt])) {
                    throw new InvalidArgumentException('A merchant remittance appears more than once.');
                }
                $remittances[$receipt->receipt] = $receipt;
            }
        }
        return [$collected, $this->remitted($collections, $remittances)];
    }

    /**
     * @param array<string, CodReceipt> $collections @param array<string, CodReceipt> $remittances
     */
    private function remitted(array $collections, array $remittances): int
    {
        $allocated = [];
        $total = 0;
        foreach ($remittances as $receipt) {
            $original = $collections[$receipt->target] ?? null;
            if ($original === null || $receipt->occurredAt < $original->occurredAt) {
                throw new InvalidArgumentException(
                    'Merchant remittance must follow its original confirmed payer collection.'
                );
            }
            $allocated[$receipt->target] = self::add($allocated[$receipt->target] ?? 0, $receipt->amountMinor);
            if ($allocated[$receipt->target] > $original->amountMinor) {
                throw new InvalidArgumentException('Merchant receipts exceed their original carrier collection.');
            }
            $total = self::add($total, $receipt->amountMinor);
        }
        return $total;
    }

    private function assertOriginal(mixed $receipt): void
    {
        if (! $receipt instanceof CodReceipt || $receipt->payment !== $this->descriptor->identity->key()
            || $receipt->currency !== $this->descriptor->currency || $receipt->occurredAt > $this->observedAt) {
            throw new InvalidArgumentException(
                'COD facts must retain the original payment, currency and observation boundary.'
            );
        }
    }

    private static function add(int $current, int $amount): int
    {
        if ($amount > PHP_INT_MAX - $current) {
            throw new InvalidArgumentException('COD money exceeds the exact integer observation range.');
        }
        return $current + $amount;
    }
}
