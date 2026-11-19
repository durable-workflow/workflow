<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Workflow;

/**
 * Workflow-side waits of one exchange: warehouse inspection of the original unit, then carrier delivery of
 * the replacement. Recorded host observations decide every step; replay reads neither provider nor clock.
 */
final class ExchangeWait
{
    public static function inspection(): array
    {
        return self::until('inspect', ['settle']);
    }

    public static function delivery(): array
    {
        return self::until('track', ['delivered', 'failed']);
    }

    private static function until(string $operation, array $done): array
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $plan = Workflow::activity(ExchangeBoundaryActivity::class, $operation, []);
            if (in_array($plan['action'], $done, true)) {
                return $plan;
            }
            Workflow::await('exchange-milestone');
        }
        throw new LogicException('The bounded original exchange wait remains unresolved.');
    }
}
