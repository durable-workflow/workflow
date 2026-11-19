<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Workflow;

/**
 * Separate native remedy for an original parcel the carrier confirmed lost or damaged before recipient
 * handover. It settles the trusted customer remedy first, then files the carrier claim and recovers damaged
 * goods; neither gates the customer. Its scope owns only its own operations.
 */
#[Type('example.provider.incident.transit.v1')]
#[Signal('incident-milestone')]
final class TransitIncident extends Workflow
{
    public function handle(string $key): array
    {
        $initial = self::activity(IncidentBoundaryActivity::class, 'initialize', [
            'arguments' => [$key],
        ]);
        if ($initial['nativeRun'] !== $this->runId() || $initial['key'] !== $key) {
            throw new LogicException('The recorded incident initialization belongs to another original incident.');
        }
        $scope = new DurableScope($this, ScopeIdentity::fromArray($initial['scope']), $initial['principal']);
        $execute = static fn (array $obligation): array => $scope->register(Obligation::fromArray($obligation))
            ->execute();
        $obligations = $initial['obligations'];
        if ($initial['policy'] === TransitPolicy::REPLACE) {
            // An unknown reservation outcome is reconciled by the same operation; only refusal is unavailability.
            $reserved = $execute($obligations['reserve']);
            if ($reserved['status'] === 'confirmed') {
                $this->replace($execute, $obligations);
            } elseif ($reserved['status'] === 'refused') {
                // Selected policy: a definitively unavailable replacement refunds every original unit instead.
                $execute($obligations['refund']);
            }
        } else {
            $execute($obligations['refund']);
        }
        // Every remedy ends explicitly: settled, or owed after a refused refund or an unresolved replacement.
        IncidentWait::settlement();
        // The carrier claim and damaged-goods recovery follow the customer remedy and never gate it.
        $execute($obligations['claim']);
        if (isset($obligations['recover']) && $execute($obligations['recover'])['status'] === 'confirmed') {
            $plan = IncidentWait::inspection();
            if ($plan['restock'] !== null) {
                $execute($plan['restock']);
            }
        }
        return self::activity(IncidentBoundaryActivity::class, 'publish', []);
    }

    /**
     * Books the reserved replacement and waits for its carrier outcome. A refused booking releases the unused
     * reservation, and a carrier-confirmed replacement failure keeps the shipped stock; both refund instead.
     */
    private function replace(callable $execute, array $obligations): void
    {
        $booked = $execute($obligations['book']);
        if ($booked['status'] === 'refused') {
            $execute($obligations['release']);
            $execute($obligations['refund']);
            return;
        }
        if ($booked['status'] !== 'confirmed') {
            return;
        }
        $delivery = IncidentWait::delivery();
        if ($delivery['action'] === 'failed') {
            $execute($obligations['refund']);
        } elseif ($delivery['action'] === 'unresolved') {
            // It may still arrive, so nothing is refunded automatically; the customer is explicitly owed.
            self::activity(IncidentBoundaryActivity::class, 'unresolved', []);
        }
    }
}
