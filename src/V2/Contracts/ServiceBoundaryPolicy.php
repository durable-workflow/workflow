<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

use Workflow\V2\Support\ServiceBoundaryDecision;
use Workflow\V2\Support\ServiceBoundaryRequest;

/**
 * Cross-namespace service-call boundary contract.
 *
 * The boundary is the single seam every cross-namespace service call
 * must pass through before handler dispatch. It enforces:
 *
 *  - caller-versus-endpoint/service/operation authorization
 *  - explicit allow/deny by caller namespace
 *  - service-boundary rate limiting
 *  - service-boundary concurrency limiting
 *  - circuit-break behavior for degraded targets
 *  - sync-versus-async policy variations
 *
 * The contract is intentionally narrow: a single typed `evaluate()`
 * call returns the decision. Implementations are free to compose
 * multiple sub-policies internally; callers do not need to know the
 * order in which gates fire.
 *
 * **Privacy boundary.** The boundary never sees decoded payload
 * material. Payload privacy is handled by the existing codec and
 * data-converter trust boundaries — adding a second payload-security
 * model here would force every operator to keep two key registries in
 * sync, which is the wrong boundary shape for service authorization.
 *
 * @api stable contract surface; binding key is `ServiceBoundaryPolicy::class`.
 */
interface ServiceBoundaryPolicy
{
    public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision;
}
