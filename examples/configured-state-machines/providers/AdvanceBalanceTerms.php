<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Original bank advance plus carrier balance for one purchase; partial receipt is not full settlement. */
final readonly class AdvanceBalanceTerms
{
    public function __construct(
        public IncomingPaymentDescriptor $advance,
        public CodDescriptor $balance,
        public int $totalAmountMinor,
        public string $currency,
        public string $advanceAdmissionContract = IncomingPaymentJournal::OPEN,
        public string $balanceAdmissionContract = CodJournal::OPEN,
    ) {
        if (! $advance->identity->sameOwner($balance->identity)
            || $advance->identity->key() === $balance->identity->key()) {
            throw new InvalidArgumentException(
                'Both payment portions must retain one purchase and distinct original identities.'
            );
        }
        if ($totalAmountMinor <= 0 || $advance->amountMinor >= $totalAmountMinor
            || $balance->amountMinor !== $totalAmountMinor - $advance->amountMinor
            || $advance->currency !== $currency || $balance->currency !== $currency) {
            throw new InvalidArgumentException(
                'Original advance and balance must exactly partition the purchase money.'
            );
        }
        if ($advance->deadline > $balance->collectionDeadline) {
            throw new InvalidArgumentException(
                'The original advance deadline cannot follow the carrier collection deadline.'
            );
        }
        if (! in_array(
            $advanceAdmissionContract,
            [IncomingPaymentJournal::OPEN, IncomingPaymentJournal::CLOSABLE],
            true
        )
            || ! in_array($balanceAdmissionContract, [CodJournal::OPEN, CodJournal::CLOSABLE], true)) {
            throw new InvalidArgumentException(
                'Each original payment portion requires its own supported admission contract.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'advance' => $this->advance->toArray(),
            'balance' => $this->balance->toArray(),
            'totalAmountMinor' => $this->totalAmountMinor,
            'currency' => $this->currency,
            'advanceAdmissionContract' => $this->advanceAdmissionContract,
            'balanceAdmissionContract' => $this->balanceAdmissionContract,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== [
            'advance',
            'advanceAdmissionContract',
            'balance',
            'balanceAdmissionContract',
            'currency',
            'totalAmountMinor',
        ]
            || ! is_array($value['advance']) || ! is_array($value['balance']) || ! is_int($value['totalAmountMinor'])
            || ! is_string($value['currency']) || ! is_string($value['advanceAdmissionContract']) || ! is_string(
                $value['balanceAdmissionContract']
            )) {
            throw new InvalidArgumentException('Original advance/balance fields are missing, unexpected or coerced.');
        }
        return new self(
            IncomingPaymentDescriptor::fromArray($value['advance']),
            CodDescriptor::fromArray($value['balance']),
            $value['totalAmountMinor'],
            $value['currency'],
            $value['advanceAdmissionContract'],
            $value['balanceAdmissionContract']
        );
    }
}
