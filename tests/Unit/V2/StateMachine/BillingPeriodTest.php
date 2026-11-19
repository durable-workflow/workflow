<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\AuthorizationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\LostAcknowledgement;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriod;
use DurableWorkflow\Examples\ProviderStateMachines\BillingPeriodPolicy;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class BillingPeriodTest extends TestCase
{
    public function testServiceBillingDoesNotInventAnOrderOrShipment(): void
    {
        $period = $this->period();
        $this->assertNull($period->order);
        $this->assertNull($period->toArray()['order']);
        $descriptor = $period->paymentDescriptor();
        $this->assertSame(['forward', 'compensation'], array_keys($descriptor));
        $this->assertSame('capture', $descriptor['forward']['action']);
        $this->assertSame('refund', $descriptor['compensation']['action']);
        $this->assertSame($descriptor['forward']['identity'], $descriptor['compensation']['target']);
        $this->assertSame($period->fingerprint(), BillingPeriod::fromArray($period->toArray())->fingerprint());
    }

    public function testEachPeriodCanPinAGenuineDistinctOrderWithoutChangingItsPaymentIdentity(): void
    {
        $period = $this->period();
        $order = new OrderTerms('shop-a', 'actual-order-2026-11', $period->ownerGeneration(), 1499, 'USD', 'sku-a', 2);
        $value = $period->toArray();
        $value['order'] = $order->toArray();
        $associated = BillingPeriod::fromArray($value);
        $this->assertSame('actual-order-2026-11', $associated->order->subject);
        $this->assertSame($period->paymentDescriptor(), $associated->paymentDescriptor());
        $this->assertNotSame($period->fingerprint(), $associated->fingerprint());
    }

    public function testAnOrderFromAnotherPeriodCannotReplaceTheOriginalAssociation(): void
    {
        $period = $this->period();
        $value = $period->toArray();
        $value['order'] = (new OrderTerms(
            'shop-a',
            'other-period-order',
            'foreign-period',
            1499,
            'USD',
            'sku-a',
            2
        ))->toArray();
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::fromArray($value);
    }

    public function testTheSameNamedPeriodOfAnotherSubscriptionCannotTakeTheOriginalOrder(): void
    {
        $period = $this->period();
        $value = $period->toArray();
        $value['order'] = (new OrderTerms(
            'shop-a',
            'actual-order',
            $period->ownerGeneration(),
            1499,
            'USD',
            'sku-a',
            2
        ))->toArray();
        $value['subscription'] = 'foreign-subscription';
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::fromArray($value);
    }

    public function testAnAssociatedAuthorizationCannotBecomeAnImmediateRecurringCapture(): void
    {
        $period = $this->period();
        $value = $period->toArray();
        $value['order'] = (new OrderTerms(
            'shop-a',
            'actual-order',
            $period->ownerGeneration(),
            1499,
            'USD',
            'sku-a',
            2,
            new AuthorizationTerms(1499, 'USD', 2000)
        ))->toArray();
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::fromArray($value);
    }

    public function testLostAcknowledgementAndANewConsumerKeepOneCommittedChargeForTheOriginalPeriod(): void
    {
        $period = $this->period();
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $provider->install();
        $charge = Request::fromArray($period->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed, true);
        try {
            $provider->attempt($charge);
            $this->fail('The original acknowledgement must be lost after the provider commit.');
        } catch (LostAcknowledgement) {
            $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        }
        $recovered = BillingPeriod::fromArray($period->toArray());
        $replacement = new Journal($db, 'shop-a');
        $result = $replacement->attempt(Request::fromArray($recovered->paymentDescriptor()['forward']));
        $this->assertSame(Status::Confirmed, $result->status);
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        $this->assertSame(
            2,
            (int) $db->query("SELECT COUNT(*) FROM provider_requests WHERE kind = 'command'")
                ->fetchColumn()
        );
    }

    public function testARefundCannotSubstituteTheNextBillingPeriod(): void
    {
        $original = $this->period();
        $value = $original->toArray();
        $value['period'] = '2026-12';
        $next = BillingPeriod::fromArray($value);
        $this->assertNotSame($original->subjectKey(), $next->subjectKey());
        $refund = $next->paymentDescriptor()['compensation'];
        $refund['target'] = $original->paymentDescriptor()['forward']['identity'];
        $this->expectException(InvalidArgumentException::class);
        Request::fromArray($refund);
    }

    public function testChangingTheMoneyCannotReuseAnAlreadyCommittedPeriodCharge(): void
    {
        $period = $this->period();
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $provider->install();
        $charge = Request::fromArray($period->paymentDescriptor()['forward']);
        $provider->plan($charge, Status::Confirmed);
        $provider->attempt($charge);
        $value = $period->toArray();
        $value['amountMinor'] = 2499;
        $substituted = BillingPeriod::fromArray($value);
        try {
            $provider->attempt(Request::fromArray($substituted->paymentDescriptor()['forward']));
            $this->fail('A committed period charge cannot acquire different terms.');
        } catch (\LogicException) {
            $this->assertSame(1, (int) $db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
        }
    }

    public function testTheOriginalGraceDeadlineSurvivesDecodingAndExpiresAtTheExactBoundary(): void
    {
        $period = $this->period();
        $deadline = $period->paymentDeadline;
        $this->assertSame($period->startsAt + 864000, $deadline);
        $this->assertTrue(BillingPeriodPolicy::access($period, $deadline - 1, null)['access']);
        $recovered = BillingPeriod::fromArray($period->toArray());
        $atDeadline = BillingPeriodPolicy::access($recovered, $deadline, null);
        $this->assertFalse($atDeadline['access']);
        $this->assertSame($deadline, $atDeadline['deadline']);
        $this->assertTrue(BillingPeriodPolicy::access($recovered, $deadline, $deadline - 1)['access']);
        $late = BillingPeriodPolicy::access($recovered, $deadline + 1, $deadline);
        $this->assertTrue($late['latePayment']);
        $this->assertFalse($late['access']);
        $this->assertFalse(BillingPeriodPolicy::access($recovered, $period->endsAt, $deadline - 1)['access']);
    }

    public function testPaymentFirstAndGraceAccessChoicesDoNotRedefineTheCommonPaymentStates(): void
    {
        $grace = $this->period();
        $value = $grace->toArray();
        $value['activationPolicy'] = BillingPeriod::PAYMENT_FIRST;
        $value['accessDuringGrace'] = false;
        $first = BillingPeriod::fromArray($value);
        $this->assertFalse(BillingPeriodPolicy::access($first, $first->startsAt, null)['access']);
        $this->assertTrue(BillingPeriodPolicy::access($first, $first->startsAt, $first->startsAt)['access']);
        $common = FundingCatalogue::payment(FundingCatalogue::IMMEDIATE);
        foreach ([$grace, $first] as $period) {
            $profile = BillingPeriodPolicy::paymentProfile($period);
            $this->assertSame($common->definition->fingerprint(), $profile->definition->fingerprint());
            $this->assertSame($common->definition->toArray(), $profile->definition->toArray());
        }
        $value = $grace->toArray();
        $value['accessDuringGrace'] = false;
        $this->assertFalse(BillingPeriodPolicy::access(
            BillingPeriod::fromArray($value),
            $grace->startsAt,
            null
        )['access']);
    }

    public static function malformed(): array
    {
        return [
            'floating amount' => ['amountMinor', 1499.0],
            'text deadline' => ['paymentDeadline', '864100'],
            'unknown policy' => ['activationPolicy', 'retry-starts-grace'],
            'expired period' => ['endsAt', 99],
            'deadline outside period' => ['paymentDeadline', 3000100],
            'unknown field' => ['technicalAttempt', 'new-charge'],
            'untyped access' => ['accessDuringGrace', 1],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedOrExtendedPeriodAssignmentsAreRefused(string $field, mixed $replacement): void
    {
        $value = $this->period()
            ->toArray();
        $value[$field] = $replacement;
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::fromArray($value);
    }

    public function testGraceArithmeticCannotOverflowOrUseAnEmptyInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BillingPeriod::graceDeadline(PHP_INT_MAX - 1, 2);
    }

    private function period(): BillingPeriod
    {
        return new BillingPeriod(
            'shop-a',
            'service-subscription',
            'subscription-generation-1',
            '2026-11',
            1499,
            'USD',
            100,
            3000100,
            BillingPeriod::graceDeadline(100),
            BillingPeriod::GRACE,
            true
        );
    }
}
