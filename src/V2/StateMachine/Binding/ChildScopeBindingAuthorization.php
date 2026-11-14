<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

/** @experimental The host authorizes the original business owner and retained scope pair together. */
interface ChildScopeBindingAuthorization extends BindingAuthorization
{
    /**
     * Refuse an unrelated owner/generation or unavailable pinned policy.
     * Returning a version authorizes this exact relationship, not each identity independently.
     */
    public function assertParticipationAllowed(
        BindingIdentity $identity,
        ChildScopeParticipation $participation,
        string $principal,
        string $operation,
        ?string $pinnedPolicyVersion = null,
    ): string;
}
