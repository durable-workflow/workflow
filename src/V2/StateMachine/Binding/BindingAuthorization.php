<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

/** @experimental The host checks the authenticated principal and original business owner. */
interface BindingAuthorization
{
    /**
     * Refuse unauthorized access, or return the applicable authorization policy version.
     */
    public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string;
}
