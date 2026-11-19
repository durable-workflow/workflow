<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Workflow;

/**
 * Workflow-side waits of one transit incident: carrier delivery of the replacement, then warehouse receipt
 * and inspection of recovered damaged goods. Recorded host observations decide; replay reads neither.
 */
final class IncidentWait
{
    /**
     * A replacement the carrier neither delivers nor fails within the bounded wait ends as unresolved.
     */
    public static function delivery(): array
    {
        return self::until('track', ['delivered', 'failed'], [
            'action' => 'unresolved',
        ]);
    }

    /**
     * The incident's own customer remedy decision: settled, or explicitly owed.
     */
    public static function settlement(): array
    {
        return self::until('settle', ['settled', 'owed']);
    }

    public static function inspection(): array
    {
        return self::until('inspect', ['settle', 'unrecoverable']);
    }

    /**
     * The original Order's wait for its separate incident's decided customer remedy, settled or owed.
     */
    public static function remedy(): array
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $value = Workflow::activity(ShipmentBoundaryActivity::class, 'remedy');
            if ($value['decided'] ?? $value['settled']) {
                return $value;
            }
            Workflow::await('physical-milestone');
        }
        throw new LogicException('The bounded original transit remedy wait remains unresolved.');
    }

    private static function until(string $operation, array $done, ?array $exhausted = null): array
    {
        for ($wake = 0; $wake < 32; ++$wake) {
            $plan = Workflow::activity(IncidentBoundaryActivity::class, $operation, []);
            if (in_array($plan['action'], $done, true)) {
                return $plan;
            }
            Workflow::await('incident-milestone');
        }
        return $exhausted ?? throw new LogicException('The bounded original incident wait remains unresolved.');
    }
}
