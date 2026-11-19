<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

/**
 * The host checks the authenticated principal and original business owner.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
interface BindingAuthorization
{
    /**
     * Refuse unauthorized access, or return the applicable authorization policy version.
     */
    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string;
}
