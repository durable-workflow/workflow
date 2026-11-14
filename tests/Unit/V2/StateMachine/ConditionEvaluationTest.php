<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Rules\ConditionEvaluation;
use Workflow\V2\StateMachine\Rules\ConditionRule;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\RuleRegistry;
use Workflow\V2\StateMachine\Rules\Verdict;

final class ConditionEvaluationTest extends TestCase
{
    public static function truthTable(): array
    {
        return [
            ['satisfied', 'satisfied', 'satisfied', 'satisfied'],
            ['satisfied', 'unsatisfied', 'unsatisfied', 'satisfied'],
            ['satisfied', 'unknown', 'unknown', 'satisfied'],
            ['unsatisfied', 'satisfied', 'unsatisfied', 'satisfied'],
            ['unsatisfied', 'unsatisfied', 'unsatisfied', 'unsatisfied'],
            ['unsatisfied', 'unknown', 'unsatisfied', 'unknown'],
            ['unknown', 'satisfied', 'unknown', 'satisfied'],
            ['unknown', 'unsatisfied', 'unsatisfied', 'unknown'],
            ['unknown', 'unknown', 'unknown', 'unknown'],
        ];
    }

    #[DataProvider('truthTable')]
    public function testAllAndAnyPreserveThreeValuedLogicAndBothExplanations(
        string $left,
        string $right,
        string $all,
        string $any,
    ): void {
        $facts = new RecordedFacts('snapshot', [
            'left' => $left,
            'right' => $right,
        ], [
            'left' => 'receipt-left',
            'right' => 'receipt-right',
        ]);
        $children = [$this->leaf('left'), $this->leaf('right')];
        $allResult = $this->registry()
            ->evaluateCondition(ConditionDefinition::all($children), $facts);
        $anyResult = $this->registry()
            ->evaluateCondition(ConditionDefinition::any($children), $facts);
        $this->assertSame($all, $allResult->decision->verdict->value);
        $this->assertSame($any, $anyResult->decision->verdict->value);
        $this->assertSame([$left, $right], array_map(
            static fn (ConditionEvaluation $child): string => $child->decision->verdict->value,
            $allResult->children,
        ));
        $this->assertSame('snapshot', $anyResult->snapshotId);
    }

    public static function negations(): array
    {
        return [['satisfied', 'unsatisfied'], ['unsatisfied', 'satisfied'], ['unknown', 'unknown']];
    }

    #[DataProvider('negations')]
    public function testNegationDoesNotConvertUnknownToPermission(string $input, string $expected): void
    {
        $facts = new RecordedFacts('snapshot', [
            'flag' => $input,
        ], [
            'flag' => 'receipt',
        ]);
        $result = $this->registry()
            ->evaluateCondition(ConditionDefinition::not($this->leaf('flag')), $facts);
        $this->assertSame($expected, $result->decision->verdict->value);
    }

    public function testAFalseConditionDoesNotEraseAnotherConditionsMissingFact(): void
    {
        $definition = ConditionDefinition::all([$this->leaf('left'), $this->leaf('missing')]);
        $result = $this->registry()
            ->evaluateCondition($definition, new RecordedFacts(
                'snapshot',
                [
                    'left' => 'unsatisfied',
                ],
                [
                    'left' => 'receipt-left',
                ],
            ));
        $this->assertSame(Verdict::Unsatisfied, $result->decision->verdict);
        $this->assertSame(Verdict::Unknown, $result->children[1]->decision->verdict);
        $this->assertSame(['missing'], $result->toArray()['children'][1]['missingFacts']);
    }

    public function testTheWholeConditionStructureAndPinnedVersionsRemainInThePortableExplanation(): void
    {
        $definition = ConditionDefinition::all([$this->leaf('left'), ConditionDefinition::not($this->leaf('right'))]);
        $result = $this->registry()
            ->evaluateCondition($definition, new RecordedFacts(
                'snapshot',
                [
                    'left' => 'satisfied',
                    'right' => 'unsatisfied',
                ],
                [
                    'left' => 'receipt-left',
                    'right' => 'receipt-right',
                ],
            ));
        $value = $result->toArray();
        $this->assertSame(
            $value,
            Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $value))
        );
        $this->assertSame($definition->fingerprint(), $value['configurationFingerprint']);
        $this->assertSame('1', $value['children'][0]['rule']['version']);
        $this->assertSame('not', $value['children'][1]['kind']);
        $this->assertSame(['receipt-left', 'receipt-right'], $result->decision->evidence);
    }

    public function testReorderingOrReplacingConditionsChangesThePinnedStructure(): void
    {
        $left = $this->leaf('left');
        $right = $this->leaf('right');
        $this->assertNotSame(
            ConditionDefinition::all([$left, $right])->fingerprint(),
            ConditionDefinition::all([$right, $left])->fingerprint()
        );
        $this->assertNotSame(
            ConditionDefinition::all([$left, $right])->fingerprint(),
            ConditionDefinition::any([$left, $right])->fingerprint()
        );
    }

    public static function malformedGroups(): array
    {
        return [
            'empty' => [[]],
            'named map' => [[
                'named' => self::exampleLeaf(),
            ]],
            'untyped object' => [[new \stdClass()]],
        ];
    }

    #[DataProvider('malformedGroups')]
    public function testMalformedConditionGroupsRefuseBeforeRuleEvaluation(array $children): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConditionDefinition::all($children);
    }

    public function testTheConditionNodeBudgetCannotBeExceeded(): void
    {
        $this->expectExceptionMessage('condition structure exceeds its node limit');
        ConditionDefinition::all(array_fill(0, ConditionDefinition::MAX_NODES, $this->leaf('ready')));
    }

    public function testResultsFromDifferentSnapshotsCannotBeGrouped(): void
    {
        $condition = ConditionDefinition::all([$this->leaf('left'), $this->leaf('right')]);
        $first = $this->registry()
            ->evaluateCondition($condition->children[0], new RecordedFacts(
                'first',
                [
                    'left' => 'satisfied',
                ],
                [
                    'left' => 'receipt-left',
                ],
            ));
        $second = $this->registry()
            ->evaluateCondition($condition->children[1], new RecordedFacts(
                'second',
                [
                    'right' => 'satisfied',
                ],
                [
                    'right' => 'receipt-right',
                ],
            ));
        $this->expectException(InvalidArgumentException::class);
        ConditionEvaluation::group($condition, [$first, $second]);
    }

    public function testMissingChildrenCannotProduceACompoundResult(): void
    {
        $condition = ConditionDefinition::all([$this->leaf('left'), $this->leaf('right')]);
        $first = $this->registry()
            ->evaluateCondition($condition->children[0], new RecordedFacts(
                'first',
                [
                    'left' => 'satisfied',
                ],
                [
                    'left' => 'receipt-left',
                ],
            ));
        $this->expectException(InvalidArgumentException::class);
        ConditionEvaluation::group($condition, [$first]);
    }

    public function testALeafCannotBorrowAnotherRulesExplanation(): void
    {
        $result = $this->registry()
            ->evaluate(new RuleReference('test', '1', [
                'fact' => 'left',
            ]), new RecordedFacts(
                'snapshot',
                [
                    'left' => 'satisfied',
                ],
                [
                    'left' => 'receipt-left',
                ],
            ));
        $this->expectException(InvalidArgumentException::class);
        ConditionEvaluation::leaf($this->leaf('right'), $result);
    }

    private static function exampleLeaf(): ConditionDefinition
    {
        return ConditionDefinition::rule(new RuleReference('test', '1', [
            'fact' => 'ready',
        ]));
    }

    private function leaf(string $name): ConditionDefinition
    {
        return ConditionDefinition::rule(new RuleReference('test', '1', [
            'fact' => $name,
        ]));
    }

    private function registry(): RuleRegistry
    {
        return new RuleRegistry([
            'test' => [
                '1' => new class() implements ConditionRule {
                    public function assertParameters(array $parameters): void
                    {
                        if (array_keys($parameters) !== ['fact'] || ! is_string($parameters['fact'])) {
                            throw new InvalidArgumentException('Invalid fact reference.');
                        }
                    }

                    public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
                    {
                        $name = $rule->parameters['fact'];
                        return $facts->has($name) ? new RuleEvaluation($rule, $facts->snapshotId, new Decision(
                            Verdict::from($facts->values[$name]),
                            'recorded_fact',
                            [$facts->evidence[$name]],
                        )) : new RuleEvaluation($rule, $facts->snapshotId, new Decision(
                            Verdict::Unknown,
                            'missing'
                        ), [$name]);
                    }
                },
            ],
        ]);
    }
}
