<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;

/**
 * A configured machine catalog cannot host its declared child slots.
 *
 * The reason is a stable code. The path starts with the registered `machine@version` and names
 * each following slot step as `slot:machine@version`, so configuration tooling can report the
 * exact edge.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final class InvalidMachineCatalog extends InvalidArgumentException
{
    public const DUPLICATE_DEFINITION = 'state_machine_definition_duplicate';

    public const UNREGISTERED_CHILD = 'state_machine_child_definition_unregistered';

    public const CHILD_BINDING_CYCLE = 'state_machine_child_binding_cycle';

    /**
     * @param list<string> $path
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $path,
    ) {
        if (! in_array($reason, [self::DUPLICATE_DEFINITION, self::UNREGISTERED_CHILD, self::CHILD_BINDING_CYCLE], true)
            || $path === [] || ! array_is_list($path)) {
            throw new InvalidArgumentException('Invalid state-machine catalog refusal.');
        }
        parent::__construct(sprintf('State-machine catalog refused [%s]: %s.', $reason, implode(' -> ', $path)));
    }
}
