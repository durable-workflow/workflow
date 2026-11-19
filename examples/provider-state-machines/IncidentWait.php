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
     * Default and largest recorded wake bound of the replacement delivery and remedy decision waits. Each wake
     * is one recorded incident signal; histories recorded before the bound was configurable use this value.
     */
    public const WAKES = 32;

    /**
     * A replacement the carrier neither delivers nor fails within the bounded wait ends as unresolved.
     */
    public static function delivery(int $wakes = self::WAKES): array
    {
        return self::until('track', ['delivered', 'failed'], $wakes, static fn (): array => [
            'action' => 'unresolved',
        ]);
    }

    /**
     * The incident's own customer remedy decision: settled, or explicitly owed. A remedy still undecided after
     * the bounded wait, such as a refund the provider is still processing, ends explicitly owed instead.
     */
    public static function settlement(int $wakes = self::WAKES): array
    {
        return self::until('settle', ['settled', 'owed'], $wakes, static fn (): array => Workflow::activity(
            IncidentBoundaryActivity::class,
            'unresolved',
            []
        ));
    }

    public static function inspection(): array
    {
        return self::until('inspect', ['settle', 'unrecoverable'], self::WAKES);
    }

    /**
     * The original Order's wait for its separate incident's decided customer remedy, settled or owed.
     */
    public static function remedy(): array
    {
        for ($wake = 0; $wake < self::WAKES; ++$wake) {
            $value = Workflow::activity(ShipmentBoundaryActivity::class, 'remedy');
            if ($value['decided'] ?? $value['settled']) {
                return $value;
            }
            Workflow::await('physical-milestone');
        }
        throw new LogicException('The bounded original transit remedy wait remains unresolved.');
    }

    private static function until(string $operation, array $done, int $wakes, ?callable $exhausted = null): array
    {
        for ($wake = 0; $wake < $wakes; ++$wake) {
            $plan = Workflow::activity(IncidentBoundaryActivity::class, $operation, []);
            if (in_array($plan['action'], $done, true)) {
                return $plan;
            }
            Workflow::await('incident-milestone');
        }
        return $exhausted === null
            ? throw new LogicException('The bounded original incident wait remains unresolved.')
            : $exhausted();
    }
}
