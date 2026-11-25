<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Definition\ChildSlot;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionResult;
use Workflow\V2\StateMachine\Execution\TransitionStage;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Inspection\MachineInspection;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;

final class MachineInspectionTest extends TestCase
{
    public function testOneViewCorrelatesTheBlockedTransitionChildWorkAndCompensation(): void
    {
        $profile = self::profile();
        $begin = self::execution($profile, 'begin-1', 'Received', '1');
        $finish = self::execution($profile, 'finish-1', 'Working', '2');
        $blocked = new TransitionResult(
            $finish,
            TransitionStatus::Blocked,
            'shipment_not_delivered',
            new Decision(Verdict::Unsatisfied, 'shipment_not_delivered', ['carrier-status:42']),
            'finish',
            TransitionStage::Permission,
            'snapshot-7',
            SnapshotPhase::Before,
        );
        $admitted = self::binding($begin, 'entry-1');
        $receipt = new StartedChildWorkflow(
            'namespace',
            'order-instance',
            'order-run',
            3,
            'shipment-instance',
            'shipment-run',
            'call-1',
            'example.shipment',
        );
        $closed = new NeverAdmittedChild(self::reservation(self::binding($begin, 'entry-2')), 'closure-1');
        $reserved = self::reservation(self::binding($begin, 'entry-3'));
        $scope = new ScopeIdentity('orders', 'namespace', 'subject-1', 'order-run');
        $childScope = new ScopeIdentity('orders', 'namespace', 'subject-1.shipment', 'entry-1');
        $participating = self::reservation($admitted, new ChildScopeParticipation($scope, $childScope, 'shipment'));
        $description = self::description();

        $inspection = new MachineInspection(
            $profile,
            'Working',
            '2',
            $finish,
            $blocked,
            [new ChildBinding($admitted, $receipt), $closed, $reserved, $participating],
            [[
                'scope' => $scope,
                'description' => $description,
            ], [
                'scope' => $childScope,
                'description' => $description,
            ]],
        );
        $view = $inspection->toArray();

        $this->assertSame(1, $view['version']);
        $this->assertSame([
            'machine' => 'orders',
            'definitionVersion' => '1',
            'profile' => 'carrier-a',
            'profileVersion' => '1',
            'policyFingerprint' => $profile->fingerprint(),
            'definitionFingerprint' => $profile->definition?->fingerprint(),
        ], $view['machine']);
        $this->assertSame([
            'state' => 'Working',
            'revision' => '2',
        ], $view['business']);
        $this->assertSame($finish->toArray(), $view['execution']);
        $this->assertSame($blocked->explain(), $view['transition']);
        $this->assertSame('permission', $view['transition']['stage']);
        $this->assertSame([
            'status' => 'admitted',
            'binding' => $admitted->key(),
            'slot' => 'shipment',
            'generation' => 'entry-1',
            'hostingState' => 'Working',
            'childWorkflowType' => 'example.shipment',
            'requiredOutcome' => 'delivered',
            'parentExecutionId' => 'begin-1',
            'childInstanceId' => 'shipment-instance',
            'childRunId' => 'shipment-run',
            'closureId' => null,
            // The admitted child's own reservation links it to the listed parent and child scopes.
            'participation' => [
                'parentScopeId' => $scope->id(),
                'childScopeId' => $childScope->id(),
                'obligationKey' => 'shipment',
            ],
        ], $view['children'][0]);
        $this->assertCount(3, $view['children']);
        $this->assertSame([$scope->id(), $childScope->id()], array_column($view['compensation'], 'scopeId'));
        $this->assertSame(
            ['never_admitted', $closed->reservation->childInstanceId(), null, 'closure-1'],
            [
                $view['children'][1]['status'],
                $view['children'][1]['childInstanceId'],
                $view['children'][1]['childRunId'],
                $view['children'][1]['closureId'],
            ]
        );
        $this->assertSame(['reserved', $reserved->childInstanceId(), null], [
            $view['children'][2]['status'],
            $view['children'][2]['childInstanceId'],
            $view['children'][2]['participation'],
        ]);
        $this->assertSame([
            'scopeId' => $scope->id(),
            'scope' => $scope->toArray(),
            ...$description,
        ], $view['compensation'][0]);
        $this->assertSame($view, json_decode(json_encode($view, JSON_THROW_ON_ERROR), true));
    }

    public function testAnInspectionWithoutTransitionChildrenOrScopesIsStillExplicit(): void
    {
        $profile = self::profile();
        $view = (new MachineInspection($profile, 'Received', '1', self::execution(
            $profile,
            'begin-1',
            'Received',
            '1'
        )))->toArray();

        $this->assertNull($view['transition']);
        $this->assertSame([], $view['children']);
        $this->assertSame([], $view['compensation']);
    }

    public static function substitutions(): array
    {
        return [
            'undeclared state' => ['state'],
            'empty revision' => ['revision'],
            'execution under another profile' => ['profile'],
            'transition of another execution' => ['transition'],
            'child of another subject' => ['child-subject'],
            'child under another profile' => ['child-profile'],
            'repeated child generation' => ['child-repeat'],
            'admitted and never-admitted generation' => ['child-contradiction'],
            'closure next to its reservation' => ['child-closure-reservation'],
            'reservation with a changed identity' => ['child-changed'],
            'untyped child' => ['child-untyped'],
            'child map' => ['child-map'],
            'malformed scope description' => ['scope-description'],
            'non-portable scope description value' => ['scope-object'],
            'malformed refusal' => ['scope-refusal'],
            'malformed unresolved obligation' => ['scope-unresolved'],
            'untyped scope' => ['scope-untyped'],
            'repeated scope' => ['scope-repeat'],
        ];
    }

    #[DataProvider('substitutions')]
    public function testForeignOrMalformedPartsAreRefusedRatherThanMerged(string $case): void
    {
        $profile = self::profile();
        $execution = self::execution($profile, 'finish-1', 'Working', '2');
        $state = 'Working';
        $revision = '2';
        $transition = null;
        $children = [];
        $compensation = [];
        $child = self::binding($execution, 'entry-1');
        $scope = [
            'scope' => new ScopeIdentity('orders', 'namespace', 'subject-1', 'order-run'),
            'description' => self::description(),
        ];
        match ($case) {
            'state' => $state = 'Shipped',
            'revision' => $revision = ' ',
            'profile' => $profile = self::profile('carrier-b'),
            'transition' => $transition = new TransitionResult(
                self::execution($profile, 'other-1', 'Working', '2'),
                TransitionStatus::Unavailable,
                'transition_unavailable',
                stage: TransitionStage::Selection,
            ),
            'child-subject' => $children = [self::reservation(self::binding(
                new ExecutionReference('orders', 'subject-2', 'finish-1', $profile->fingerprint(), 'Working', '2'),
                'entry-1'
            ))],
            'child-profile' => $children = [self::reservation(self::binding(
                self::execution(self::profile('carrier-b'), 'finish-1', 'Working', '2'),
                'entry-1'
            ))],
            'child-repeat' => $children = [self::reservation($child), self::reservation($child)],
            'child-contradiction' => $children = [
                new ChildBinding($child, new StartedChildWorkflow(
                    'namespace',
                    'order-instance',
                    'order-run',
                    3,
                    'shipment-instance',
                    'shipment-run',
                    'call-1',
                    'example.shipment',
                )),
                new NeverAdmittedChild(self::reservation($child), 'closure-1'),
            ],
            'child-closure-reservation' => $children = [
                new NeverAdmittedChild(self::reservation($child), 'closure-1'),
                self::reservation($child),
            ],
            'child-changed' => $children = [
                self::reservation($child),
                self::reservation(BindingIdentity::fromArray([
                    ...$child->toArray(),
                    'inputFingerprint' => str_repeat('c', 64),
                ])),
            ],
            'child-untyped' => $children = [$child],
            'child-map' => $children = [
                'shipment' => self::reservation($child),
            ],
            'scope-description' => $compensation = [[
                'scope' => $scope['scope'],
                'description' => [
                    ...self::description(),
                    'extra' => true,
                ],
            ]],
            'scope-object' => $compensation = [[
                'scope' => $scope['scope'],
                'description' => [
                    ...self::description(),
                    'recoveryRunId' => new \stdClass(),
                ],
            ]],
            'scope-refusal' => $compensation = [[
                'scope' => $scope['scope'],
                'description' => [
                    ...self::description(),
                    'refusal' => [
                        'code' => 'recovery_authorization_failed',
                    ],
                ],
            ]],
            'scope-unresolved' => $compensation = [[
                'scope' => $scope['scope'],
                'description' => [
                    ...self::description(),
                    'unresolved' => [[
                        'key' => 'shipment',
                        'status' => 'pending',
                    ]],
                ],
            ]],
            'scope-untyped' => $compensation = [[
                'scope' => $scope['scope']->toArray(),
                'description' => self::description(),
            ]],
            'scope-repeat' => $compensation = [$scope, $scope],
        };
        $this->expectException(InvalidArgumentException::class);
        new MachineInspection($profile, $state, $revision, $execution, $transition, $children, $compensation);
    }

    public function testASnapshotIdRequiresItsPhase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TransitionResult(
            self::execution(self::profile(), 'finish-1', 'Working', '2'),
            TransitionStatus::Pending,
            'target_unsatisfied',
            snapshotId: 'snapshot-1',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function description(): array
    {
        return [
            'scopeStatus' => 'requested',
            'originStatus' => 'completed',
            'recoveryRunId' => 'recovery-run',
            'recoveryStatus' => 'waiting',
            'automaticRecovery' => 'routed',
            'refusal' => null,
            'nextAction' => 'observe_recovery',
            'unresolved' => [[
                'key' => 'shipment',
                'status' => 'pending',
                'reason' => 'carrier_refund_processing',
            ]],
        ];
    }

    private static function profile(string $name = 'carrier-a'): PolicyProfile
    {
        $definition = new MachineDefinition(
            'orders',
            '1',
            [new MachineState('Received', 'Order accepted.'), new MachineState('Working', 'Shipment admitted.')],
            ['begin', 'finish'],
            ConditionDefinition::rule(new RuleReference('example.boolean', '1')),
            [new ChildSlot('shipment', ['Working'], 'shipments', '1', 'delivered')],
        );
        return PolicyProfile::forDefinition($definition, $name, '1', [
            new TransitionDefinition('begin', 'begin', ['Received'], 'Working', 'begin', '1'),
            new TransitionDefinition('finish', 'finish', ['Working'], 'Received', 'finish', '1'),
        ]);
    }

    private static function execution(
        PolicyProfile $profile,
        string $id,
        string $state,
        string $revision
    ): ExecutionReference {
        return new ExecutionReference('orders', 'subject-1', $id, $profile->fingerprint(), $state, $revision);
    }

    private static function binding(ExecutionReference $parent, string $generation): BindingIdentity
    {
        return new BindingIdentity(
            $parent,
            'namespace',
            'order-instance',
            'order-run',
            'shipment',
            $generation,
            'Working',
            'example.shipment',
            str_repeat('a', 64),
            'delivered',
            ChildReservation::inputFingerprint(['shipment-' . $generation]),
        );
    }

    private static function reservation(
        BindingIdentity $identity,
        ?ChildScopeParticipation $participation = null
    ): ChildReservation {
        return new ChildReservation(
            $identity,
            'App\\Shipment',
            'sha256:' . str_repeat('b', 64),
            'policy-1',
            $participation,
            $participation === null ? null : 'joint-policy-1',
        );
    }
}
