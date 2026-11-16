<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;

/** Common authorization Order route. Ready is a prerequisite milestone, never recipient delivery. */
#[Type('example.provider.order.funding.v1')]
#[Signal('funding-milestone')]
#[Signal('reconcile-stock')]
#[Signal('fulfill')]
#[Signal('physical-milestone')]
final class FundingCheckout extends AuthorizationCheckout
{
}
