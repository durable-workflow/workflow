<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestDefinedMachineWorkflow;
use Workflow\V2\StateMachine\Definition\ChildSlot;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\DefinitionLimits;
use Workflow\V2\StateMachine\Definition\ExecutionLimits;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ResourceLimitExceeded;

final class ResourceLimitsTest extends TestCase
{
    public function testExactGraphBoundaryIsPinnedAndLegacyFingerprintsDoNotChange(): void
    {
        $old = TestDefinedMachineWorkflow::definition();
        $profile = TestDefinedMachineWorkflow::profile();
        $limits = new DefinitionLimits(2, 1, 0, 1);
        $definition = new MachineDefinition(
            $old->machine,
            '2',
            $old->states,
            $old->transitionKeys,
            $old->invariants,
            limits: $limits
        );
        $limited = PolicyProfile::forDefinition(
            $definition,
            'prepare',
            '2',
            $profile->transitions,
            limits: new ExecutionLimits(8, 100, 65_536)
        );
        $this->assertSame($limits->toArray(), $definition->toArray()['limits']);
        $this->assertArrayNotHasKey('limits', $old->toArray());
        $this->assertSame($profile->fingerprint(), TestDefinedMachineWorkflow::profile()->fingerprint());
        $this->assertNotSame($profile->fingerprint(), $limited->fingerprint());
        $changed = PolicyProfile::forDefinition(
            $definition,
            'prepare',
            '2',
            $profile->transitions,
            limits: new ExecutionLimits(9, 100, 65_536)
        );
        $this->assertNotSame($limited->fingerprint(), $changed->fingerprint());
        $this->assertSame($limited->fingerprint(), PolicyProfile::forDefinition(
            $definition,
            'prepare',
            '2',
            $profile->transitions,
            limits: new ExecutionLimits(8, 100, 65_536)
        )->fingerprint());
    }

    public static function graphDimensions(): array
    {
        return [
            'states' => ['states', new DefinitionLimits(1, 1, 0, 1)],
            'transition catalogue' => ['transitions', new DefinitionLimits(2, 0, 0, 1)],
            'child slots' => ['child_slots', new DefinitionLimits(2, 1, 0, 1)],
            'condition structure' => ['rule_nodes', new DefinitionLimits(2, 1, 0, 1)],
        ];
    }

    #[DataProvider('graphDimensions')]
    public function testOversizedSharedDefinitionsHaveSpecificRefusals(
        string $dimension,
        DefinitionLimits $limits
    ): void {
        $old = TestDefinedMachineWorkflow::definition();
        $invariant = $dimension === 'rule_nodes' ? ConditionDefinition::not($old->invariants) : $old->invariants;
        $slots = $dimension === 'child_slots' ? [
            new ChildSlot('shipment', ['Received'], 'shipments', '1', 'delivered'),
        ] : [];
        $refusal = null;
        try {
            new MachineDefinition($old->machine, '2', $old->states, $old->transitionKeys, $invariant, $slots, $limits);
        } catch (ResourceLimitExceeded $error) {
            $refusal = $error;
        }
        $this->assertInstanceOf(ResourceLimitExceeded::class, $refusal);
        $this->assertSame($dimension, $refusal->dimension);
        $this->assertSame('state_machine_' . $dimension . '_limit_exceeded', $refusal->reason());
        $this->assertGreaterThan($refusal->limit, $refusal->observed);
    }

    public function testProfileCannotEvadeTheEdgeBudgetWithSeveralSourceStates(): void
    {
        $old = TestDefinedMachineWorkflow::definition();
        $definition = new MachineDefinition(
            $old->machine,
            '2',
            $old->states,
            ['process'],
            $old->invariants,
            limits: new DefinitionLimits(2, 1, 0, 64)
        );
        $edge = new TransitionDefinition('process', 'received', [
            'Received',
            'Processing',
        ], 'Processing', 'prepare', '1');
        $this->expectException(ResourceLimitExceeded::class);
        $this->expectExceptionMessage('[transitions]');
        PolicyProfile::forDefinition($definition, 'prepare', '2', [$edge]);
    }

    public function testProfileConditionsShareTheMachineRuleBudget(): void
    {
        $old = TestDefinedMachineWorkflow::definition();
        $definition = new MachineDefinition(
            $old->machine,
            '2',
            $old->states,
            ['process'],
            $old->invariants,
            limits: new DefinitionLimits(2, 1, 0, 1)
        );
        $this->expectException(ResourceLimitExceeded::class);
        $this->expectExceptionMessage('[rule_nodes]');
        PolicyProfile::forDefinition($definition, 'prepare', '2', [], [
            'extra' => $old->invariants,
        ]);
    }

    public static function invalidExecutionLimits(): array
    {
        return [[-1, null, null, 1], [4097, null, null, 1], [1, -1, null, 1],
            [1, null, -1, 1], [1, null, null, 0], [1, null, null, 65]];
    }

    #[DataProvider('invalidExecutionLimits')]
    public function testInvalidExecutionBudgetsRefuse(int $commands, ?int $events, ?int $bytes, int $depth): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutionLimits($commands, $events, $bytes, $depth);
    }
}
