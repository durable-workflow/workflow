<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

/**
 * @experimental The host jointly authorizes the exact business scope/generation,
 * native definition, input and profile before protected storage is accessed.
 * Separate grants for independently chosen parts do not authorize their pairing.
 */
interface RootAuthorization
{
    /**
     * Return the immutable original policy version, or refuse by throwing.
     */
    public function assertAllowed(RootIdentity $identity, string $principal, string $operation): string;
}
