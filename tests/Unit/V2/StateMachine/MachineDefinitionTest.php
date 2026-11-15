<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestDefinedMachineWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Definition\ChildSlot;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\ConditionRule;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\RuleRegistry;
use Workflow\V2\StateMachine\Rules\Verdict;

final class MachineDefinitionTest extends TestCase
{
    public static function invalidDefinitions(): array
    {
        return [
            'empty machine' => ['machine', ''],
            'empty version' => ['version', ' '],
            'empty catalogue' => ['states', []],
            'untyped state' => ['states', ['Received']],
            'duplicate state' => ['duplicate-state', null],
            'non-list catalogue' => ['state-map', null],
            'non-list transition keys' => [
                'transitionKeys', [
                    'process' => 'process',
                ]],
            'empty transition key' => ['transitionKeys', [' ']],
            'untyped transition key' => ['transitionKeys', [1]],
            'duplicate transition key' => ['transitionKeys', ['process', 'process']],
            'non-list slots' => ['slot-map', null],
            'untyped slot' => ['slots', ['shipment']],
            'duplicate slot' => ['duplicate-slot', null],
        ];
    }

    #[DataProvider('invalidDefinitions')]
    public function testMalformedSharedConfigurationRefusesBeforeAProfileCanUseIt(string $field, mixed $value): void
    {
        $state = new MachineState('Received', 'Original input accepted.');
        $slot = new ChildSlot('shipment', ['Received'], 'shipments', '1', 'delivered');
        $data = [
            'machine' => 'orders',
            'version' => '1',
            'states' => [$state],
            'transitionKeys' => ['process'],
            'slots' => [$slot],
        ];
        match ($field) {
            'duplicate-state' => $data['states'] = [$state, $state],
            'state-map' => $data['states'] = [
                'received' => $state,
            ],
            'slot-map' => $data['slots'] = [
                'shipment' => $slot,
            ],
            'duplicate-slot' => $data['slots'] = [$slot, $slot],
            default => $data[$field] = $value,
        };
        $this->expectException(InvalidArgumentException::class);
        new MachineDefinition(
            $data['machine'],
            $data['version'],
            $data['states'],
            $data['transitionKeys'],
            TestDefinedMachineWorkflow::definition()->invariants,
            $data['slots']
        );
    }

    public static function invalidSlots(): array
    {
        return [
            ['', ['Received'], 'shipments', '1', 'delivered'],
            ['shipment', ['Received'], '', '1', 'delivered'],
            ['shipment', ['Received'], 'shipments', '', 'delivered'],
            ['shipment', ['Received'], 'shipments', '1', ''],
            ['shipment', [], 'shipments', '1', 'delivered'],
            ['shipment', [
                'received' => 'Received',
            ], 'shipments', '1', 'delivered'],
            ['shipment', [' '], 'shipments', '1', 'delivered'],
            ['shipment', [1], 'shipments', '1', 'delivered'],
            ['shipment', ['Received', 'Received'], 'shipments', '1', 'delivered'],
        ];
    }

    #[DataProvider('invalidSlots')]
    public function testMalformedSlotCannotBeUsedAsANativeAdmissionContract(
        string $key,
        array $states,
        string $machine,
        string $version,
        string $outcome
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new ChildSlot($key, $states, $machine, $version, $outcome);
    }

    public function testAStateWithoutBusinessMeaningIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MachineState('Received', ' ');
    }

    public function testSharedDefinitionKeepsTheExistingUnicodeIdentifierSupport(): void
    {
        $definition = new MachineDefinition(
            '注文',
            '版一',
            [new MachineState('受付', '入力は受け付けられました。')],
            ['処理'],
            TestDefinedMachineWorkflow::definition()->invariants
        );
        $profile = new PolicyProfile('注文', '版一', '販売者', '版一', ['受付'], [
            new TransitionDefinition('処理', '受信', ['受付'], '受付', '処理ハンドラ', '1', false),
        ], definition: $definition);
        $this->assertSame(['受付'], $profile->states);
        $this->assertSame('入力は受け付けられました。', $definition->states[0]->meaning);
    }

    public function testProfilesShareStateMeaningAndInvariantsWhileChoosingDifferentEdges(): void
    {
        $prepare = TestDefinedMachineWorkflow::profile();
        $import = TestDefinedMachineWorkflow::profile('imported');
        $this->assertSame($prepare->definition->fingerprint(), $import->definition->fingerprint());
        $this->assertSame($prepare->states, $import->states);
        $this->assertNotSame($prepare->fingerprint(), $import->fingerprint());
        $this->assertSame('Business input has been accepted.', $prepare->definition->states[0]->meaning);
        $changed = new MachineDefinition('example', '1', [new MachineState('Received', 'Different business meaning.'),
            new MachineState('Processing', 'The original provider operation is confirmed.')], [
                'process',
            ], $prepare->definition->invariants);
        $profile = new PolicyProfile(
            'example',
            '1',
            'prepare',
            '1',
            $changed->stateIds(),
            $prepare->transitions,
            definition: $changed
        );
        $this->assertNotSame($prepare->fingerprint(), $profile->fingerprint());
    }

    public function testDefinitionBoundProfilesDoNotReuseTheLegacyProfileFingerprint(): void
    {
        $profile = TestDefinedMachineWorkflow::profile();
        $legacy = new PolicyProfile(
            $profile->machine,
            $profile->definitionVersion,
            $profile->profile,
            $profile->profileVersion,
            $profile->states,
            $profile->transitions
        );
        $this->assertNull($legacy->definition);
        $this->assertNotSame($legacy->fingerprint(), $profile->fingerprint());
    }

    public static function foreignProfiles(): array
    {
        return [['other', '1', ['Received', 'Processing'], 'process'],
            ['example', '2', ['Received', 'Processing'], 'process'],
            ['example', '1', ['Received', 'Changed'], 'process'],
            ['example', '1', ['Received', 'Processing'], 'undeclared'],
        ];
    }

    #[DataProvider('foreignProfiles')]
    public function testAPolicyCannotReplaceItsSharedMachineCatalogueOrIntroduceAnUnknownTransition(
        string $machine,
        string $version,
        array $states,
        string $key,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new PolicyProfile(
            $machine,
            $version,
            'imported',
            '1',
            $states,
            [new TransitionDefinition($key, 'received', [$states[0]], $states[1], 'import', '1')],
            definition: TestDefinedMachineWorkflow::definition()
        );
    }

    public function testDefinitionSnapshotsCallerReferencesInsteadOfRetainingMutableArrays(): void
    {
        $state = new MachineState('Received', 'Input accepted.');
        $hosting = 'Received';
        $edge = 'process';
        $states = [&$state];
        $hosts = [&$hosting];
        $edges = [&$edge];
        $slot = new ChildSlot('shipment', $hosts, 'shipments', '1', 'delivered');
        $slots = [&$slot];
        $definition = new MachineDefinition(
            'example',
            '1',
            $states,
            $edges,
            TestDefinedMachineWorkflow::definition()->invariants,
            $slots
        );
        $fingerprint = $definition->fingerprint();
        $state = new MachineState('Foreign', 'Other meaning.');
        $hosting = 'Foreign';
        $edge = 'foreign';
        $slot = new ChildSlot('foreign', ['Foreign'], 'other', '1', 'not-delivered');
        $this->assertSame($fingerprint, $definition->fingerprint());
        $this->assertSame(['Received'], $definition->stateIds());
        $this->assertSame(['Received'], $definition->slot('shipment')->hostingStates);
        $this->assertSame(['process'], $definition->transitionKeys);
    }

    public function testRegisteredRulesProduceAnInvariantDecisionAndCompleteRecordedExplanation(): void
    {
        $definition = TestDefinedMachineWorkflow::definition();
        $rule = new class() implements ConditionRule {
            public function assertParameters(array $parameters): void
            {
            }

            public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
            {
                return new RuleEvaluation($rule, $facts->snapshotId, new Decision(
                    $facts->values['current'] ? Verdict::Satisfied : Verdict::Unsatisfied,
                    'current_owner_assessed',
                    [$facts->evidence['current']]
                ));
            }
        };
        $facts = new RecordedFacts('original-snapshot', [
            'current' => false,
        ], [
            'current' => 'owner-revocation-1',
        ]);
        $evaluation = $definition->assess(new RuleRegistry([
            'example.owner-current' => [
                '1' => $rule,
            ],
        ]), $facts);
        $assessment = MachineAssessment::fromEvaluation($definition, $evaluation);
        $this->assertSame(Verdict::Unsatisfied, $assessment->decision->verdict);
        $this->assertSame($definition->invariants->fingerprint(), $evaluation->toArray()['configurationFingerprint']);
        $this->assertSame(['owner-revocation-1'], $assessment->decision->evidence);
        $portable = Serializer::unserializeWithCodec(
            'avro',
            Serializer::serializeWithCodec('avro', $assessment->toArray())
        );
        $this->assertSame($assessment->toArray(), MachineAssessment::fromArray($portable, $definition)->toArray());
        $this->assertTrue($assessment->matches($definition, 'original-snapshot'));
        $this->assertFalse($assessment->matches($definition, 'different-snapshot'));
    }

    public static function malformedAssessments(): array
    {
        return [['version', '1'], ['extra', 'data'], [
            'decision',
            [
                'verdict' => 'not-a-verdict',
                'reason' => 'invalid',
                'evidence' => [],
            ]],
            [
                'decision',
                [
                    'verdict' => 'satisfied',
                    'reason' => 'invalid',
                    'evidence' => [
                        'named' => 'evidence',
                    ],
                ]], ['snapshotId', null]];
    }

    #[DataProvider('malformedAssessments')]
    public function testMalformedRecordedAssessmentsFailClosed(string $field, mixed $value): void
    {
        $payload = TestDefinedMachineWorkflow::assessment('snapshot')->toArray();
        $payload[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        MachineAssessment::fromArray($payload, TestDefinedMachineWorkflow::definition());
    }

    public function testTheDeclaredSlotBindsItsParentProfileHostingStateAndChildBusinessContract(): void
    {
        [$definition,$parent,$child,$binding] = $this->binding();
        $definition->assertBinding($parent, $binding, $child);
        $this->assertSame('shipments', $definition->slot('shipment')->childMachine);
        $this->assertSame('delivered', $definition->slot('shipment')->requiredOutcome);
    }

    public static function foreignBindings(): array
    {
        return [['hostingState', 'Received'],
            ['childPolicyFingerprint', null],
            ['requiredOutcome', 'technically-completed'],
            ['slot', 'missing']];
    }

    #[DataProvider('foreignBindings')]
    public function testSlotCompatibilityCannotStandInForAChangedChildOrOutcome(string $field, mixed $value): void
    {
        [$definition,$parent,$child,$binding] = $this->binding();
        $data = $binding->toArray();
        $data[$field] = $value ?? str_repeat('f', 64);
        $this->expectException(LogicException::class);
        $definition->assertBinding($parent, BindingIdentity::fromArray($data), $child);
    }

    public function testASlotCannotHostAnUndeclaredParentState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MachineDefinition(
            'example',
            '1',
            [new MachineState('Received', 'Input accepted.')],
            ['process'],
            TestDefinedMachineWorkflow::definition()->invariants,
            [new ChildSlot('shipment', ['Foreign'], 'shipments', '1', 'delivered')]
        );
    }

    private function binding(): array
    {
        $base = TestDefinedMachineWorkflow::definition();
        $definition = new MachineDefinition(
            $base->machine,
            $base->version,
            $base->states,
            $base->transitionKeys,
            $base->invariants,
            [new ChildSlot('shipment', ['Processing'], 'shipments', '1', 'delivered')]
        );
        $parent = new PolicyProfile(
            'example',
            '1',
            'prepare',
            '1',
            $definition->stateIds(),
            TestDefinedMachineWorkflow::profile()->transitions,
            definition: $definition
        );
        $child = new PolicyProfile('shipments', '1', 'carrier', '1', ['Queued', 'Delivered'], []);
        $binding = new BindingIdentity(
            new ExecutionReference('scope', 'subject', 'execution', $parent->fingerprint(), 'Received', '1'),
            'namespace',
            'parent-instance',
            'parent-run',
            'shipment',
            'entry-1',
            'Processing',
            'example.shipment.v1',
            $child->fingerprint(),
            'delivered',
            ChildReservation::inputFingerprint(['shipment-1'])
        );
        return [$definition, $parent, $child, $binding];
    }
}
