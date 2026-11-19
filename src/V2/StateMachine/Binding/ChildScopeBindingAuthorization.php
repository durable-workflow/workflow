<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

/**
 * The host authorizes the original business owner and retained scope pair together.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
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
