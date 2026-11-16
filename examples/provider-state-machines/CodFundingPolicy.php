<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;

/** COD profile preparation; native admission remains unavailable until its recorded host adapters are wired. */
final class CodFundingPolicy
{
    public const BEFORE = 'funding-cod-before-recipient-handover';

    public const AFTER = 'funding-cod-after-recipient-handover';

    public static function profile(string $role, string $recipe): PolicyProfile
    {
        if (! in_array($recipe, [self::BEFORE, self::AFTER], true)) {
            throw new LogicException('The original carrier handover policy is unavailable.');
        }
        $base = FundingCatalogue::profile($role, FundingCatalogue::IMMEDIATE);
        $transitions = [];
        foreach ($base->transitions as $edge) {
            $handler = $edge->handler;
            if ($role === 'payment') {
                $handler = 'funding-' . $edge->key . '.' . $recipe;
            } elseif ($role === 'order' && in_array($edge->key, ['prepare', 'fulfill'], true)) {
                $handler = 'funding-order-' . $edge->key . '.' . $recipe;
            }
            $transitions[] = new TransitionDefinition(
                $edge->key,
                $edge->event,
                $edge->from,
                $edge->to,
                $handler,
                $edge->handlerVersion,
                $edge->hasWork
            );
        }
        $conditions = $base->conditions;
        if ($role === 'payment') {
            $conditions = [
                'prepare.target' => self::all(['forward_open', 'cod_reference_ready']),
                'prepare.permission' => self::all(['forward_open']),
                'collect.target' => self::all(['forward_open', 'cod_payer_collection_confirmed']),
                'collect.permission' => self::all(['forward_open', 'cod_reference_ready']),
                'confirm-receipt.target' => self::all(['forward_open', 'merchant_receipt_confirmed']),
                'confirm-receipt.permission' => self::all(['forward_open']),
            ];
        }
        return PolicyProfile::forDefinition(
            $base->definition ?? throw new LogicException('The common funding definition is unavailable.'),
            $recipe,
            '1',
            $transitions,
            $conditions
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
