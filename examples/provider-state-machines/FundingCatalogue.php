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

/** Common new-generation routes. Readiness, payer collection and merchant receipt are different facts. */
final class FundingCatalogue
{
    public const IMMEDIATE = 'funding-immediate';

    public const AFTER_STOCK = 'funding-authorization-stock';

    public const AFTER_DELIVERY = 'funding-authorization-delivery';

    public const BANK = 'funding-bank-prepayment';

    /**
     * @return non-empty-list<string>
     */
    public static function recipes(): array
    {
        return [self::IMMEDIATE, self::AFTER_STOCK, self::AFTER_DELIVERY, self::BANK];
    }

    public static function selected(string $recipe): bool
    {
        return in_array($recipe, self::recipes(), true);
    }

    public static function assertRecipe(string $recipe): void
    {
        if (! self::selected($recipe)) {
            throw new LogicException('The original funding policy is unavailable.');
        }
    }

    public static function requiresAuthorization(string $recipe): bool
    {
        self::assertRecipe($recipe);
        return in_array($recipe, [self::AFTER_STOCK, self::AFTER_DELIVERY], true);
    }

    public static function payment(string $recipe): PolicyProfile
    {
        self::assertRecipe($recipe);
        $definition = new MachineDefinition('provider.payment', '3', [
            new MachineState('received', 'Original payment obligation received'),
            new MachineState('ready', 'The selected policy financial prerequisite for fulfillment was confirmed'),
            new MachineState('collected', 'The payer collection required by the original obligation was confirmed'),
            new MachineState(
                'paid',
                'The original required merchant receipt was confirmed; later refunds remain separate'
            ),
        ], ['prepare', 'collect', 'confirm-receipt'], self::all(['model_consistent']));
        $collected = $recipe === self::BANK ? 'incoming_full_amount_confirmed' : 'capture_confirmed';
        $ready = self::requiresAuthorization($recipe) ? 'authorization_active' : $collected;
        $permission = match ($recipe) {
            self::AFTER_STOCK => ['authorization_active', 'original_stock_reserved'],
            self::AFTER_DELIVERY => ['authorization_active', 'original_recipient_delivered'],
            default => [$collected],
        };
        return PolicyProfile::forDefinition($definition, $recipe, '1', [
            new TransitionDefinition('prepare', 'prepare', ['received'], 'ready', 'funding-prepare.' . $recipe, '1'),
            new TransitionDefinition('collect', 'collect', ['ready'], 'collected', 'funding-collect.' . $recipe, '1'),
            new TransitionDefinition('confirm-receipt', 'confirm-receipt', [
                'collected',
            ], 'paid', 'funding-receipt.' . $recipe, '1'),
        ], [
            'prepare.target' => self::all(['forward_open', $ready]),
            'prepare.permission' => self::all(self::requiresAuthorization($recipe)
                ? ['forward_open', 'authorization_admissible'] : ['forward_open']),
            'collect.target' => self::all(['forward_open', $collected]),
            'collect.permission' => self::all(['forward_open', ...$permission]),
            'confirm-receipt.target' => self::all(['forward_open', 'merchant_receipt_confirmed']),
            'confirm-receipt.permission' => self::all(['forward_open']),
        ]);
    }

    public static function order(string $recipe): PolicyProfile
    {
        self::assertRecipe($recipe);
        $definition = new MachineDefinition('provider.order', '4', [
            new MachineState('received', 'Original purchase received; preparation remains outstanding'),
            new MachineState('ready', 'Original stock and the selected financial prerequisite permit fulfillment'),
            new MachineState(
                'fulfilled',
                'Selected physical fulfillment and required original merchant receipt confirmed'
            ),
            new MachineState('cancelled', 'Original retained obligations verified and business cancellation committed'),
        ], ['prepare', 'fulfill', 'cancel'], self::all(['model_consistent']), [
            new ChildSlot('payment', ['received', 'ready', 'fulfilled'], 'provider.payment', '3', 'paid'),
            new ChildSlot('shipment', ['ready', 'fulfilled'], 'provider.shipment', '2', 'delivered'),
        ]);
        return PolicyProfile::forDefinition($definition, $recipe, '1', [
            new TransitionDefinition('prepare', 'prepare', [
                'received',
            ], 'ready', 'funding-order-prepare.' . $recipe, '1'),
            new TransitionDefinition('fulfill', 'fulfill', [
                'ready',
            ], 'fulfilled', 'funding-order-fulfill.' . $recipe, '1'),
            new TransitionDefinition('cancel', 'cancel', [
                'received',
                'ready',
                'fulfilled',
            ], 'cancelled', 'cancel', '1', false),
        ], [
            'prepare.target' => self::all(['forward_open', 'prepare_confirmed']),
            'prepare.permission' => self::all(['forward_open']),
            'fulfill.target' => self::all(['forward_open', 'fulfillment_confirmed']),
            'fulfill.permission' => self::all(['forward_open', 'fulfillment_permitted']),
            'cancel.target' => self::all(['cancellation_requested', 'cancel_confirmed']),
            'cancel.permission' => self::all(['cancellation_requested']),
        ]);
    }

    public static function shipment(string $recipe): PolicyProfile
    {
        self::assertRecipe($recipe);
        $physical = PhysicalCatalogue::profile(FinancialCatalogue::AFTER_STOCK);
        $definition = $physical->definition ?? throw new LogicException(
            'The common physical definition is unavailable.'
        );
        return PolicyProfile::forDefinition(
            $definition,
            $recipe,
            '1',
            $physical->transitions,
            $physical->conditions
        );
    }

    /**
     * @param non-empty-list<string> $facts
     */
    private static function all(array $facts): ConditionDefinition
    {
        return ConditionDefinition::all(array_map(static fn (string $fact): ConditionDefinition =>
            ConditionDefinition::rule(new RuleReference('example.boolean', '1', [
                'fact' => $fact,
                'expected' => true,
            ])), $facts));
    }
}
