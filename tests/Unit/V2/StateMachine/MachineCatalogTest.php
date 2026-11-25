<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Definition\ChildSlot;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\InvalidMachineCatalog;
use Workflow\V2\StateMachine\Definition\MachineCatalog;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\ResourceLimitExceeded;

final class MachineCatalogTest extends TestCase
{
    public function testTheSameChildDefinitionCanBeBoundFromDifferentParentStatesByConfiguration(): void
    {
        $shipment = self::machine('shipments', '1');
        $order = self::machine('orders', '1', [self::slot('delivery', 'shipments', '1', ['Working'])]);
        $returns = self::machine('returns', '1', [self::slot('pickup', 'shipments', '1', ['Received'])]);
        $catalog = new MachineCatalog([$order, $returns, $shipment], maxChildDepth: 1);

        $this->assertSame([$order, $returns, $shipment], $catalog->definitions);
        $this->assertSame($shipment, $catalog->child($order, 'delivery'));
        $this->assertSame($shipment, $catalog->child($returns, 'pickup'));
        $this->assertSame($order, $catalog->definition('orders', '1'));
        $this->assertSame(1, $catalog->childDepth($order));
        $this->assertSame(0, $catalog->childDepth($shipment));
        $this->assertSame(1, $catalog->maxChildDepth);
    }

    public function testADuplicateMachineVersionIsRefusedExplicitly(): void
    {
        $this->assertRefusal(
            InvalidMachineCatalog::DUPLICATE_DEFINITION,
            ['orders@1'],
            [self::machine('orders', '1'), self::machine('orders', '1')]
        );
    }

    public function testAnUnregisteredChildSlotIsRefusedWithItsDeclaredRoute(): void
    {
        $this->assertRefusal(
            InvalidMachineCatalog::UNREGISTERED_CHILD,
            ['orders@1', 'delivery:shipments@2'],
            [self::machine('orders', '1', [self::slot('delivery', 'shipments', '2')]), self::machine('shipments', '1')]
        );
    }

    public static function cyclicCatalogs(): array
    {
        return [
            'self hosting' => [
                ['orders@1', 'reorder:orders@1'],
                [self::machine('orders', '1', [self::slot('reorder', 'orders', '1')])],
            ],
            'direct cycle' => [
                ['orders@1', 'delivery:shipments@1', 'parent:orders@1'],
                [
                    self::machine('orders', '1', [self::slot('delivery', 'shipments', '1')]),
                    self::machine('shipments', '1', [self::slot('parent', 'orders', '1')]),
                ],
            ],
            // A machine identity recurring under another version is still a recursive child machine.
            'recursion through another version' => [
                ['orders@2', 'delivery:shipments@1', 'resend:orders@1'],
                [
                    self::machine('orders', '2', [self::slot('delivery', 'shipments', '1')]),
                    self::machine('shipments', '1', [self::slot('resend', 'orders', '1')]),
                    self::machine('orders', '1'),
                ],
            ],
            // The shipment subtree is analysed first; reusing it below orders@2 must still find the cycle.
            'cycle through an analysed subtree' => [
                ['orders@2', 'delivery:shipments@1', 'pickup:returns@1', 'replacement:orders@1'],
                [
                    self::machine('shipments', '1', [self::slot('pickup', 'returns', '1')]),
                    self::machine('returns', '1', [self::slot('replacement', 'orders', '1')]),
                    self::machine('orders', '1'),
                    self::machine('orders', '2', [self::slot('delivery', 'shipments', '1')]),
                ],
            ],
        ];
    }

    /**
     * @param list<string> $path
     * @param list<MachineDefinition> $definitions
     */
    #[DataProvider('cyclicCatalogs')]
    public function testCyclicAndRecursiveChildBindingsAreRefusedBeforeAnyBindingCanUseThem(
        array $path,
        array $definitions
    ): void {
        $this->assertRefusal(InvalidMachineCatalog::CHILD_BINDING_CYCLE, $path, $definitions);
    }

    public function testAConfiguredChildDepthIsAnExplicitResourceOutcome(): void
    {
        $definitions = [
            self::machine('orders', '1', [self::slot('delivery', 'shipments', '1')]),
            self::machine('shipments', '1', [self::slot('parcel', 'parcels', '1')]),
            self::machine('parcels', '1'),
        ];
        $this->assertSame(2, (new MachineCatalog($definitions, maxChildDepth: 2))->childDepth($definitions[0]));
        $this->assertSame(2, (new MachineCatalog($definitions))->childDepth($definitions[0]));

        try {
            new MachineCatalog($definitions, maxChildDepth: 1);
            $this->fail('A nesting depth beyond the configured limit was accepted.');
        } catch (ResourceLimitExceeded $refusal) {
            $this->assertSame('child_depth', $refusal->dimension);
            $this->assertSame(2, $refusal->observed);
            $this->assertSame(1, $refusal->limit);
            $this->assertSame('state_machine_child_depth_limit_exceeded', $refusal->reason());
        }
        $this->expectException(ResourceLimitExceeded::class);
        new MachineCatalog([self::machine('parcels', '1'), $definitions[1]], maxChildDepth: 0);
    }

    public function testEachDefinitionIsAnalysedOnceWhenSubtreesAreShared(): void
    {
        // Each layer's two versions host both versions of the next layer: 2^40 routes, 80 definitions.
        $definitions = [self::machine('layer40', '1'), self::machine('layer40', '2')];
        for ($layer = 39; $layer >= 1; $layer--) {
            $next = 'layer' . ($layer + 1);
            foreach (['1', '2'] as $version) {
                $definitions[] = self::machine(
                    'layer' . $layer,
                    $version,
                    [self::slot('first', $next, '1'), self::slot('second', $next, '2')]
                );
            }
        }
        $catalog = new MachineCatalog($definitions, maxChildDepth: 39);

        $this->assertSame(39, $catalog->childDepth($catalog->definition('layer1', '2')));
    }

    public static function invalidCatalogs(): array
    {
        return [
            'empty' => [[], null],
            'map' => [[
                'orders' => self::machine('orders', '1'),
            ], null],
            'untyped' => [['orders@1'], null],
            'negative depth' => [[self::machine('orders', '1')], -1],
            'depth over the maximum' => [[self::machine('orders', '1')], MachineCatalog::MAX_CHILD_DEPTH + 1],
        ];
    }

    #[DataProvider('invalidCatalogs')]
    public function testMalformedCatalogConfigurationRefuses(array $definitions, ?int $depth): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MachineCatalog($definitions, $depth);
    }

    public function testCatalogBindingRequiresTheRegisteredParentAndChildDefinitions(): void
    {
        [$catalog, $parent, $child, $binding] = $this->binding();
        $catalog->assertBinding($parent, $binding, $child);

        $impostor = PolicyProfile::forDefinition(
            self::machine('shipments', '1', meaning: 'A different business meaning.'),
            'carrier',
            '1',
            []
        );
        $this->assertBindingRefused($catalog, $parent, $this->rebind($binding, $impostor), $impostor);

        $unbound = new PolicyProfile('shipments', '1', 'carrier', '1', ['Received', 'Working'], []);
        $this->assertBindingRefused($catalog, $parent, $this->rebind($binding, $unbound), $unbound);

        $legacyParent = new PolicyProfile('orders', '1', 'prepare', '1', ['Received', 'Working'], []);
        $this->assertBindingRefused($catalog, $legacyParent, $binding, $child);

        $foreignParent = PolicyProfile::forDefinition(
            self::machine('orders', '1', [self::slot('delivery', 'shipments', '1')], 'Another order meaning.'),
            'prepare',
            '1',
            []
        );
        $this->assertBindingRefused($catalog, $foreignParent, $binding, $child);

        $this->expectException(LogicException::class);
        $catalog->definition('orders', '9');
    }

    /**
     * @param list<string> $path
     * @param list<MachineDefinition> $definitions
     */
    private function assertRefusal(string $reason, array $path, array $definitions): void
    {
        try {
            new MachineCatalog($definitions);
            $this->fail('An invalid machine catalog was accepted.');
        } catch (InvalidMachineCatalog $refusal) {
            $this->assertSame($reason, $refusal->reason);
            $this->assertSame($path, $refusal->path);
            $this->assertStringContainsString(implode(' -> ', $path), $refusal->getMessage());
        }
    }

    private function assertBindingRefused(
        MachineCatalog $catalog,
        PolicyProfile $parent,
        BindingIdentity $binding,
        PolicyProfile $child
    ): void {
        try {
            $catalog->assertBinding($parent, $binding, $child);
            $this->fail('A binding outside the registered catalog was accepted.');
        } catch (LogicException $refusal) {
            $this->assertNotInstanceOf(InvalidArgumentException::class, $refusal);
        }
    }

    /**
     * @return array{MachineCatalog, PolicyProfile, PolicyProfile, BindingIdentity}
     */
    private function binding(): array
    {
        $order = self::machine('orders', '1', [self::slot('delivery', 'shipments', '1')]);
        $shipment = self::machine('shipments', '1');
        $parent = PolicyProfile::forDefinition($order, 'prepare', '1', [
            new TransitionDefinition('begin', 'begin', ['Received'], 'Working', 'begin', '1'),
        ]);
        $child = PolicyProfile::forDefinition($shipment, 'carrier', '1', []);
        $binding = new BindingIdentity(
            new ExecutionReference('scope', 'subject', 'execution', $parent->fingerprint(), 'Received', '1'),
            'namespace',
            'parent-instance',
            'parent-run',
            'delivery',
            'entry-1',
            'Working',
            'example.shipment.v1',
            $child->fingerprint(),
            'delivered',
            ChildReservation::inputFingerprint(['shipment-1'])
        );
        return [new MachineCatalog([$order, $shipment]), $parent, $child, $binding];
    }

    private function rebind(BindingIdentity $binding, PolicyProfile $child): BindingIdentity
    {
        $data = $binding->toArray();
        $data['childPolicyFingerprint'] = $child->fingerprint();
        return BindingIdentity::fromArray($data);
    }

    /**
     * @param list<string> $hosting
     */
    private static function slot(string $key, string $machine, string $version, array $hosting = ['Working']): ChildSlot
    {
        return new ChildSlot($key, $hosting, $machine, $version, 'delivered');
    }

    /**
     * @param list<ChildSlot> $slots
     */
    private static function machine(
        string $machine,
        string $version,
        array $slots = [],
        string $meaning = 'Original work admitted.'
    ): MachineDefinition {
        return new MachineDefinition(
            $machine,
            $version,
            [new MachineState('Received', 'Business input accepted.'), new MachineState('Working', $meaning)],
            ['begin'],
            ConditionDefinition::rule(new RuleReference('example.boolean', '1')),
            $slots
        );
    }
}
