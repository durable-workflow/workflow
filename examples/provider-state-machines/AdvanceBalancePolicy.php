<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\CodDescriptor;
use LogicException;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;

/** Original advance permits preparation; carrier balance and merchant receipt remain later facts. */
final class AdvanceBalancePolicy
{
    public const BEFORE = 'funding-advance-carrier-balance-before-handover';

    public const AFTER = 'funding-advance-carrier-balance-after-handover';

    /**
     * @return non-empty-list<string>
     */
    public static function recipes(): array
    {
        return [self::BEFORE, self::AFTER];
    }

    public static function selected(string $recipe): bool
    {
        return in_array($recipe, self::recipes(), true);
    }

    public static function handover(string $recipe): string
    {
        return match ($recipe) {
            self::BEFORE => CodDescriptor::BEFORE,
            self::AFTER => CodDescriptor::AFTER,
            default => throw new LogicException('The original advance/balance handover recipe is unavailable.'),
        };
    }

    public static function profile(string $role, string $recipe): PolicyProfile
    {
        if (! in_array($recipe, [self::BEFORE, self::AFTER], true)) {
            throw new LogicException('The original advance/balance policy is unavailable.');
        }
        return FundingCatalogue::carrierProfile($role, $recipe, [
            'prepare.target' => self::all(['forward_open', 'advance_payment_confirmed', 'cod_reference_ready']),
            'prepare.permission' => self::all(['forward_open']),
            'collect.target' => self::all(
                ['forward_open', 'advance_payment_confirmed', 'cod_payer_collection_confirmed']
            ),
            'collect.permission' => self::all(['forward_open', 'advance_payment_confirmed', 'cod_reference_ready']),
            'confirm-receipt.target' => self::all(
                [
                    'forward_open', 'advance_payment_confirmed', 'cod_payer_collection_confirmed', 'merchant_receipt_confirmed']
            ),
            'confirm-receipt.permission' => self::all(['forward_open']),
        ]);
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
