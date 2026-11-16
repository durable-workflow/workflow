<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentJournal;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';
require_once __DIR__ . '/../../../../examples/provider-state-machines/FundingCatalogue.php';

/** Definition/guard controls only; native common-route integration remains required. */
final class CommonFundingPolicyTest extends TestCase
{
    public function testAllFourPoliciesShareOneDefinitionAndRouteForEveryMachineType(): void
    {
        foreach (['order', 'payment', 'shipment'] as $role) {
            $profiles = array_map(
                static fn (string $recipe): PolicyProfile => FundingCatalogue::{$role}($recipe),
                FundingCatalogue::recipes()
            );
            $this->assertCount(
                1,
                array_unique(array_map(
                    static fn (PolicyProfile $p): string => $p->definition->fingerprint(),
                    $profiles
                ))
            );
            $this->assertCount(
                4,
                array_unique(array_map(static fn (PolicyProfile $p): string => $p->fingerprint(), $profiles))
            );
            $routes = array_map(
                static fn (PolicyProfile $p): array => array_map(
                    static fn ($edge): array => [$edge->key, $edge->event, $edge->from, $edge->to],
                    $p->transitions
                ),
                $profiles
            );
            foreach ($routes as $route) {
                $this->assertSame($routes[0], $route);
            }
        }
        $this->assertSame(
            ['received', 'ready', 'collected', 'paid'],
            FundingCatalogue::payment(FundingCatalogue::BANK)->states
        );
        $this->assertSame(
            'paid',
            FundingCatalogue::order(FundingCatalogue::BANK)->definition->slot('payment')->requiredOutcome
        );
    }

    public static function readiness(): array
    {
        return [
            [FundingCatalogue::IMMEDIATE, [
                'capture_confirmed' => true,
            ], Verdict::Satisfied],
            [FundingCatalogue::IMMEDIATE, [
                'capture_confirmed' => false,
                'authorization_active' => true,
            ], Verdict::Unsatisfied],
            [FundingCatalogue::AFTER_STOCK, [
                'authorization_active' => true,
                'capture_confirmed' => false,
            ], Verdict::Satisfied],
            [FundingCatalogue::AFTER_DELIVERY, [
                'authorization_active' => false,
                'capture_confirmed' => true,
            ], Verdict::Unsatisfied],
            [FundingCatalogue::BANK, [
                'incoming_full_amount_confirmed' => false,
                'capture_confirmed' => true,
            ], Verdict::Unsatisfied],
            [FundingCatalogue::BANK, [
                'incoming_full_amount_confirmed' => true,
                'capture_confirmed' => false,
            ], Verdict::Satisfied],
            [FundingCatalogue::BANK, [
                'capture_confirmed' => true,
            ], Verdict::Unknown],
        ];
    }

    #[DataProvider('readiness')]
    public function testFundingReadinessUsesTheOriginalSelectedEvidenceRatherThanInventingCardMoney(
        string $recipe,
        array $facts,
        Verdict $expected
    ): void {
        $this->assertSame($expected, $this->evaluate($recipe, 'prepare.target', [
            'forward_open' => true,
            ...$facts,
        ]));
    }

    public static function captureTriggers(): array
    {
        return [
            [FundingCatalogue::AFTER_STOCK, true, false, Verdict::Satisfied],
            [FundingCatalogue::AFTER_STOCK, false, true, Verdict::Unsatisfied],
            [FundingCatalogue::AFTER_DELIVERY, true, false, Verdict::Unsatisfied],
            [FundingCatalogue::AFTER_DELIVERY, false, true, Verdict::Satisfied],
        ];
    }

    #[DataProvider('captureTriggers')]
    public function testCommonCollectionStageStillPreservesTheOriginalStockOrDeliveryCapturePolicy(
        string $recipe,
        bool $stock,
        bool $delivery,
        Verdict $expected
    ): void {
        $this->assertSame($expected, $this->evaluate($recipe, 'collect.permission', [
            'forward_open' => true,
            'authorization_active' => true,
            'original_stock_reserved' => $stock,
            'original_recipient_delivered' => $delivery,
        ]));
        $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'collect.target', [
            'forward_open' => true,
            'capture_confirmed' => false,
        ]));
    }

    public function testPayerCollectionAndTechnicalCompletionCannotProveMerchantReceipt(): void
    {
        foreach (FundingCatalogue::recipes() as $recipe) {
            $this->assertSame(Verdict::Unknown, $this->evaluate($recipe, 'confirm-receipt.target', [
                'forward_open' => true,
                'payer_collection_confirmed' => true,
                'child_completed' => true,
            ]));
            $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'forward_open' => true,
                'merchant_receipt_confirmed' => false,
            ]));
            $this->assertSame(Verdict::Satisfied, $this->evaluate($recipe, 'confirm-receipt.target', [
                'forward_open' => true,
                'merchant_receipt_confirmed' => true,
            ]));
        }
    }

    public function testCancellationClosesEveryFundingStageEvenWhenItsMoneyOrTriggerFactsAreTrue(): void
    {
        foreach (FundingCatalogue::recipes() as $recipe) {
            foreach ([
                'prepare.target',
                'collect.target',
                'collect.permission',
                'confirm-receipt.target',
            ] as $condition) {
                $this->assertSame(Verdict::Unsatisfied, $this->evaluate($recipe, $condition, [
                    'forward_open' => false,
                    'capture_confirmed' => true,
                    'authorization_active' => true,
                    'incoming_full_amount_confirmed' => true,
                    'merchant_receipt_confirmed' => true,
                    'original_stock_reserved' => true,
                    'original_recipient_delivered' => true,
                ]));
            }
        }
    }

    public function testHistoricalPaymentDefinitionsRemainAvailableAndDistinct(): void
    {
        $this->assertSame(['received', 'captured'], Catalogue::profile('payment')->states);
        $this->assertSame(
            ['received', 'authorized', 'captured'],
            FinancialCatalogue::profile(FinancialCatalogue::AFTER_STOCK)->states
        );
        $this->assertNotSame(
            Catalogue::profile('payment')->definition->fingerprint(),
            FundingCatalogue::payment(FundingCatalogue::IMMEDIATE)->definition->fingerprint()
        );
        $this->assertNotSame(
            FinancialCatalogue::profile(FinancialCatalogue::AFTER_STOCK)->definition->fingerprint(),
            FundingCatalogue::payment(FundingCatalogue::AFTER_STOCK)->definition->fingerprint()
        );
    }

    public function testAnUnimplementedPolicyCannotBecomeAnAdmittedNewProfile(): void
    {
        $this->expectException(LogicException::class);
        FundingCatalogue::payment('carrier-remittance-is-card-capture');
    }

    public function testBankAssignmentRetainsItsOriginalOwnerReferenceMoneyDeadlineAndAdmissionContract(): void
    {
        $incoming = new IncomingPaymentDescriptor(new Identity(
            'shop-a',
            'purchase-1',
            'g1',
            'original-receivable'
        ), 'original-reference', 1499, 'USD', 1234);
        $terms = new OrderTerms(
            'shop-a',
            'purchase-1',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            fulfillmentDeadline: 2000,
            incomingPayment: $incoming,
            incomingAdmissionContract: IncomingPaymentJournal::CLOSABLE
        );
        $copy = OrderTerms::fromArray($terms->toArray());
        $this->assertSame($terms->toArray(), $copy->toArray());
        $this->assertSame($terms->subjectKey(), $copy->subjectKey());
        $this->assertSame([
            'original' => $incoming->toArray(),
            'admissionContract' => IncomingPaymentJournal::CLOSABLE,
        ], $copy->incomingDescriptor());
        $this->assertNull($copy->authorization);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Incoming payment cannot become an immediate card charge.');
        $copy->descriptor('payment');
    }

    public static function foreignIncoming(): iterable
    {
        foreach (['client', 'subject', 'generation', 'currency', 'amountMinor'] as $target) {
            yield $target => [$target];
        }
    }

    #[DataProvider('foreignIncoming')]
    public function testBankReferenceCannotBeReboundToAnotherPurchasePeriodOrAmount(string $target): void
    {
        $identity = new Identity(
            $target === 'client' ? 'other-shop' : 'shop-a',
            $target === 'subject' ? 'other-purchase' : 'purchase-1',
            $target === 'generation' ? 'other-period' : 'g1',
            'original-receivable'
        );
        $incoming = new IncomingPaymentDescriptor(
            $identity,
            'original-reference',
            $target === 'amountMinor' ? 1500 : 1499,
            $target === 'currency' ? 'EUR' : 'USD',
            1234
        );
        $this->expectException(InvalidArgumentException::class);
        new OrderTerms('shop-a', 'purchase-1', 'g1', 1499, 'USD', 'sku-1', 2, incomingPayment: $incoming);
    }

    public static function malformedBankAssignment(): array
    {
        return [
            [[
                'incomingPayment' => null,
            ]],
            [[
                'incomingAdmissionContract' => IncomingPaymentJournal::CLOSABLE,
            ]],
            [[
                'incomingPayment' => [],
                'incomingAdmissionContract' => null,
            ]],
        ];
    }

    #[DataProvider('malformedBankAssignment')]
    public function testPartialOrNullBankFieldsCannotReinterpretAnOriginalNonBankAssignment(array $change): void
    {
        $value = (new OrderTerms('shop-a', 'purchase-1', 'g1', 1499, 'USD', 'sku-1', 2))->toArray();
        $this->expectException(InvalidArgumentException::class);
        OrderTerms::fromArray([...$value, ...$change]);
    }

    private function evaluate(string $recipe, string $condition, array $values): Verdict
    {
        $facts = new RecordedFacts('original-funding-observation', $values, array_fill_keys(
            array_keys($values),
            'original-provider-evidence'
        ));
        return Catalogue::registry()->evaluateCondition(
            FundingCatalogue::payment($recipe)->condition($condition),
            $facts
        )->decision->verdict;
    }
}
