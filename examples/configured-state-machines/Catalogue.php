<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\ChildSlot;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineCatalog;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Rules\RuleRegistry;

/** Closed application definitions. Version 1 stays available for original runs and compensation. */
final class Catalogue
{
    public const STATES = ['received', 'working', 'completed', 'cancelled'];

    public static function order(string $integration, string $version = '1'): PolicyProfile
    {
        self::assertVersion($integration, $version);
        $common = self::all(['enabled', 'forward_open']);
        $transitions = [
            new TransitionDefinition('begin', 'begin', ['received'], self::hosting($integration), 'begin', '1'),
            new TransitionDefinition('finish', 'finish', [self::hosting($integration)], 'completed', 'finish', '1'),
            new TransitionDefinition('cancel', 'cancel-resolved', [
                'received',
                'working',
                'completed',
            ], 'cancelled', 'cancel', '1', false),
        ];
        $conditions = [
            'begin.target' => ConditionDefinition::all([
                $common,
                self::all([
                    'shipment_started',
                    ...array_map(
                        static fn (string $action): string => $action . '_active',
                        self::actions($integration)
                    ),
                ]),
            ]),
            'begin.permission' => ConditionDefinition::all([$common, self::fact('slot_available')]),
            'finish.target' => ConditionDefinition::all([$common, self::fact('shipment_verified')]),
            'finish.permission' => ConditionDefinition::all(
                [$common, self::all(['shipment_started', 'hosting_committed'])]
            ),
            'cancel.target' => self::all(['cancellation_requested', 'scope_verified']),
            'cancel.permission' => self::fact('cancellation_requested'),
        ];
        if ($integration === 'imported') {
            // An authoritative read-only business fact can satisfy this edge without provider work.
            $transitions[] = new TransitionDefinition('reconcile', 'reconcile', [
                'received',
            ], 'completed', 'reconcile', '1', false);
            $conditions['reconcile.target'] = ConditionDefinition::all([$common, self::fact('prevalidated')]);
            $conditions['reconcile.permission'] = $common;
        }
        return $version === '1'
            ? new PolicyProfile('orders', '1', $integration, $version, self::STATES, $transitions, $conditions)
            : PolicyProfile::forDefinition(
                self::definition('orders'),
                $integration,
                $version,
                $transitions,
                $conditions
            );
    }

    public static function shipment(string $integration, string $version = '1'): PolicyProfile
    {
        self::assertVersion($integration, $version);
        $common = self::all(['enabled', 'forward_open']);
        $transitions = [new TransitionDefinition('deliver', 'deliver', ['received'], 'completed', 'deliver', '1')];
        $conditions = [
            'deliver.target' => ConditionDefinition::all([$common, self::fact('deliver_active')]),
            'deliver.permission' => $common,
        ];
        return $version === '1'
            ? new PolicyProfile('shipments', '1', $integration, $version, self::STATES, $transitions, $conditions)
            : PolicyProfile::forDefinition(
                self::definition('shipments'),
                $integration,
                $version,
                $transitions,
                $conditions
            );
    }

    /**
     * @return list<array{string, string}>
     */
    public static function profiles(): array
    {
        return [['prepare', '1'], ['imported', '1'], ['prepare', '2'], ['imported', '2']];
    }

    /**
     * Version-two definitions that may host each other; construction refuses cyclic or unregistered slots.
     */
    public static function machines(): MachineCatalog
    {
        return new MachineCatalog([self::definition('orders'), self::definition('shipments')], maxChildDepth: 1);
    }

    public static function registry(): RuleRegistry
    {
        return new RuleRegistry([
            'example.boolean' => [
                '1' => new BooleanFact(),
            ],
        ]);
    }

    public static function transition(PolicyProfile $profile, string $key): TransitionDefinition
    {
        foreach ($profile->transitions as $transition) {
            if ($transition->key === $key) {
                return $transition;
            }
        }
        throw new LogicException('The original transition definition is unavailable.');
    }

    /**
     * @return list<string>
     */
    public static function actions(string $integration): array
    {
        self::assertVersion($integration, '1');
        return $integration === 'prepare' ? ['reserve', 'prepare'] : ['import'];
    }

    public static function hosting(string $integration): string
    {
        self::assertVersion($integration, '1');
        return $integration === 'prepare' ? 'working' : 'received';
    }

    private static function definition(string $machine): MachineDefinition
    {
        return new MachineDefinition(
            $machine,
            '2',
            [
                new MachineState('received', 'Business input accepted; completion has not been committed.'),
                new MachineState('working', 'Original work admitted; business completion remains pending.'),
                new MachineState('completed', 'The original execution has verified its required business outcome.'),
                new MachineState('cancelled', 'Original compensation verified and business cancellation committed.'),
            ],
            $machine === 'orders' ? ['begin', 'finish', 'cancel', 'reconcile'] : ['deliver'],
            self::fact('model_consistent'),
            $machine === 'orders' ? [
                new ChildSlot('shipment', ['received', 'working'], 'shipments', '2', 'completed'),
            ] : []
        );
    }

    private static function fact(string $name): ConditionDefinition
    {
        return ConditionDefinition::rule(new RuleReference('example.boolean', '1', [
            'fact' => $name,
            'expected' => true,
        ]));
    }

    /**
     * @param non-empty-list<string> $names
     */
    private static function all(array $names): ConditionDefinition
    {
        return ConditionDefinition::all(array_map(self::fact(...), $names));
    }

    private static function assertVersion(string $integration, string $version): void
    {
        if (! in_array($version, ['1', '2'], true) || ! in_array($integration, ['prepare', 'imported'], true)) {
            throw new LogicException('The original configured machine profile is unavailable.');
        }
    }
}
