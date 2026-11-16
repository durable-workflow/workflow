<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CartReservationTerms;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use DurableWorkflow\Examples\ProviderStateMachines\CartLeaseOperation;
use DurableWorkflow\Examples\ProviderStateMachines\CartPlan;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class CartCheckoutPlanTest extends TestCase
{
    public function testThePurchaseOwnerSurvivesAssociationWithADistinctFormalOrderReference(): void
    {
        $plan = $this->plan();
        $terms = $this->terms();
        $plan->assertOrder($terms);
        $this->assertSame('cart-before-order', $plan->initial->terms->cartId);
        $this->assertSame('formal-order-2026-1', $plan->orderReference);
        $this->assertSame('purchase-a', $terms->subject);
        $this->assertSame($plan->fingerprint(), CartPlan::fromArray($plan->toArray())->fingerprint());
        $this->assertSame(2800, $plan->initial->terms->expiresAt);
        $this->assertSame(4600, $plan->paymentObservationDeadline);
        $this->assertSame(4900, $plan->late->terms->expiresAt);
        $this->assertNotSame($plan->obligation('initial')->fingerprint(), $plan->obligation('late')->fingerprint());
        $this->assertSame($plan->initial->reserve->identity->toArray(), $plan->late->terms->previous->toArray());
    }

    public function testAFormalOrderCannotSelectAnotherGenerationOfTheCart(): void
    {
        $plan = $this->plan();
        $terms = $this->terms('g2');
        $this->expectException(InvalidArgumentException::class);
        $plan->assertOrder($terms);
    }

    public function testTheLateWindowCannotQuietlyExtendTheOriginalPaymentDeadline(): void
    {
        $plan = $this->plan();
        $terms = $this->terms('g1', 4601);
        $this->expectException(InvalidArgumentException::class);
        $plan->assertOrder($terms);
    }

    public function testALateReservationCannotUseAFormerCartWithDifferentStock(): void
    {
        $initial = $this->plan()
->initial;
        $late = $this->lease('late', 2800, 4900, $initial->reserve->identity, 'foreign-sku');
        $this->expectException(InvalidArgumentException::class);
        new CartPlan('formal-order-2026-1', $initial, $late, 4600);
    }

    public function testAnUnrecognizedLeaseStepCannotChooseAnUnregisteredResponsibility(): void
    {
        $plan = $this->plan();
        $this->expectException(InvalidArgumentException::class);
        $plan->obligation('replacement');
    }

    public function testReverseCancellationBeforeTheFirstCommandPositivelyFencesBothLeases(): void
    {
        $plan = $this->plan();
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a', static fn (): int => 1000);
        $provider->install();
        $provider->cartInventory()
            ->install();
        $operation = $this->operation($provider, $plan);
        $this->assertTrue($operation->compensate($this->context($plan, 'late'))->verified);
        $this->assertTrue($operation->compensate($this->context($plan, 'initial'))->verified);
        foreach ([$plan->initial, $plan->late] as $lease) {
            $result = $provider->attempt($lease->reserve);
            $this->assertSame(Status::NotExecuted, $result->status);
            $this->assertTrue($result->admissionClosed);
        }
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testFencingTheUnusedLateLeaseDoesNotSettleAnUncertainOriginalHold(): void
    {
        $plan = $this->plan();
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a', static fn (): int => 1000);
        $provider->install();
        $provider->cartInventory()
            ->install();
        $provider->cartInventory()
            ->receive('sku-a', 'receipt', 2);
        $operation = $this->operation($provider, $plan);
        $provider->plan($plan->initial->reserve, Status::Unknown);
        $this->assertFalse($operation->execute($this->context($plan, 'initial'))->observed);
        $this->assertTrue($operation->compensate($this->context($plan, 'late'))->verified);
        $this->assertFalse($operation->compensate($this->context($plan, 'initial'))->verified);
        $this->assertSame(0, $provider->cartInventory()->inspect($plan->initial)['availableQuantity']);
        $provider->resolve($plan->initial->reserve, Status::Confirmed);
        $provider->plan($plan->initial->release, Status::Confirmed);
        $this->assertTrue($operation->compensate($this->context($plan, 'initial'))->verified);
        $this->assertSame(2, $provider->cartInventory()->inspect($plan->initial)['availableQuantity']);
        $this->assertSame(2, (int) $db->query('SELECT COUNT(*) FROM provider_effects')->fetchColumn());
    }

    public function testHostRefusalPrecedesEvenTheNeverAdmittedProviderFences(): void
    {
        $plan = $this->plan();
        $db = new PDO('sqlite::memory:');
        $provider = new Journal($db, 'shop-a');
        $provider->install();
        $provider->cartInventory()
            ->install();
        $operation = new CartLeaseOperation($provider, static function (): CartPlan {
            throw new LogicException('The original host grant was revoked.');
        });
        try {
            $operation->compensate($this->context($plan, 'late'));
            $this->fail('Revocation must refuse before any provider access.');
        } catch (LogicException $error) {
            $this->assertSame('The original host grant was revoked.', $error->getMessage());
        }
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM provider_operations')->fetchColumn());
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) FROM provider_cart_roots')->fetchColumn());
    }

    private function plan(): CartPlan
    {
        $initial = $this->lease('initial', 1000, 2800);
        return new CartPlan(
            'formal-order-2026-1',
            $initial,
            $this->lease('late', 2800, 4900, $initial->reserve->identity),
            4600
        );
    }

    private function lease(
        string $step,
        int $createdAt,
        int $expiresAt,
        ?Identity $previous = null,
        string $sku = 'sku-a'
    ): CartReservationDescriptor {
        $id = static fn (string $action): Identity => new Identity('shop-a', 'purchase-a', 'g1', $step . '.' . $action);
        $reserve = $id('reserve');
        $release = $id('release');
        $terms = (new CartReservationTerms(
            'cart-before-order',
            $sku,
            2,
            $createdAt,
            $expiresAt,
            $release->operation,
            $previous
        ))->toArray();
        return new CartReservationDescriptor(new Request($reserve, 'reserve', $terms), new Request(
            $release,
            'release',
            $terms,
            $reserve
        ));
    }

    private function terms(string $generation = 'g1', int $paymentDeadline = 4600): OrderTerms
    {
        $incoming = new IncomingPaymentDescriptor(
            new Identity('shop-a', 'purchase-a', $generation, 'incoming'),
            'payment-reference',
            1499,
            'USD',
            $paymentDeadline
        );
        return new OrderTerms(
            'shop-a',
            'purchase-a',
            $generation,
            1499,
            'USD',
            'sku-a',
            2,
            fulfillmentDeadline: 5200,
            incomingPayment: $incoming
        );
    }

    private function operation(Journal $provider, CartPlan $plan): CartLeaseOperation
    {
        $scope = new ScopeIdentity('cart-source-unit', 'cart-example', $this->terms()->subjectKey(), 'native-original');
        return new CartLeaseOperation($provider, static function (
            OperationContext $context,
            string $mode
        ) use ($plan, $scope): CartPlan {
            if ($context->originalRunId !== 'native-original' || $context->scope->toArray() !== $scope->toArray()
                || ! in_array($mode, ['forward', 'compensate'], true)) {
                throw new LogicException('The original host grant does not cover this scope or plan.');
            }
            return $plan;
        });
    }

    private function context(CartPlan $plan, string $step): OperationContext
    {
        return new OperationContext(
            new ScopeIdentity('cart-source-unit', 'cart-example', $this->terms()->subjectKey(), 'native-original'),
            $plan->obligation($step),
            'original-operation-' . $step,
            'native-original',
            'activity-' . $step,
            'attempt-1',
            false
        );
    }
}
