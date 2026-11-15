<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FinancialCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class FinancialPaymentPolicyTest extends TestCase
{
    public function testOriginalSevenFieldAssignmentStillUsesItsOriginalCanonicalPayloadAndRequests(): void
    {
        $legacy = new OrderTerms('shop-a', 'purchase-1', 'g1', 1499, 'USD', 'sku-1', 2);
        $payload = '{"amountMinor":1499,"client":"shop-a","currency":"USD","generation":"g1","quantity":2,"sku":"sku-1","subject":"purchase-1"}';
        $this->assertSame($payload, PortableDescriptor::encode($legacy->toArray()));
        $restored = OrderTerms::fromArray(json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($legacy->toArray(), $restored->toArray());
        $this->assertSame($legacy->subjectKey(), $restored->subjectKey());
        foreach (['order', 'payment', 'shipment'] as $role) {
            $this->assertSame($legacy->descriptor($role), $restored->descriptor($role));
        }
        $this->assertSame('capture', $legacy->descriptor('payment')['forward']['action']);
        $this->assertSame('refund', $legacy->descriptor('payment')['compensation']['action']);
    }

    public function testAuthorizedAssignmentPinsOriginalExpiryAndEveryFinancialTargetAcrossSerialization(): void
    {
        $terms = $this->terms();
        $restored = OrderTerms::fromArray($terms->toArray());
        $this->assertSame($terms->toArray(), $restored->toArray());
        $this->assertSame($terms->financialDescriptor(), $restored->financialDescriptor());
        $graph = array_map(Request::fromArray(...), $restored->financialDescriptor());
        $this->assertInstanceOf(AuthorizationTerms::class, $restored->authorization);
        $this->assertSame(1001, $restored->authorization->expiresAt);
        $this->assertNull($graph['authorize']->target);
        foreach (['capture', 'void'] as $key) {
            $this->assertNotNull($graph[$key]->target);
            $this->assertSame($graph['authorize']->identity->toArray(), $graph[$key]->target->toArray());
            $this->assertSame($graph['authorize']->terms, $graph[$key]->terms);
        }
        $this->assertNotNull($graph['refund']->target);
        $this->assertSame($graph['capture']->identity->toArray(), $graph['refund']->target->toArray());
        $this->assertSame([
            'amountMinor' => 1499,
            'currency' => 'USD',
        ], $graph['refund']->terms);
        $this->assertCount(4, array_unique(array_map(static fn (Request $r): string => $r->identity->key(), $graph)));
    }

    public function testAuthorizationCannotFallBackToTheLegacyImmediateChargeDescriptor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms()
            ->descriptor('payment');
    }

    public function testLegacyAssignmentCannotInventAnAuthorizationOrNewExpiry(): void
    {
        $legacy = new OrderTerms('shop-a', 'purchase-1', 'g1', 1499, 'USD', 'sku-1', 2);
        $this->expectException(InvalidArgumentException::class);
        $legacy->financialDescriptor();
    }

    public static function otherMoney(): array
    {
        return [[1500, 'USD'], [1499, 'EUR']];
    }

    #[DataProvider('otherMoney')]
    public function testAuthorizationCannotChangeTheOriginalOrderMoney(int $amount, string $currency): void
    {
        $authorization = new AuthorizationTerms($amount, $currency, 1001);
        $this->expectException(InvalidArgumentException::class);
        new OrderTerms('shop-a', 'purchase-1', 'g1', 1499, 'USD', 'sku-1', 2, $authorization);
    }

    public function testExplicitNullCannotIntroduceAnotherWireRepresentationOfALegacyAssignment(): void
    {
        $value = (new OrderTerms('shop-a', 'purchase-1', 'g1', 1499, 'USD', 'sku-1', 2))->toArray();
        $value['authorization'] = null;
        $this->expectException(InvalidArgumentException::class);
        OrderTerms::fromArray($value);
    }

    public function testBothCapturePoliciesShareTheSamePaymentDefinitionAndHistoricalStateMeanings(): void
    {
        $stock = FinancialCatalogue::profile(FinancialCatalogue::AFTER_STOCK);
        $delivery = FinancialCatalogue::profile(FinancialCatalogue::AFTER_DELIVERY);
        $this->assertSame($stock->definition->fingerprint(), $delivery->definition->fingerprint());
        $this->assertSame($stock->states, $delivery->states);
        $this->assertSame(['received', 'authorized', 'captured'], $stock->states);
        $this->assertEquals($stock->transitions, $delivery->transitions);
        $this->assertNotSame($stock->fingerprint(), $delivery->fingerprint());
        $this->assertNotSame(
            Catalogue::profile('payment')->definition->fingerprint(),
            $stock->definition->fingerprint()
        );
    }

    public static function triggers(): array
    {
        return [
            [FinancialCatalogue::AFTER_STOCK, true, false, Verdict::Satisfied],
            [FinancialCatalogue::AFTER_STOCK, false, true, Verdict::Unsatisfied],
            [FinancialCatalogue::AFTER_DELIVERY, true, false, Verdict::Unsatisfied],
            [FinancialCatalogue::AFTER_DELIVERY, false, true, Verdict::Satisfied],
        ];
    }

    #[DataProvider('triggers')]
    public function testCapturePermissionUsesTheSelectedOriginalBusinessEvidence(
        string $recipe,
        bool $stock,
        bool $delivery,
        Verdict $expected,
    ): void {
        $profile = FinancialCatalogue::profile($recipe);
        $facts = $this->facts([
            'forward_open' => true,
            'authorization_active' => true,
            'original_stock_reserved' => $stock,
            'original_recipient_delivered' => $delivery,
        ]);
        $this->assertSame($expected, Catalogue::registry()->evaluateCondition(
            $profile->condition('capture.permission'),
            $facts
        )->decision->verdict);
    }

    public function testBookingAndTechnicalCompletionCannotSatisfyTheDeliveryCapturePolicy(): void
    {
        $profile = FinancialCatalogue::profile(FinancialCatalogue::AFTER_DELIVERY);
        $facts = $this->facts([
            'forward_open' => true,
            'authorization_active' => true,
            'original_stock_reserved' => true,
            'booking_confirmed' => true,
            'child_run_completed' => true,
        ]);
        $decision = Catalogue::registry()->evaluateCondition(
            $profile->condition('capture.permission'),
            $facts
        )->decision;
        $this->assertSame(Verdict::Unknown, $decision->verdict);
    }

    public function testLostAuthorizationOrCancellationRefusesNewCaptureEvenAfterTheTriggerArrives(): void
    {
        foreach ([FinancialCatalogue::AFTER_STOCK, FinancialCatalogue::AFTER_DELIVERY] as $recipe) {
            $profile = FinancialCatalogue::profile($recipe);
            foreach ([[false, true], [true, false]] as [$open, $active]) {
                $facts = $this->facts([
                    'forward_open' => $open,
                    'authorization_active' => $active,
                    'original_stock_reserved' => true,
                    'original_recipient_delivered' => true,
                ]);
                $this->assertSame(Verdict::Unsatisfied, Catalogue::registry()->evaluateCondition(
                    $profile->condition('capture.permission'),
                    $facts
                )->decision->verdict);
            }
        }
    }

    public function testUnknownProfileCannotSelectACaptureTrigger(): void
    {
        $this->expectException(LogicException::class);
        FinancialCatalogue::profile('booking-is-delivery');
    }

    private function terms(): OrderTerms
    {
        return new OrderTerms(
            'shop-a',
            'purchase-1',
            'g1',
            1499,
            'USD',
            'sku-1',
            2,
            new AuthorizationTerms(1499, 'USD', 1001)
        );
    }

    private function facts(array $values): RecordedFacts
    {
        return new RecordedFacts('original-financial-snapshot', $values, array_fill_keys(
            array_keys($values),
            'original-authenticated-receipt'
        ));
    }
}
