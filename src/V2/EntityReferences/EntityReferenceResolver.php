<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

/**
 * Loads the current persisted entity for a reference before each activity
 * attempt runs (#996).
 *
 * The worker calls it only for argument positions the engine recorded as
 * references when it scheduled the activity, never for an ordinary array that
 * happens to look like one, and never during workflow replay.
 *
 * Throw {@see EntityNotFound} or {@see EntityAccessDenied} for those outcomes.
 * Any other exception follows the activity's retry policy.
 *
 * @experimental Draft API for workflow issue 996; not covered by the stability
 *               guarantees until it ships.
 */
interface EntityReferenceResolver
{
    /**
     * @param EntityReferenceContext $context Trusted execution context. Tenant
     *        authorization comes from here, never from the reference alone.
     */
    public function resolve(EntityReference $reference, EntityReferenceContext $context): object;
}
