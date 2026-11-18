<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\ChildSlot;
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

    public static function selected(string $recipe): bool
    {
        return in_array($recipe, [self::AFTER_STOCK, self::AFTER_DELIVERY], true);
    }

    /**
     * @param bool $transitRemedy Opted-in grants add the remedied outcome; legacy definitions keep their fingerprints.
     */
    public static function order(string $recipe, bool $transitRemedy = false): PolicyProfile
    {
        self::captureTrigger($recipe);
        $states = [
            new MachineState('received', 'Original purchase received; financial prerequisites are unresolved'),
            new MachineState('ready', 'Original stock and the selected financial prerequisite permit fulfillment'),
            new MachineState('fulfilled', 'Original recipient delivery and required financial settlement confirmed'),
            new MachineState('cancelled', 'Original retained obligations verified and business cancellation committed'),
        ];
        $events = ['prepare', 'fulfill', 'cancel'];
        $transitions = [
            new TransitionDefinition('prepare', 'prepare', ['received'], 'ready', 'financial-prepare', '1'),
            new TransitionDefinition('fulfill', 'fulfill', ['ready'], 'fulfilled', 'financial-fulfill', '1'),
            new TransitionDefinition('cancel', 'cancel', [
                'received',
                'ready',
                'fulfilled',
            ], 'cancelled', 'cancel', '1', false),
        ];
        $conditions = [
            'prepare.target' => self::all(['forward_open', 'prepare_confirmed']),
            'prepare.permission' => self::all(['forward_open']),
            'fulfill.target' => self::all(['forward_open', 'fulfillment_confirmed']),
            'fulfill.permission' => self::all(['forward_open', 'fulfillment_permitted']),
            'cancel.target' => self::all(['cancellation_requested', 'cancel_confirmed']),
            'cancel.permission' => self::all(['cancellation_requested']),
        ];
        if ($transitRemedy) {
            $states[] = new MachineState(
                'remedied',
                'Original parcel confirmed lost or damaged before handover; the selected customer remedy settled'
            );
            $events[] = 'remedy';
            $transitions[] = new TransitionDefinition('remedy', 'remedy', ['ready'], 'remedied', 'transit-remedy', '1');
            $conditions['remedy.target'] = self::all(['forward_open', 'transit_remedy_settled']);
            $conditions['remedy.permission'] = self::all(['forward_open', 'transit_incident_confirmed']);
        }
        $definition = new MachineDefinition(
            'provider.order',
            $transitRemedy ? '4' : '3',
            $states,
            $events,
            self::all(['model_consistent']),
            [
                new ChildSlot('payment', ['received', 'ready', 'fulfilled'], 'provider.payment', '2', 'captured'),
                new ChildSlot('shipment', ['ready', 'fulfilled'], 'provider.shipment', '2', 'delivered'),
            ]
        );
        return PolicyProfile::forDefinition($definition, $recipe, '1', $transitions, $conditions);
    }

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
