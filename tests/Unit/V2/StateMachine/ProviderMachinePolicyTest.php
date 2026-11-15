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
    public function testReservationReconciliationCannotDispatchAnAlreadyRegisteredOperation(): void
    {
        $condition = Catalogue::profile('order', Catalogue::PAYMENT_FIRST)->condition('reserve.permission');
        foreach ([
            false => Verdict::Unsatisfied,
            true => Verdict::Satisfied,
        ] as $open => $expected) {
            $facts = new RecordedFacts(
                'reservation-snapshot',
                [
                    'forward_open' => true,
                    'reservation_dispatch_open' => (bool) $open,
                ],
                [
                    'forward_open' => 'original-owner',
                    'reservation_dispatch_open' => 'original-reservation-admission',
                ]
            );
            $this->assertSame(
                $expected,
                Catalogue::registry()->evaluateCondition($condition, $facts)->decision->verdict
            );
        }
    }

    public function testExistingProviderProfilesKeepTheirAcceptedFingerprints(): void
    {
        // Captured from the accepted predecessor source, before introducing recipe selection.
        foreach ([
            'order' => '015a3f53d1b1a7285bd3cf73074f64523fff28ef85aac9226616aacf719b0243',
            'payment' => 'a0834bea346015a028003b2a6cb580aa6074e75897d466a755d6b0fa6a5e6af2',
            'shipment' => 'bfbafc9b46772a7823d0c7fd0936779a3a37665b5da3042b7585fdeec4b0c1df',
        ] as $role => $fingerprint) {
            $this->assertSame($fingerprint, Catalogue::profile($role)->fingerprint());
        }
    }

    public function testPaymentFirstRecipeChangesRootEntryButKeepsOriginalChildPolicies(): void
    {
        $legacy = Catalogue::profile('order');
        $selected = Catalogue::profile('order', Catalogue::PAYMENT_FIRST);
        $this->assertNotSame($legacy->fingerprint(), $selected->fingerprint());
        foreach (['payment', 'shipment'] as $role) {
            $this->assertSame(
                Catalogue::profile($role)->fingerprint(),
                Catalogue::profile($role, Catalogue::PAYMENT_FIRST)->fingerprint()
            );
        }
        $plan = new BindingPlan($this->terms(), 'original-run', 'worker-a', static function (): void {
        }, Catalogue::PAYMENT_FIRST);
        $this->assertSame('received', $plan->execution('payment')->sourceState);
        $this->assertSame('1', $plan->execution('payment')->sourceRevision);
        $this->assertSame('reserved', $plan->execution('shipment')->sourceState);
        $this->assertSame('3', $plan->execution('shipment')->sourceRevision);
        $this->assertSame($selected->fingerprint(), $plan->binding('payment')->parentExecution->policyFingerprint);
        $this->assertNotSame(BindingPlan::VERSION, $plan->version());
        $this->assertSame(['paid'], Catalogue::transition('order', 'reserve', Catalogue::PAYMENT_FIRST)->from);
    }

    public function testForeignRecipeBindingIsRefusedBeforeCallingOriginalOwner(): void
    {
        $calls = 0;
        $original = new BindingPlan($this->terms(), 'original-run', 'worker-a', static function () use (&$calls): void {
            ++$calls;
        });
        $changed = new BindingPlan($this->terms(), 'original-run', 'worker-a', static function (): void {
        }, Catalogue::PAYMENT_FIRST);
        $identity = $changed->binding('payment');
        try {
            $original->assertAllowed($identity, 'worker-a', 'reserve');
            $this->fail('A different policy must not reuse the original admission authority.');
        } catch (LogicException $error) {
            $this->assertSame(
                'The child binding does not belong to this original business generation.',
                $error->getMessage()
            );
        }
        $this->assertSame(0, $calls);
    }

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
