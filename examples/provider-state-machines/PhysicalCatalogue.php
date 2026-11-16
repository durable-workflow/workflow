<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;

/** Both financial policies use the same full Shipment lifecycle and physical meanings. */
final class PhysicalCatalogue
{
    public static function profile(string $recipe): PolicyProfile
    {
        FinancialCatalogue::captureTrigger($recipe);
        $definition = new MachineDefinition('provider.shipment', '2', [
            new MachineState('received', 'Original fulfillment obligation received'),
            new MachineState('booked', 'Original carrier booking confirmed; no handover or delivery implied'),
            new MachineState('in-transit', 'Original carrier handover and physical stock deduction confirmed'),
            new MachineState('delivered', 'Original recipient delivery confirmed; historical after any return'),
        ], ['book', 'handover', 'deliver'], self::all(['model_consistent']));
        return PolicyProfile::forDefinition($definition, $recipe, '1', [
            new TransitionDefinition('book', 'book', ['received'], 'booked', 'physical-book', '1'),
            new TransitionDefinition('handover', 'handover', ['booked'], 'in-transit', 'physical-handover', '1'),
            new TransitionDefinition('deliver', 'deliver', ['in-transit'], 'delivered', 'physical-deliver', '1'),
        ], [
            'book.target' => self::all(['forward_open', 'booking_confirmed']),
            'book.permission' => self::all(['forward_open', 'shipment_permitted']),
            'handover.target' => self::all(['forward_open', 'original_carrier_handover', 'stock_deduction_confirmed']),
            'handover.permission' => self::all(['forward_open', 'original_carrier_handover']),
            'deliver.target' => self::all(
                ['forward_open', 'original_recipient_delivered', 'stock_deduction_confirmed']
            ),
            'deliver.permission' => self::all(
                ['forward_open', 'original_recipient_delivered', 'stock_deduction_confirmed']
            ),
        ]);
    }

    private static function all(array $facts): ConditionDefinition
    {
        return ConditionDefinition::all(array_map(static fn (string $fact): ConditionDefinition =>
            ConditionDefinition::rule(new RuleReference('example.boolean', '1', [
                'fact' => $fact,
                'expected' => true,
            ])), $facts));
    }
}
