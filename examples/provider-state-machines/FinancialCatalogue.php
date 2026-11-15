<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;

/** One new Payment definition; recorded trigger policy changes permission, never historical state meanings. */
final class FinancialCatalogue
{
    public const AFTER_STOCK = 'authorization-after-stock';

    public const AFTER_DELIVERY = 'authorization-after-delivery';

    public static function profile(string $recipe): PolicyProfile
    {
        $trigger = self::captureTrigger($recipe);
        $definition = new MachineDefinition('provider.payment', '2', [
            new MachineState('received', 'Original payment obligation received'),
            new MachineState(
                'authorized',
                'Original authorization confirmed; current validity is inspected separately'
            ),
            new MachineState('captured', 'Original full capture confirmed; refund is inspected separately'),
        ], ['authorize', 'capture'], self::all(['model_consistent']));
        return PolicyProfile::forDefinition($definition, $recipe, '1', [
            new TransitionDefinition('authorize', 'authorize', ['received'], 'authorized', 'financial-authorize', '1'),
            new TransitionDefinition('capture', 'capture', ['authorized'], 'captured', 'financial-capture', '1'),
        ], [
            'authorize.target' => self::all(['forward_open', 'authorization_confirmed']),
            'authorize.permission' => self::all(['forward_open', 'authorization_admissible']),
            'capture.target' => self::all(['forward_open', 'capture_confirmed']),
            'capture.permission' => self::all(['forward_open', 'authorization_active', $trigger]),
        ]);
    }

    public static function captureTrigger(string $recipe): string
    {
        return match ($recipe) {
            self::AFTER_STOCK => 'original_stock_reserved',
            self::AFTER_DELIVERY => 'original_recipient_delivered',
            default => throw new LogicException('The trusted authorization/capture policy is unavailable.'),
        };
    }

    /**
     * @param non-empty-list<string> $facts
     */
    private static function all(array $facts): ConditionDefinition
    {
        return ConditionDefinition::all(array_map(
            static fn (string $fact): ConditionDefinition =>
            ConditionDefinition::rule(new RuleReference('example.boolean', '1', [
                'fact' => $fact,
                'expected' => true,
            ])),
            $facts
        ));
    }
}
