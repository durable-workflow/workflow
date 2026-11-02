<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

/** Current authorization/circuit checks without a fresh admission reservation. */
interface ServiceBoundaryReplayPolicy extends ServiceBoundaryPolicy
{
    public function authorizeReplay(ServiceBoundaryRequest $request): ServiceBoundaryDecision;
}
