<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

/**
 * Turns an application domain entity into a portable reference when workflow
 * code schedules an activity with it (#996).
 *
 * Registered by logical type in `workflows.v2.entity_references`. The domain
 * class itself implements nothing from Durable Workflow or Laravel; the
 * adapter belongs to the application's infrastructure layer.
 * Its constructor and methods must be deterministic and perform no I/O:
 * adaptation happens while the workflow turn is committed, including retries.
 *
 * @experimental Draft API for workflow issue 996; not covered by the stability
 *               guarantees until it ships.
 */
interface EntityReferenceAdapter
{
    /**
     * Whether this adapter turns the given object into a reference.
     */
    public function supports(object $entity): bool;

    /**
     * The entity's stable identifier. It is recorded in history and must not
     * contain the entity's state, credentials or a tenant the worker would
     * trust on its own.
     *
     * @return non-empty-string
     */
    public function identify(object $entity): string;

    /**
     * The reference format version this adapter writes. A resolver refuses
     * versions it does not support, as a non-retryable failure.
     *
     * @return positive-int
     */
    public function version(): int;
}
