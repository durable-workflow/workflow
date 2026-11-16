<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ProviderStateMachines\AdvanceBalancePolicy;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class AdvanceBalancePolicyTest extends TestCase
{
    public function testBothCollectionPoliciesRetainEveryOriginalMachineDefinitionAndRoute(): void
    {
        foreach (AdvanceBalancePolicy::recipes() as $recipe) {
            Catalogue::assertRecipe($recipe);
            foreach (['order', 'payment', 'shipment'] as $role) {
                $profile = AdvanceBalancePolicy::profile($role, $recipe);
                $base = FundingCatalogue::profile($role, FundingCatalogue::IMMEDIATE);
                $this->assertSame($base->definition->fingerprint(), $profile->definition->fingerprint());
                $this->assertSame($profile->fingerprint(), Catalogue::profile($role, $recipe)->fingerprint());
                $this->assertSame($base->definition->transitionKeys, $profile->definition->transitionKeys);
                $this->assertNotSame($base->fingerprint(), $profile->fingerprint());
            }
        }
    }

    public function testAdvanceBalanceAndMerchantReceiptAreIndependentRequiredFacts(): void
    {
        foreach (AdvanceBalancePolicy::recipes() as $recipe) {
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'prepare.target', [
                'cod_reference_ready' => true,
                'capture_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Satisfied, $this->evaluate($recipe, 'prepare.target', [
                'advance_payment_confirmed' => true,
                'cod_reference_ready' => true,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'collect.target', [
                'advance_payment_confirmed' => true,
                'capture_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'collect.target', [
                'cod_payer_collection_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Satisfied, $this->evaluate($recipe, 'collect.target', [
                'advance_payment_confirmed' => true,
                'cod_payer_collection_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'advance_payment_confirmed' => true,
                'cod_payer_collection_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'merchant_receipt_confirmed' => true,
            ]));
            $this->assertSame(Verdict::Satisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'advance_payment_confirmed' => true,
                'cod_payer_collection_confirmed' => true,
                'merchant_receipt_confirmed' => true,
            ]));
        }
    }

    private function evaluate(string $recipe, string $condition, array $values): Verdict
    {
        $values = [
            'forward_open' => true,
            'advance_payment_confirmed' => false,
            'cod_reference_ready' => false,
            'cod_payer_collection_confirmed' => false,
            'merchant_receipt_confirmed' => false,
            ...$values,
        ];
        return Catalogue::registry()->evaluateCondition(
            AdvanceBalancePolicy::profile('payment', $recipe)->condition($condition),
            new RecordedFacts('original-two-portion-control', $values, array_fill_keys(
                array_keys($values),
                'original-evidence'
            ))
        )->decision->verdict;
    }
}
