<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Rules\ConditionEvaluation;
use Workflow\V2\StateMachine\Rules\ConditionRule;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\RuleRegistry;
use Workflow\V2\StateMachine\Rules\Verdict;

final class ImmutableRuleDataTest extends TestCase
{
    public function testCanonicalEncodingDoesNotRewriteReferencedCallerArrays(): void
    {
        $nested = [
            'z' => 1,
            'a' => 2,
        ];
        $input = [
            'nested' => &$nested,
        ];
        $this->assertSame('{"nested":{"a":2,"z":1}}', PortableDescriptor::encode($input));
        $this->assertSame(['z', 'a'], array_keys($nested));
    }

    public function testRecordedFactsDetachNestedValuesAndEvidenceFromCallerReferences(): void
    {
        $value = 7;
        $evidence = 'booking-receipt-1';
        $facts = new RecordedFacts('snapshot-1', [
            'booking' => [
                'amount' => &$value,
                'ratio' => 1.0,
                'optional' => null,
                'label' => '配送',
            ],
        ], [
            'booking' => &$evidence,
        ]);
        $original = json_encode($facts->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $value = 999;
        $evidence = 'foreign-receipt';
        $this->assertSame($original, json_encode($facts->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame(7, $facts->values['booking']['amount']);
        $this->assertSame(1.0, $facts->values['booking']['ratio']);
        $this->assertNull($facts->values['booking']['optional']);
        $this->assertSame('配送', $facts->values['booking']['label']);
    }

    public function testRuleParametersKeepTheirOriginalFingerprintAfterCallerMutation(): void
    {
        $minimum = 7;
        $rule = new RuleReference('booking.minimum', '1', [
            'limits' => [
                'minimum' => &$minimum,
            ],
        ]);
        $original = $rule->fingerprint();
        $minimum = 999;
        $this->assertSame($original, $rule->fingerprint());
        $this->assertSame(7, $rule->parameters['limits']['minimum']);
    }

    public function testDecisionAndMissingFactReferencesCannotRewriteAnExplanation(): void
    {
        $evidence = 'receipt-1';
        $missing = 'carrier-ack';
        $decision = new Decision(Verdict::Unknown, 'awaiting_carrier', [&$evidence]);
        $result = new RuleEvaluation(new RuleReference('booking', '1'), 'snapshot-1', $decision, [&$missing]);
        $original = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        $evidence = 'foreign-receipt';
        $missing = 'another-fact';
        $this->assertSame($original, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testConditionChildrenCannotBeReplacedOrTurnedIntoACycleAfterValidation(): void
    {
        $child = ConditionDefinition::rule(new RuleReference('booking', '1'));
        $originalChild = $child;
        $condition = ConditionDefinition::all([&$child]);
        $fingerprint = $condition->fingerprint();
        $child = ConditionDefinition::rule(new RuleReference('booking', '2'));
        $this->assertSame($fingerprint, $condition->fingerprint());
        $child = $condition;
        $this->assertSame($originalChild, $condition->children[0]);
        $this->assertNotSame($condition, $condition->children[0]);
    }

    public function testCompoundExplanationsKeepTheirValidatedChildSnapshot(): void
    {
        $leaf = ConditionDefinition::rule(new RuleReference('booking', '1'));
        $configuration = ConditionDefinition::all([$leaf]);
        $child = ConditionEvaluation::leaf($leaf, new RuleEvaluation(
            $leaf->rule,
            'snapshot-1',
            new Decision(Verdict::Satisfied, 'booking_ready'),
        ));
        $group = ConditionEvaluation::group($configuration, [&$child]);
        $original = json_encode($group->toArray(), JSON_THROW_ON_ERROR);
        $child = ConditionEvaluation::leaf($leaf, new RuleEvaluation(
            $leaf->rule,
            'foreign-snapshot',
            new Decision(Verdict::Unsatisfied, 'booking_missing'),
        ));
        $this->assertSame($original, json_encode($group->toArray(), JSON_THROW_ON_ERROR));
        $this->assertSame('snapshot-1', $group->children[0]->snapshotId);
    }

    public function testProfileKeepsItsOriginalStatesEdgesAndConditions(): void
    {
        $from = 'Received';
        $to = 'Processing';
        $transition = new TransitionDefinition('process', 'received', [&$from], $to, 'prepare', '1');
        $condition = ConditionDefinition::rule(new RuleReference('booking', '1'));
        $profile = new PolicyProfile('orders', '1', 'carrier-a', '1', ['Received', &$to], [&$transition], [
            'ready' => &$condition,
        ]);
        $fingerprint = $profile->fingerprint();
        $from = 'Foreign';
        $to = 'Cancelled';
        $transition = new TransitionDefinition('cancel', 'cancelled', ['Received'], 'Cancelled', 'cancel', '2');
        $condition = ConditionDefinition::rule(new RuleReference('booking', '2'));
        $this->assertSame($fingerprint, $profile->fingerprint());
        $this->assertSame(['Received', 'Processing'], $profile->states);
        $this->assertSame(['Received'], $profile->transitions[0]->from);
        $this->assertSame('1', $profile->condition('ready')->rule->version);
    }

    public function testRegistryRetainsItsOriginalVersionMap(): void
    {
        $implementation = self::rule(Verdict::Satisfied);
        $registry = new RuleRegistry([
            'booking' => [
                '1' => &$implementation,
            ],
        ]);
        $implementation = self::rule(Verdict::Unsatisfied);
        $result = $registry->evaluate(new RuleReference('booking', '1'), new RecordedFacts('snapshot-1', [], []));
        $this->assertSame(Verdict::Satisfied, $result->decision->verdict);
    }

    private static function rule(Verdict $verdict): ConditionRule
    {
        return new class($verdict) implements ConditionRule {
            public function __construct(
                private readonly Verdict $verdict
            ) {
            }

            public function assertParameters(array $parameters): void
            {
            }

            public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
            {
                return new RuleEvaluation($rule, $facts->snapshotId, new Decision($this->verdict, 'booking_result'));
            }
        };
    }
}
