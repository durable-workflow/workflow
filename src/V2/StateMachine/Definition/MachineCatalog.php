<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Execution\ResourceLimitExceeded;

/**
 * Trusted application configuration of the machine definitions that may host each other.
 *
 * Construction resolves every child slot to a registered `machine@version` and refuses an
 * unregistered child, a recursive or cyclic binding and, when configured, a nesting depth
 * beyond the limit. Validation is pure: it performs no I/O, starts no child and does not
 * authorize a binding.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class MachineCatalog
{
    public const MAX_DEFINITIONS = 1024;

    public const MAX_CHILD_DEPTH = 64;

    /**
     * @var non-empty-list<MachineDefinition>
     */
    public array $definitions;

    /**
     * @var array<string, MachineDefinition>
     */
    private array $registered;

    /**
     * @var array<string, int>
     */
    private array $heights;

    /**
     * @param non-empty-list<MachineDefinition> $definitions
     * @param int|null $maxChildDepth Child levels below any definition; null leaves depth unbounded.
     */
    public function __construct(
        array $definitions,
        public ?int $maxChildDepth = null,
    ) {
        if ($definitions === [] || ! array_is_list($definitions) || count($definitions) > self::MAX_DEFINITIONS) {
            throw new InvalidArgumentException(sprintf(
                'A machine catalog requires an ordered list of 1 to %d definitions.',
                self::MAX_DEFINITIONS
            ));
        }
        if ($maxChildDepth !== null && ($maxChildDepth < 0 || $maxChildDepth > self::MAX_CHILD_DEPTH)) {
            throw new InvalidArgumentException(sprintf(
                'The machine catalog child depth must be between 0 and %d.',
                self::MAX_CHILD_DEPTH
            ));
        }
        $registered = [];
        foreach ($definitions as $definition) {
            if (! $definition instanceof MachineDefinition) {
                throw new InvalidArgumentException('A machine catalog accepts only machine definitions.');
            }
            $key = self::key($definition->machine, $definition->version);
            if (isset($registered[$key])) {
                throw new InvalidMachineCatalog(
                    InvalidMachineCatalog::DUPLICATE_DEFINITION,
                    [self::id($definition->machine, $definition->version)]
                );
            }
            $registered[$key] = $definition;
        }
        $analysis = [];
        foreach ($definitions as $definition) {
            self::visit(
                $registered,
                $definition,
                [self::id($definition->machine, $definition->version)],
                [],
                $analysis
            );
        }
        $heights = array_map(static fn (array $node): int => $node['height'], $analysis);
        $deepest = max($heights);
        if ($maxChildDepth !== null && $deepest > $maxChildDepth) {
            throw new ResourceLimitExceeded('child_depth', $deepest, $maxChildDepth);
        }
        $this->definitions = array_values($registered);
        $this->registered = $registered;
        $this->heights = $heights;
    }

    public function definition(string $machine, string $version): MachineDefinition
    {
        return $this->registered[self::key($machine, $version)]
            ?? throw new LogicException('The pinned machine definition is not registered in this catalog.');
    }

    /**
     * The deepest chain of child levels below this registered definition.
     */
    public function childDepth(MachineDefinition $definition): int
    {
        $this->registeredFor($definition);

        return $this->heights[self::key($definition->machine, $definition->version)];
    }

    /**
     * Resolve a slot of an exactly registered parent definition to its registered child definition.
     */
    public function child(MachineDefinition $parent, string $slot): MachineDefinition
    {
        $declared = $this->registeredFor($parent)
            ->slot($slot);

        return $this->definition($declared->childMachine, $declared->childDefinitionVersion);
    }

    /**
     * Pure catalog compatibility: both profiles must be bound to the exact registered definitions.
     *
     * This adds the child's definition fingerprint to MachineDefinition::assertBinding(). It is not
     * host authorization, native admission or outcome evidence.
     */
    public function assertBinding(PolicyProfile $parent, BindingIdentity $binding, PolicyProfile $child): void
    {
        $definition = $parent->definition
            ?? throw new LogicException('A catalog binding requires a definition-bound parent profile.');
        $expected = $this->child($definition, $binding->slot);
        if ($child->definition?->fingerprint() !== $expected->fingerprint()) {
            throw new LogicException('The child profile does not use the registered child machine definition.');
        }
        $definition->assertBinding($parent, $binding, $child);
    }

    private function registeredFor(MachineDefinition $definition): MachineDefinition
    {
        $registered = $this->definition($definition->machine, $definition->version);
        if ($registered->fingerprint() !== $definition->fingerprint()) {
            throw new LogicException('The machine definition differs from its registered catalog definition.');
        }
        return $registered;
    }

    /**
     * Depth-first analysis. Each definition is expanded once; a reused subtree is checked against
     * the current ancestors through the machine identities it can reach.
     *
     * @param array<string, MachineDefinition> $registered
     * @param non-empty-list<string> $path Display labels from the root to this node.
     * @param array<string, true> $ancestors Machine identities above this node.
     * @param array<string, array{machines: array<string, true>, height: int}> $analysis
     */
    private static function visit(
        array $registered,
        MachineDefinition $node,
        array $path,
        array $ancestors,
        array &$analysis,
    ): void {
        $key = self::key($node->machine, $node->version);
        if (isset($analysis[$key])) {
            foreach (array_keys($analysis[$key]['machines']) as $machine) {
                if (isset($ancestors[(string) $machine])) {
                    throw new InvalidMachineCatalog(
                        InvalidMachineCatalog::CHILD_BINDING_CYCLE,
                        [...$path, ...self::route($registered, $analysis, $node, (string) $machine)]
                    );
                }
            }
            return;
        }
        $lineage = $ancestors + [
            $node->machine => true,
        ];
        $machines = [
            $node->machine => true,
        ];
        $height = 0;
        foreach ($node->slots as $slot) {
            $label = self::label($slot);
            if (isset($lineage[$slot->childMachine])) {
                throw new InvalidMachineCatalog(InvalidMachineCatalog::CHILD_BINDING_CYCLE, [...$path, $label]);
            }
            $childKey = self::key($slot->childMachine, $slot->childDefinitionVersion);
            $child = $registered[$childKey]
                ?? throw new InvalidMachineCatalog(InvalidMachineCatalog::UNREGISTERED_CHILD, [...$path, $label]);
            self::visit($registered, $child, [...$path, $label], $lineage, $analysis);
            $machines += $analysis[$childKey]['machines'];
            $height = max($height, $analysis[$childKey]['height'] + 1);
        }
        $analysis[$key] = [
            'machines' => $machines,
            'height' => $height,
        ];
    }

    /**
     * A concrete slot route from an analysed definition down to a reachable machine identity.
     *
     * @param array<string, MachineDefinition> $registered
     * @param array<string, array{machines: array<string, true>, height: int}> $analysis
     * @return list<string>
     */
    private static function route(array $registered, array $analysis, MachineDefinition $node, string $machine): array
    {
        $route = [];
        while ($node->machine !== $machine) {
            foreach ($node->slots as $slot) {
                $childKey = self::key($slot->childMachine, $slot->childDefinitionVersion);
                if (isset($analysis[$childKey]['machines'][$machine])) {
                    $route[] = self::label($slot);
                    $node = $registered[$childKey];
                    continue 2;
                }
            }
            throw new LogicException('The analysed machine catalog route is unavailable.');
        }
        return $route;
    }

    private static function label(ChildSlot $slot): string
    {
        return $slot->key . ':' . self::id($slot->childMachine, $slot->childDefinitionVersion);
    }

    /**
     * Unambiguous registry key; display labels may collide when identities contain separators.
     */
    private static function key(string $machine, string $version): string
    {
        return json_encode([$machine, $version], JSON_THROW_ON_ERROR);
    }

    private static function id(string $machine, string $version): string
    {
        return $machine . '@' . $version;
    }
}
