<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Rules\ConditionRule;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\RuleRegistry;
use Workflow\V2\StateMachine\Rules\Verdict;

final class RuleRegistryTest extends TestCase
{
    public function testOriginalRuleVersionRemainsSelectedWhenANewerOneIsRegistered(): void
    {
        $registry = new RuleRegistry([
            'flag' => [
                '1' => new BooleanFactRule(),
                '2' => new BooleanFactRule(true),
            ],
        ]);
        $facts = $this->facts(true);
        $first = $registry->evaluate($this->rule(), $facts);
        $second = $registry->evaluate(new RuleReference('flag', '2', [
            'fact' => 'ready',
        ]), $facts);
        $this->assertSame(Verdict::Satisfied, $first->decision->verdict);
        $this->assertSame(Verdict::Unsatisfied, $second->decision->verdict);
        $this->assertSame('1', $first->rule->version);
        $this->assertSame(['receipt-1'], $first->decision->evidence);
    }

    public function testMissingFactsAndKnownNullHaveDistinctExplicitOutcomes(): void
    {
        $registry = new RuleRegistry([
            'flag' => [
                '1' => new BooleanFactRule(),
            ],
        ]);
        $missing = $registry->evaluate($this->rule(), new RecordedFacts('snapshot-missing', [], []));
        $knownNull = $registry->evaluate($this->rule(), $this->facts(null));
        $this->assertSame(Verdict::Unknown, $missing->decision->verdict);
        $this->assertSame('fact_missing', $missing->decision->reason);
        $this->assertSame(['ready'], $missing->missingFacts);
        $this->assertSame('fact_type_unknown', $knownNull->decision->reason);
        $this->assertSame([], $knownNull->missingFacts);
    }

    public function testEvaluationsDoNotUseAMutableLastResultChannel(): void
    {
        $registry = new RuleRegistry([
            'flag' => [
                '1' => new BooleanFactRule(),
            ],
        ]);
        $before = $registry->evaluate($this->rule(), $this->facts(false));
        $after = $registry->evaluate($this->rule(), new RecordedFacts('snapshot-2', [
            'ready' => true,
        ], [
            'ready' => 'receipt-2',
        ]));
        $this->assertNotSame($before, $after);
        $this->assertSame(Verdict::Unsatisfied, $before->decision->verdict);
        $this->assertSame('snapshot-1', $before->snapshotId);
        $this->assertSame(Verdict::Satisfied, $after->decision->verdict);
        $this->assertSame('snapshot-2', $after->snapshotId);
    }

    public function testParametersAndTheirVersionsContributeToACanonicalFingerprint(): void
    {
        $a = new RuleReference('flag', '1', [
            'fact' => 'ready',
            'limits' => [
                'min' => 1,
                'max' => 2,
            ],
        ]);
        $b = new RuleReference('flag', '1', [
            'limits' => [
                'max' => 2,
                'min' => 1,
            ],
            'fact' => 'ready',
        ]);
        $changed = new RuleReference('flag', '1', [
            'fact' => 'ready',
            'limits' => [
                'min' => 1,
                'max' => 3,
            ],
        ]);
        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $changed->fingerprint());
        $this->assertNotSame($a->fingerprint(), (new RuleReference('flag', '2', $a->parameters))->fingerprint());
    }

    public function testUnknownVersionsCannotFallBackToTheNewestRule(): void
    {
        $registry = new RuleRegistry([
            'flag' => [
                '2' => new BooleanFactRule(),
            ],
        ]);
        $this->expectExceptionMessage('original rule version is not registered');
        $registry->evaluate($this->rule(), $this->facts(true));
    }

    public function testParameterRefusalHappensBeforeEvaluation(): void
    {
        $rule = new BooleanFactRule();
        $registry = new RuleRegistry([
            'flag' => [
                '1' => $rule,
            ],
        ]);
        try {
            $registry->evaluate(new RuleReference('flag', '1', [
                'phpClass' => 'UnexpectedClass',
            ]), $this->facts(true));
            $this->fail('Unregistered configuration reached evaluation.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Expected the named fact parameter.', $error->getMessage());
        }
        $this->assertSame(0, $rule->evaluations);
    }

    public static function substitutions(): array
    {
        return [
            'different snapshot' => ['snapshot'],
            'different rule parameters' => ['rule'],
            'known fact reported missing' => ['missing'],
            'foreign evidence' => ['evidence'],
        ];
    }

    #[DataProvider('substitutions')]
    public function testAHandlerCannotSubstituteTheSnapshotOrConfiguration(string $substitution): void
    {
        $handler = new class($substitution) implements ConditionRule {
            public function __construct(
                private readonly string $substitution
            ) {
            }

            public function assertParameters(array $parameters): void
            {
            }

            public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
            {
                return new RuleEvaluation(
                    $this->substitution === 'rule' ? new RuleReference('flag', '1', [
                        'fact' => 'other',
                    ]) : $rule,
                    $this->substitution === 'snapshot' ? 'other-snapshot' : $facts->snapshotId,
                    new Decision(Verdict::Unknown, 'unknown', $this->substitution === 'evidence' ? [
                        'foreign-receipt',
                    ] : []),
                    $this->substitution === 'missing' ? ['ready'] : [],
                );
            }
        };
        $this->expectException(LogicException::class);
        (new RuleRegistry([
            'flag' => [
                '1' => $handler,
            ],
        ]))->evaluate($this->rule(), $this->facts(true));
    }

    public static function invalidReferences(): array
    {
        return [
            'PHP class' => ['App\\Rules\\LiveLookup', '1', []],
            'callable syntax' => ['App::run', '1', []],
            'empty version' => ['flag', '', []],
            'positional parameters' => ['flag', '1', ['ready']],
            'object parameter' => [
                'flag', '1', [
                    'value' => new \stdClass(),
                ]],
            'non-finite parameter' => [
                'flag', '1', [
                    'value' => INF,
                ]],
            'oversized parameters' => [
                'flag', '1', [
                    'value' => str_repeat('x', 17000),
                ]],
            'invalid UTF-8' => [
                'flag', '1', [
                    'value' => "\xff",
                ]],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function testConfigurationIsBoundedDataRatherThanExecutableInput(
        string $type,
        string $version,
        array $parameters
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new RuleReference($type, $version, $parameters);
    }

    public function testEvidenceMustCoverTheExactProvidedFactKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RecordedFacts('snapshot', [
            'ready' => true,
        ], [
            'other' => 'receipt',
        ]);
    }

    public static function invalidFactMetadata(): array
    {
        return [
            'missing snapshot identity' => [
                '', [
                    'ready' => true,
                ], [
                    'ready' => 'receipt',
                ]],
            'positional facts' => ['snapshot', [true], ['receipt']],
            'missing evidence reference' => [
                'snapshot', [
                    'ready' => true,
                ], [
                    'ready' => '',
                ]],
            'untyped evidence reference' => [
                'snapshot', [
                    'ready' => true,
                ], [
                    'ready' => 123,
                ]],
            'oversized evidence reference' => [
                'snapshot', [
                    'ready' => true,
                ], [
                    'ready' => str_repeat('x', 513),
                ]],
            'invalid evidence encoding' => [
                'snapshot', [
                    'ready' => true,
                ], [
                    'ready' => "\xff",
                ]],
        ];
    }

    #[DataProvider('invalidFactMetadata')]
    public function testFactsRequireBoundedTypedIdentityAndProvenance(
        string $snapshot,
        array $values,
        array $evidence
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new RecordedFacts($snapshot, $values, $evidence);
    }

    public static function malformedMissingFacts(): array
    {
        return [
            'named map' => [[
                'name' => 'ready',
            ]],
            'integer name' => [[123]],
            'object name' => [[new \stdClass()]],
            'duplicate name' => [['ready', 'ready']],
            'empty name' => [['']],
        ];
    }

    #[DataProvider('malformedMissingFacts')]
    public function testMissingFactMetadataCannotSmuggleObjectsOrDuplicateNames(array $missing): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RuleEvaluation($this->rule(), 'snapshot', new Decision(Verdict::Unknown, 'missing'), $missing);
    }

    public static function invalidRegistrations(): array
    {
        return [
            'class as type' => [[
                'App\\Rule' => [
                    '1' => new BooleanFactRule(),
                ],
            ]],
            'non-map versions' => [[
                'flag' => 'untrusted',
            ]],
            'class as handler' => [[
                'flag' => [
                    '1' => 'App\\Rule',
                ],
            ]],
            'empty version' => [[
                'flag' => [
                    '' => new BooleanFactRule(),
                ],
            ]],
        ];
    }

    #[DataProvider('invalidRegistrations')]
    public function testOnlyTrustedTypedVersionedHandlersCanBeRegistered(array $rules): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RuleRegistry($rules);
    }

    public function testMissingEvidenceCannotBecomeASatisfiedRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RuleEvaluation($this->rule(), 'snapshot', new Decision(Verdict::Satisfied, 'ready'), ['ready']);
    }

    public function testTheCompleteExplanationCrossesTheExistingAvroBoundary(): void
    {
        $result = (new RuleRegistry([
            'flag' => [
                '1' => new BooleanFactRule(),
            ],
        ]))->evaluate($this->rule(), $this->facts(true));
        $value = $result->toArray();
        $this->assertSame(
            $value,
            Serializer::unserializeWithCodec('avro', Serializer::serializeWithCodec('avro', $value))
        );
    }

    private function rule(): RuleReference
    {
        return new RuleReference('flag', '1', [
            'fact' => 'ready',
        ]);
    }

    private function facts(mixed $value): RecordedFacts
    {
        return new RecordedFacts('snapshot-1', [
            'ready' => $value,
        ], [
            'ready' => 'receipt-1',
        ]);
    }
}

/** Pure test rule; the counter observes whether invalid configuration reached evaluation. */
final class BooleanFactRule implements ConditionRule
{
    public int $evaluations = 0;

    public function __construct(
        private readonly bool $invert = false
    ) {
    }

    public function assertParameters(array $parameters): void
    {
        if (array_keys($parameters) !== ['fact'] || ! is_string($parameters['fact']) || $parameters['fact'] === '') {
            throw new InvalidArgumentException('Expected the named fact parameter.');
        }
    }

    public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
    {
        ++$this->evaluations;
        $name = $rule->parameters['fact'];
        if (! $facts->has($name)) {
            return new RuleEvaluation($rule, $facts->snapshotId, new Decision(Verdict::Unknown, 'fact_missing'), [
                $name,
            ]);
        }
        $value = $facts->values[$name];
        if (! is_bool($value)) {
            return new RuleEvaluation($rule, $facts->snapshotId, new Decision(Verdict::Unknown, 'fact_type_unknown'));
        }
        $satisfied = $this->invert ? ! $value : $value;
        return new RuleEvaluation($rule, $facts->snapshotId, new Decision(
            $satisfied ? Verdict::Satisfied : Verdict::Unsatisfied,
            $satisfied ? 'fact_satisfied' : 'fact_unsatisfied',
            [$facts->evidence[$name]],
        ));
    }
}
