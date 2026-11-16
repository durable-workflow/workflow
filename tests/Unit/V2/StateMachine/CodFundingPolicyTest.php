<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodJournal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\CodFundingPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

/** Pure contract preparation only; native carrier admission/handover/waiting remains required. */
final class CodFundingPolicyTest extends TestCase
{
    public function testBothCarrierPoliciesRetainEveryCommonMachineDefinitionAndRoute(): void
    {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $base = FundingCatalogue::profile($role, FundingCatalogue::IMMEDIATE);
            foreach ([CodFundingPolicy::BEFORE, CodFundingPolicy::AFTER] as $recipe) {
                $profile = CodFundingPolicy::profile($role, $recipe);
                $this->assertSame($base->definition->fingerprint(), $profile->definition->fingerprint());
                $route = static fn ($edge): array => [$edge->key, $edge->event, $edge->from, $edge->to];
                $this->assertSame(array_map($route, $base->transitions), array_map($route, $profile->transitions));
                $this->assertNotSame($base->fingerprint(), $profile->fingerprint());
            }
        }
    }

    public function testOriginalCarrierReadinessDoesNotWaitForCustomerCollectionOrMerchantPayment(): void
    {
        foreach ([CodFundingPolicy::BEFORE, CodFundingPolicy::AFTER] as $recipe) {
            $this->assertSame(Verdict::Satisfied, $this->evaluate($recipe, 'prepare.target', [
                'cod_reference_ready' => true,
                'cod_payer_collection_confirmed' => false,
                'merchant_receipt_confirmed' => false,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'collect.target', [
                'cod_payer_collection_confirmed' => false,
                'capture_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'cod_payer_collection_confirmed' => true,
                'merchant_receipt_confirmed' => false,
                'child_completed' => true,
            ]));
            $this->assertSame(Verdict::Satisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'merchant_receipt_confirmed' => true,
            ]));
        }
    }

    public function testPureCodPoliciesCannotAdmitNativeWorkBeforeTheirHostAdapterExists(): void
    {
        $this->expectException(LogicException::class);
        Catalogue::assertRecipe(CodFundingPolicy::BEFORE);
    }

    public function testCodAssignmentRetainsOriginalPurchaseMoneyShipmentAndFixedDeadlines(): void
    {
        $terms = $this->terms();
        $copy = OrderTerms::fromArray($terms->toArray());
        $this->assertSame($terms->toArray(), $copy->toArray());
        $this->assertSame($terms->subjectKey(), $copy->subjectKey());
        $this->assertSame($terms->physicalDescriptor(), $copy->codPayment->shipment->toArray());
        $this->assertSame(1000, $copy->codPayment->collectionDeadline);
        $this->assertSame(2000, $copy->codPayment->remittanceDeadline);
        $this->assertSame(CodJournal::CLOSABLE, $copy->codDescriptor()['admissionContract']);
        $this->expectException(InvalidArgumentException::class);
        $copy->descriptor('payment');
    }

    public static function substitutions(): array
    {
        return [['subject'],
            ['generation'],
            ['money'],
            ['shipment'],
            ['contract'],
            ['missing-contract'],
            ['coerced-deadline'],
        ];
    }

    #[DataProvider('substitutions')]
    public function testCodAssignmentRejectsAnotherOriginalOwnerOrCoercedTerms(string $case): void
    {
        $value = $this->terms()
            ->toArray();
        if ($case === 'subject' || $case === 'generation') {
            $value[$case] = 'another';
        } elseif ($case === 'money') {
            ++$value['amountMinor'];
        } elseif ($case === 'shipment') {
            $value['sku'] = 'another';
        } elseif ($case === 'contract') {
            $value['codAdmissionContract'] = 'replace-original-contract';
        } elseif ($case === 'missing-contract') {
            unset($value['codAdmissionContract']);
        } else {
            $value['codPayment']['collectionDeadline'] = '1000';
        }
        $this->expectException(InvalidArgumentException::class);
        OrderTerms::fromArray($value);
    }

    public function testHistoricalOrderWireDoesNotAcquireNullCodFields(): void
    {
        $old = new OrderTerms('shop-a', 'purchase-cod', 'g1', 1500, 'USD', 'sku-1', 2);
        $wire = $old->toArray();
        $this->assertSame(
            ['client', 'subject', 'generation', 'amountMinor', 'currency', 'sku', 'quantity'],
            array_keys($wire)
        );
        $this->assertSame($wire, OrderTerms::fromArray($wire)->toArray());
    }

    private function terms(): OrderTerms
    {
        $base = new OrderTerms('shop-a', 'purchase-cod', 'g1', 1500, 'USD', 'sku-1', 2);
        $cod = new CodDescriptor(
            new Identity('shop-a', 'purchase-cod', 'g1', 'cod-original'),
            ShipmentDescriptor::fromArray($base->physicalDescriptor()),
            'carrier-original',
            'depot-original',
            1500,
            'USD',
            1000,
            2000
        );
        return new OrderTerms(
            'shop-a',
            'purchase-cod',
            'g1',
            1500,
            'USD',
            'sku-1',
            2,
            fulfillmentDeadline: 1500,
            codPayment: $cod,
            codAdmissionContract: CodJournal::CLOSABLE
        );
    }

    private function evaluate(string $recipe, string $condition, array $values): Verdict
    {
        $profile = CodFundingPolicy::profile('payment', $recipe);
        return Catalogue::registry()->evaluateCondition($profile->condition($condition), new RecordedFacts(
            'cod-contract-control',
            [
                'forward_open' => true,
                ...$values,
            ],
            array_fill_keys(array_keys([
                'forward_open' => true,
                ...$values,
            ]), 'cod-factual-control')
        ))->decision->verdict;
    }
}
