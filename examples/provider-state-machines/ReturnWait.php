<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Workflow;

/**
 * Workflow-side wait for warehouse receipt and inspection of one return. Recorded host observations
 * decide every step; replay reads neither the warehouse nor the clock. Receipt alone keeps waiting.
 */
final class ReturnWait
{
    public static function inspection(): array
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $plan = Workflow::activity(ReturnBoundaryActivity::class, 'observe', []);
            if ($plan['action'] === 'settle') {
                return $plan;
            }
            Workflow::await('return-milestone');
        }
        throw new LogicException('The bounded original return inspection wait remains unresolved.');
    }
}
