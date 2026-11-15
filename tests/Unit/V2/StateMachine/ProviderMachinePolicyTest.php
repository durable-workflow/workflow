<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\BindingPlan;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;

require_once __DIR__ . '/../../../../examples/provider-state-machines/bootstrap.php';

final class ProviderMachinePolicyTest extends TestCase
{
    public function testPaymentRequiresCapturedFactsRatherThanShipmentCompletion(): void
    {
        $profile = Catalogue::profile('payment');
        $condition = $profile->condition('capture.target');
        $registry = Catalogue::registry();
        $facts = static fn (array $values): RecordedFacts => new RecordedFacts(
            'snapshot-1',
            $values,
            array_fill_keys(array_keys($values), 'provider-observation-1'),
        );
        $this->assertSame(Verdict::Unknown, $registry->evaluateCondition($condition, $facts([
            'forward_open' => true,
            'shipment_completed' => true,
        ]))->decision->verdict);
        $this->assertSame(Verdict::Unsatisfied, $registry->evaluateCondition($condition, $facts([
            'forward_open' => true,
            'capture_confirmed' => false,
        ]))->decision->verdict);
        $this->assertSame(Verdict::Satisfied, $registry->evaluateCondition($condition, $facts([
            'forward_open' => true,
            'capture_confirmed' => true,
        ]))->decision->verdict);
        $this->assertSame(['received', 'captured'], $profile->states);
        $this->assertSame([], $profile->definition->slots);
    }

    public function testCancellationIsProofOnlyAndRequiresSettledOriginalScope(): void
    {
        $transition = Catalogue::transition('order', 'cancel');
        $this->assertFalse($transition->hasWork);
        $facts = new RecordedFacts('cancel-snapshot', [
            'cancellation_requested' => true,
            'cancel_confirmed' => false,
        ], [
            'cancellation_requested' => 'cancel-1',
            'cancel_confirmed' => 'scope-requested',
        ]);
        $this->assertSame(Verdict::Unsatisfied, Catalogue::registry()->evaluateCondition(
            Catalogue::profile('order')->condition('cancel.target'),
            $facts,
        )->decision->verdict);
        $this->assertSame(['received', 'booked'], Catalogue::profile('shipment')->states);
    }

    public function testOriginalProviderCommandsAreStableAndRefundTheSameFullAmount(): void
    {
        $terms = $this->terms();
        $this->assertSame($terms->toArray(), OrderTerms::fromArray($terms->toArray())->toArray());
        foreach (['order', 'payment', 'shipment'] as $role) {
            $descriptor = $terms->descriptor($role);
            $forward = Request::fromArray($descriptor['forward']);
            $undo = Request::fromArray($descriptor['compensation']);
            $this->assertSame($forward->identity->toArray(), $undo->target->toArray());
            $this->assertSame($forward->terms, $undo->terms);
            $this->assertSame($descriptor, $terms->descriptor($role));
            $this->assertNotSame($forward->identity->key(), $undo->identity->key());
        }
        $this->assertSame([
            'amountMinor' => 1499,
            'currency' => 'USD',
        ], $terms->descriptor('payment')['forward']['terms']);
        $this->assertSame('before-handover', $terms->descriptor('shipment')['forward']['terms']['stage']);
        $this->assertNotSame($terms->subjectKey(), $this->terms('other-period')->subjectKey());
        $this->assertNotSame($terms->descriptor('payment'), $this->terms('other-period')->descriptor('payment'));
    }

    public static function invalidTerms(): array
    {
        return [
            'fractional amount' => ['amountMinor', 14.99],
            'zero amount' => ['amountMinor', 0],
            'negative amount' => ['amountMinor', -1],
            'string amount' => ['amountMinor', '1499'],
            'fractional quantity' => ['quantity', 1.5],
            'zero quantity' => ['quantity', 0],
            'lowercase currency' => ['currency', 'usd'],
            'missing currency' => ['currency', null],
        ];
    }

    #[DataProvider('invalidTerms')]
    public function testInvalidMoneyAndQuantityDoNotEnterTheBusinessPolicy(string $field, mixed $value): void
    {
        $input = $this->terms()
            ->toArray();
        $input[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        OrderTerms::fromArray($input);
    }

    public function testBothChildSlotsPinTheOriginalOwnerScopeAndProviderPolicyTogether(): void
    {
        $calls = 0;
        $plan = $this->plan($calls);
        foreach (['payment', 'shipment'] as $role) {
            $binding = $plan->binding($role);
            $this->assertSame(BindingPlan::VERSION, $plan->assertParticipationAllowed(
                $binding,
                $plan->participation($role),
                'worker-a',
                'reserve',
            ));
            $this->assertSame($plan->rootRun, $binding->parentRunId);
            $this->assertSame($plan->execution($role)->executionId, $binding->generation);
            $this->assertSame($plan->rootScope()->toArray(), $plan->participation($role)->parent->toArray());
        }
        $this->assertSame(2, $calls);
    }

    public function testAnIndependentlyValidForeignScopePairIsRefusedBeforeHostStorage(): void
    {
        $calls = 0;
        $plan = $this->plan($calls);
        $foreign = new ChildScopeParticipation(
            new ScopeIdentity(Catalogue::APPLICATION, Catalogue::NAMESPACE, 'foreign-order', 'original-run'),
            $plan->childScope('payment'),
            'payment',
        );
        try {
            $plan->assertParticipationAllowed($plan->binding('payment'), $foreign, 'worker-a', 'reserve');
            $this->fail('A separately valid scope must not be substituted for this binding owner.');
        } catch (LogicException) {
            $this->assertSame(0, $calls);
        }
    }

    public static function substitutions(): array
    {
        return [
            'input' => ['inputFingerprint', str_repeat('0', 64)],
            'run' => ['parentRunId', 'other-run'],
            'generation' => ['generation', 'other-entry'],
            'child policy' => ['childPolicyFingerprint', str_repeat('0', 64)],
            'business outcome' => ['requiredOutcome', 'refunded'],
        ];
    }

    #[DataProvider('substitutions')]
    public function testSubstitutedChildIdentityIsRefusedBeforeHostStorage(string $field, string $value): void
    {
        $calls = 0;
        $plan = $this->plan($calls);
        $payload = $plan->binding('payment')
            ->toArray();
        $payload[$field] = $value;
        try {
            $plan->assertParticipationAllowed(
                BindingIdentity::fromArray($payload),
                $plan->participation('payment'),
                'worker-a',
                'close',
            );
            $this->fail('The original child identity must be pinned.');
        } catch (LogicException) {
            $this->assertSame(0, $calls);
        }
    }

    private function terms(string $generation = 'purchase-1'): OrderTerms
    {
        return new OrderTerms('shop-a', 'order-1', $generation, 1499, 'USD', 'sku-1', 2);
    }

    private function plan(int &$calls): BindingPlan
    {
        return new BindingPlan($this->terms(), 'original-run', 'worker-a', static function () use (&$calls): void {
            ++$calls;
        });
    }
}
