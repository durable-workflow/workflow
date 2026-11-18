<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use LogicException;
use Workflow\V2\Workflow;

/**
 * Workflow-side partial-stock selection. Recorded host plans drive every command; replay reads
 * neither the warehouse nor the wall clock. The wait-complete deadline was fixed at admission.
 */
final class StockSelectionWait
{
    public static function plan(?int $deadline): array
    {
        $expire = false;
        for ($wake = 0; $wake < 32; ++$wake) {
            $plan = Workflow::activity(BoundaryActivity::class, 'stock-plan', [
                'expire' => $expire,
            ]);
            if ($plan['action'] === 'cancel') {
                throw new BusinessCancellationRequested('The original stock policy cancelled the order.');
            }
            if ($plan['action'] === 'fulfill') {
                return $plan;
            }
            // Only wait-complete returns here. A received delivery or the fixed deadline wakes it.
            $remaining = ($deadline ?? 0) - Workflow::now()->getTimestamp();
            if ($remaining <= 0) {
                $expire = true;
                continue;
            }
            Workflow::await('reconcile-stock', $remaining);
        }
        throw new LogicException('The bounded original complete-order wait remains unresolved.');
    }
}
