<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;

/** Selects the new funding contract while reusing the original protected handoff implementation. */
final readonly class FundingHandoffStore extends FinancialHandoffStore
{
    public function __construct(Authority $authority, Journal $provider, FundingAuthority $funding)
    {
        parent::__construct($authority, $provider, $funding);
    }
}
