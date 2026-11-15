<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Rules\RuleRegistry;

/** Closed application definitions. Keep version 1 available when deploying another version. */
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
        return new PolicyProfile('orders', '1', $integration, $version, self::STATES, $transitions, $conditions);
    }

    public static function shipment(string $integration, string $version = '1'): PolicyProfile
    {
        self::assertVersion($integration, $version);
        $common = self::all(['enabled', 'forward_open']);
        return new PolicyProfile('shipments', '1', $integration, $version, self::STATES, [
            new TransitionDefinition('deliver', 'deliver', ['received'], 'completed', 'deliver', '1'),
        ], [
            'deliver.target' => ConditionDefinition::all([$common, self::fact('deliver_active')]),
            'deliver.permission' => $common,
        ]);
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
        if ($version !== '1' || ! in_array($integration, ['prepare', 'imported'], true)) {
            throw new LogicException('The original configured machine profile is unavailable.');
        }
    }
}
