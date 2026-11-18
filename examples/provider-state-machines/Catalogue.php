<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\BooleanFact;
use LogicException;
use Workflow\V2\StateMachine\Definition\ChildSlot;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Rules\RuleRegistry;

/** Explicit new-execution policy: immediate capture, then booking without carrier handover. */
final class Catalogue
{
    public const APPLICATION = 'provider.orders';

    public const NAMESPACE = 'provider-machines';

    public const RESERVE_FIRST = 'reserve-first';

    public const PAYMENT_FIRST = 'payment-first';

    public const COMMON_RESERVE_FIRST = 'reserve-first-common';

    public static function assertRecipe(string $recipe): void
    {
        if (! in_array($recipe, [self::RESERVE_FIRST, self::PAYMENT_FIRST, self::COMMON_RESERVE_FIRST], true)
            && ! FinancialCatalogue::selected($recipe) && ! FundingCatalogue::selected($recipe)) {
            throw new LogicException('The trusted provider policy recipe is unavailable.');
        }
    }

    public static function rootClass(string $recipe): string
    {
        self::assertRecipe($recipe);
        if (FundingCatalogue::selected($recipe)) {
            return FundingCheckout::class;
        }
        if (FinancialCatalogue::selected($recipe)) {
            return AuthorizationCheckout::class;
        }
        return $recipe === self::RESERVE_FIRST ? Order::class : CheckoutOrder::class;
    }

    /**
     * The policy changes prerequisite work, never the common Order lifecycle.
     */
    public static function preparation(string $recipe): array
    {
        return match ($recipe) {
            self::COMMON_RESERVE_FIRST => ['reserve', 'payment'],
            self::PAYMENT_FIRST => ['payment', 'reserve'],
            default => throw new LogicException('The selected legacy route has no common checkout action.'),
        };
    }

    /**
     * @param bool $transitRemedy Only an opted-in trusted transit remedy grant selects the remedied Order route.
     */
    public static function profile(
        string $role,
        string $recipe = self::RESERVE_FIRST,
        bool $transitRemedy = false
    ): PolicyProfile {
        self::assertRecipe($recipe);
        if (FundingCatalogue::selected($recipe)) {
            return FundingCatalogue::profile($role, $recipe);
        }
        if (FinancialCatalogue::selected($recipe)) {
            return match ($role) {
                'order' => FinancialCatalogue::order(
                    $recipe,
                    $transitRemedy && $recipe === FinancialCatalogue::AFTER_STOCK
                ),
                'payment' => FinancialCatalogue::profile($recipe),
                'shipment' => PhysicalCatalogue::profile($recipe),
                default => throw new LogicException('The selected financial machine role is unavailable.'),
            };
        }
        return self::legacyProfile($role, $recipe);
    }

    public static function transition(
        string $role,
        string $key,
        string $recipe = self::RESERVE_FIRST,
        bool $transitRemedy = false
    ): TransitionDefinition {
        foreach (self::profile($role, $recipe, $transitRemedy)->transitions as $transition) {
            if ($transition->key === $key) {
                return $transition;
            }
        }
        throw new LogicException('The original provider transition is unavailable.');
    }

    public static function registry(): RuleRegistry
    {
        return new RuleRegistry([
            'example.boolean' => [
                '1' => new BooleanFact(),
            ],
        ]);
    }

    private static function legacyProfile(string $role, string $recipe): PolicyProfile
    {
        $checkout = $role === 'order' && $recipe !== self::RESERVE_FIRST;
        [$states, $edges] = self::route($role, $checkout);
        $transitions = [];
        $conditions = [];
        foreach ($edges as [$key, $from, $to, $work]) {
            $transitions[] = new TransitionDefinition($key, $key, is_array($from) ? $from : [
                $from,
            ], $to, $checkout && $key === 'prepare' ? 'prepare.' . $recipe : $key, '1', $work);
            $conditions[$key . '.target'] = self::all([
                $key === 'cancel' ? 'cancellation_requested' : 'forward_open',
                $key . '_confirmed',
            ]);
            $conditions[$key . '.permission'] = self::all([
                $key === 'cancel' ? 'cancellation_requested' : 'forward_open',
            ]);
        }
        $definition = new MachineDefinition(
            'provider.' . $role,
            $checkout ? '2' : '1',
            array_map(static fn (string $state): MachineState => new MachineState($state, $state), $states),
            array_column($edges, 0),
            self::all(['model_consistent']),
            $role === 'order' ? self::childSlots($checkout) : [],
        );
        return PolicyProfile::forDefinition(
            $definition,
            $checkout ? $recipe . '-pre-handover' : 'immediate-pre-handover',
            '1',
            $transitions,
            $conditions
        );
    }

    private static function route(string $role, bool $checkout): array
    {
        if ($checkout) {
            return [
                ['received', 'ready', 'booked', 'cancelled'],
                [
                    ['prepare', 'received', 'ready', true],
                    ['book', 'ready', 'booked', true],
                    ['cancel', ['received', 'ready', 'booked'], 'cancelled', false],
                ],
            ];
        }
        return match ($role) {
            'order' => [
                ['received', 'reserved', 'paid', 'booked', 'cancelled'],
                [
                    ['reserve', 'received', 'reserved', true],
                    ['pay', 'reserved', 'paid', true],
                    ['book', 'paid', 'booked', true],
                    ['cancel', ['received', 'reserved', 'paid', 'booked'], 'cancelled', false],
                ],
            ],
            'payment' => [['received', 'captured'], [['capture', 'received', 'captured', true]]],
            // This is the booking boundary, not delivery or the complete physical Shipment lifecycle.
            'shipment' => [['received', 'booked'], [['book', 'received', 'booked', true]]],
            default => throw new LogicException('The provider machine role is unavailable.'),
        };
    }

    private static function childSlots(bool $checkout): array
    {
        return [
            new ChildSlot('payment', $checkout ? ['received', 'ready'] : [
                'reserved',
                'paid',
            ], 'provider.payment', '1', 'captured'),
            new ChildSlot('shipment', $checkout ? ['ready', 'booked'] : [
                'paid',
                'booked',
            ], 'provider.shipment', '1', 'booked'),
        ];
    }

    /**
     * @param non-empty-list<string> $facts
     */
    private static function all(array $facts): ConditionDefinition
    {
        return ConditionDefinition::all(array_map(
            static fn (string $fact): ConditionDefinition => ConditionDefinition::rule(new RuleReference(
                'example.boolean',
                '1',
                [
                    'fact' => $fact,
                    'expected' => true,
                ],
            )),
            $facts,
        ));
    }
}
