<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AdvanceBalanceTerms;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvanceBalanceFixture;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Pure original money contract only; native partial-payment admission remains separate. */
final class AdvanceBalanceTermsTest extends TestCase
{
    use AdvanceBalanceFixture;

    public function testOriginalPortionsRetainDistinctMoneyIdentitiesDeadlinesAndAdmissionContracts(): void
    {
        $terms = $this->originalParts();
        $copy = AdvanceBalanceTerms::fromArray(array_reverse($terms->toArray(), true));
        $this->assertSame($terms->fingerprint(), $copy->fingerprint());
        $this->assertSame(500, $copy->advance->amountMinor);
        $this->assertSame(999, $copy->balance->amountMinor);
        $this->assertSame(1499, $copy->totalAmountMinor);
        $this->assertNotSame($copy->advance->identity->key(), $copy->balance->identity->key());
        $this->assertSame(1100, $copy->advance->deadline);
        $this->assertSame(1200, $copy->balance->collectionDeadline);
        $this->assertSame(2000, $copy->balance->remittanceDeadline);
        $this->assertSame($terms->toArray(), $copy->toArray());
    }

    public function testMaximumOriginalPurchaseDoesNotOverflowWhenCheckingItsTwoPortions(): void
    {
        $terms = $this->originalParts(PHP_INT_MAX, 1);
        $this->assertSame(PHP_INT_MAX, AdvanceBalanceTerms::fromArray($terms->toArray())->totalAmountMinor);
        $this->assertSame(PHP_INT_MAX - 1, $terms->balance->amountMinor);
    }

    public static function substitutions(): array
    {
        return array_map(static fn (string $case): array => [$case], ['sum', 'zero-total', 'float-total',
            'string-total', 'extra-field', 'missing-contract', 'foreign-owner', 'foreign-generation',
            'same-operation', 'currency', 'advance-currency', 'late-advance', 'advance-contract', 'balance-contract']);
    }

    #[DataProvider('substitutions')]
    public function testAnotherOwnerAmountOrContractCannotReplaceAnOriginalPortion(string $case): void
    {
        $value = $this->originalParts()
            ->toArray();
        match ($case) {
            'sum' => ++$value['totalAmountMinor'],
            'zero-total' => $value['totalAmountMinor'] = 0,
            'float-total' => $value['totalAmountMinor'] = 1499.0,
            'string-total' => $value['totalAmountMinor'] = '1499',
            'extra-field' => $value['settled'] = true,
            'foreign-owner' => $value['advance']['identity']['subject'] = 'another-purchase',
            'foreign-generation' => $value['advance']['identity']['generation'] = 'another-generation',
            'same-operation' => $value['advance']['identity'] = $value['balance']['identity'],
            'currency' => $value['currency'] = 'EUR',
            'advance-currency' => $value['advance']['currency'] = 'EUR',
            'late-advance' => $value['advance']['deadline'] = 1201,
            'advance-contract' => $value['advanceAdmissionContract'] = 'substituted',
            'balance-contract' => $value['balanceAdmissionContract'] = 'substituted',
            default => null,
        };
        if ($case === 'missing-contract') {
            unset($value['advanceAdmissionContract']);
        }
        $this->expectException(InvalidArgumentException::class);
        AdvanceBalanceTerms::fromArray($value);
    }
}
